<?php

namespace Funnelchat\WapiGateway\Http\Controllers;

use Funnelchat\WapiGateway\Http\Resources\Uazapi\AdGroupResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\ChatResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\CheckPhoneResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\CommunityResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\ContactResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\CreateGroupResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\GroupResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\GroupsResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\LogOutResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\MeResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\MessageResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\QrCodeResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\QueuedMessageResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\RebootResource;
use Funnelchat\WapiGateway\Http\Resources\Uazapi\StatusResource;
use Funnelchat\WapiGateway\Interfaces\WhatsAppProviderControllerInterface;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Request as RequestAlias;
use Symfony\Component\HttpFoundation\Response;
use function Sentry\captureException;

class UazapiController implements WhatsAppProviderControllerInterface
{
    private const DISCONNECTED = 'disconnected';
    private const CONNECTING = 'connecting';
    private const CONNECTED = 'connected';
    private const INSTANCE_STATUSES = [self::DISCONNECTED, self::CONNECTING, self::CONNECTED];
    private const QR_CODE_RETRIEVAL_ERROR_MESSAGE = 'Error retrieving QR code or pair code.';
    private string|null|object $uid;
    private string|array|null $token;
    private string $baseUrl;

    public function __construct()
    {
        $this->uid = request()->route('uid');
        $this->token = request()->header('token');
        $this->baseUrl = config('uazapi.base_url');
    }

    private function sendHttpRequest(string $method, array $params, string $endpoint): \Illuminate\Http\Client\Response
    {
        $url = $this->baseUrl . $endpoint;
        $timeout = config('uazapi.timeout', 30);
        info('deviceUid #' . $this->uid . ' UazapiController::sendHttpRequest starting', [
            'method' => $method,
            'url' => $url,
            'timeout' => $timeout,
            'has_token' => !empty($this->token),
            'token_length' => is_string($this->token) ? strlen($this->token) : null,
        ]);
        try {
            $http = Http::withHeaders(['token' => $this->token,])->timeout($timeout);
            $shouldUseRawBody = empty($params) && strtoupper($method) !== 'GET';
            $response = match ($method) {
                'GET' => $http->get($url, $params),
                'PUT' => $shouldUseRawBody
                    ? $http->withBody('{}', 'application/json')->put($url)
                    : $http->asJson()->put($url, $params),
                'PATCH' => $shouldUseRawBody
                    ? $http->withBody('{}', 'application/json')->patch($url)
                    : $http->asJson()->patch($url, $params),
                'DELETE' => $shouldUseRawBody
                    ? $http->withBody('{}', 'application/json')->delete($url)
                    : $http->asJson()->delete($url, $params),
                default => $shouldUseRawBody
                    ? $http->withBody('{}', 'application/json')->post($url)
                    : $http->asJson()->post($url, $params),
            };
            info('deviceUid #' . $this->uid . ' UazapiController::sendHttpRequest', [
                'method' => $method,
                'url' => $url,
                'params' => $params,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);
            return $response;
        } catch (RequestException|\Throwable $e) {
            return $this->handleHttpException($e, $url);
        }
    }

    private function handleHttpException(\Throwable $e, string $url): \Illuminate\Http\Client\Response
    {
        captureException($e);
        $isTimeout = str_contains($e->getMessage(), 'timeout') ||
            str_contains($e->getMessage(), 'timed out') ||
            $e instanceof \GuzzleHttp\Exception\ConnectException;
        $statusCode = $isTimeout ? Response::HTTP_GATEWAY_TIMEOUT : Response::HTTP_SERVICE_UNAVAILABLE;
        $errorType = $isTimeout ? 'Request timeout' : config('uazapi.errors.connection_error');
        logger()->error('deviceUid #' . $this->uid . ' UazapiController HTTP Exception', [
            'url' => $url,
            'error' => $e->getMessage(),
            'error_type' => $errorType,
            'is_timeout' => $isTimeout,
            'trace' => $e->getTraceAsString(),
        ]);
        return new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(
                $statusCode,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'error' => $errorType,
                    'message' => $e->getMessage(),
                ])
            )
        );
    }

    private static function getFormattedError(?string $error): string
    {
        if ($error === null) {
            return 'Unknown error';
        }
        return ucfirst(str_replace(['_', '-'], ' ', strtolower($error)));
    }

    public function status(Request $request): JsonResponse|StatusResource
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], config('uazapi.endpoints.status'));
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error');
            if ($errorMessage) {
                return response()->json(['error' => self::getFormattedError($errorMessage)], Response::HTTP_CONFLICT);
            }
        }
        $data = $response->json();
        if (isset($data['error']) && !in_array($data['error'], self::INSTANCE_STATUSES)) {
            return response()->json(['error' => self::getFormattedError($data['error'])], Response::HTTP_CONFLICT);
        }
        $actualStatus = $data['instance']['status'] ?? self::DISCONNECTED;
        $data['accountStatus'] = match ($actualStatus) {
            self::CONNECTED => 'authenticated',
            self::CONNECTING => 'connecting',
            self::DISCONNECTED => 'disconnected',
            default => 'unknown_status: ' . $actualStatus,
        };
        if (in_array($actualStatus, [self::DISCONNECTED, self::CONNECTING])) {
            $qrResponse = $this->sendHttpRequest(RequestAlias::METHOD_POST, [], config('uazapi.endpoints.qr_code'));
            $qrData = $qrResponse->json();
            $qrcode = $qrData['instance']['qrcode'] ?? $qrData['qrcode'] ?? null;
            if (!empty($qrcode)) {
                $data['accountStatus'] = 'got qr code';
                $data['qrCode'] = $qrcode;
            }
        }
        return new StatusResource($data);
    }

    public function checkPhone(Request $request): CheckPhoneResource|JsonResponse
    {
        $validated = $request->validate(['phone' => ['string', 'required']]);
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_GET,
            ['phone' => $validated['phone']],
            '/contact/checkPhone/' . $validated['phone']
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Phone check failed';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new CheckPhoneResource($response->json());
    }

    public function qrCode(Request $request): QrCodeResource|JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [], config('uazapi.endpoints.qr_code'));
        $data = $response->json();
        $qrcode = $data['instance']['qrcode'] ?? $data['qrcode'] ?? null;
        $paircode = $data['instance']['paircode'] ?? $data['paircode'] ?? null;
        if (empty($qrcode) && empty($paircode)) {
            $errorMessage = $data['error'] ?? $data['message'] ?? self::QR_CODE_RETRIEVAL_ERROR_MESSAGE;
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        return new QrCodeResource([
            'value' => $qrcode,
            'qrcode' => $qrcode,
        ]);
    }

    public function logout(Request $request): JsonResponse|LogOutResource
    {
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            [],
            config('uazapi.endpoints.disconnect')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error');
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new LogOutResource($response->json());
    }

    public function reboot(Request $request): RebootResource|JsonResponse
    {
        $disconnectResponse = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            [],
            config('uazapi.endpoints.disconnect')
        );
        if ($disconnectResponse->failed()) {
            return response()->json([
                'error' => self::getFormattedError($disconnectResponse->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        sleep(2);
        $connectResponse = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            [],
            config('uazapi.endpoints.connect')
        );
        if ($connectResponse->failed() || $connectResponse->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($connectResponse->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new RebootResource([
            'status' => 'restarted',
            'message' => 'Instance rebooted successfully',
        ]);
    }

    public function me(Request $request): MeResource|JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], config('uazapi.endpoints.status'));
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error');
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        $data = $response->json();
        $instanceData = $data['instance'] ?? [];
        return new MeResource([
            'phone' => $instanceData['owner'] ?? '',
            'profileName' => $instanceData['profileName'] ?? '',
            'profilePicUrl' => $instanceData['profilePicUrl'] ?? '',
            'isBusiness' => $instanceData['isBusiness'] ?? false,
            'name' => $instanceData['profileName'] ?? '',
            'imgUrl' => $instanceData['profilePicUrl'] ?? '',
        ]);
    }

    public function contact(Request $request): ContactResource|JsonResponse
    {
        $validated = $request->validate(['phone' => ['string', 'required']]);
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_GET,
            [],
            config('uazapi.endpoints.contacts')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('message') ?? $response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        $contacts = $response->json();
        $targetPhone = $validated['phone'];
        $contact = collect($contacts)->first(function ($contact) use ($targetPhone) {
            $jid = $contact['jid'] ?? '';
            return str_starts_with($jid, $targetPhone . '@') || str_starts_with($jid, $targetPhone . ':');
        });
        if (!$contact) {
            if (request()->has('debug')) {
                return response()->json([
                    'error' => 'Contact not found',
                    'debug' => [
                        'target_phone' => $targetPhone,
                        'total_contacts' => is_array($contacts) ? count($contacts) : 0,
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
            return response()->json([
                'error' => 'Contact not found',
            ], Response::HTTP_NOT_FOUND);
        }
        return new ContactResource($contact);
    }

    public function sendContact(Request $request): JsonResponse|MessageResource
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'contactName' => ['string', 'required'],
            'contactPhone' => ['string', 'required'],
            'mentioned' => ['sometimes'],
            'delayMessage' => ['sometimes', 'int']
        ]);
        $params = [
            'number' => $validated['phone'],
            'fullName' => $validated['contactName'],
            'phoneNumber' => $validated['contactPhone'],
        ];
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_contact')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function sendMessage(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'message' => ['string', 'required'],
            'mentioned' => ['sometimes'],
            'delayMessage' => ['sometimes', 'int'],
            'delayTyping' => ['sometimes', 'int']
        ]);
        $params = [
            'number' => $validated['phone'],
            'text' => $validated['message'],
        ];
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_message')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'An error has occurred';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function groupsDebug(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $url = $this->baseUrl . config('uazapi.endpoints.groups');
        info('DEBUG: groupsDebug starting', [
            'uid' => $this->uid,
            'url' => $url,
            'token_exists' => !empty($this->token),
            'baseUrl' => $this->baseUrl,
        ]);
        try {
            $response = Http::withHeaders([
                'token' => $this->token,
                'Accept' => 'application/json',
            ])->timeout(30)->get($url);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            info('DEBUG: groupsDebug completed', [
                'duration_ms' => $duration,
                'status' => $response->status(),
            ]);
            return response()->json([
                'debug_info' => [
                    'url' => $url,
                    'duration_ms' => $duration,
                    'status' => $response->status(),
                ],
                'response' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            logger()->error('DEBUG: groupsDebug exception', [
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Exception occurred',
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
            ], 500);
        }
    }

    public function groups(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes']
        ]);
        $startTime = microtime(true);
        $url = $this->baseUrl . config('uazapi.endpoints.groups');
        try {
            $response = Http::withHeaders([
                'token' => $this->token,
                'Accept' => 'application/json',
            ])->timeout(30)->get($url);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            info('deviceUid #' . $this->uid . ' groups() request completed', [
                'duration_ms' => $duration,
                'status' => $response->status(),
                'url' => $url,
            ]);
            if ($response->failed()) {
                $error = $response->json('error') ?? $response->json('message') ?? 'Unknown error';
                $statusCode = $response->status();
                logger()->error('deviceUid #' . $this->uid . ' groups() failed', [
                    'status' => $statusCode,
                    'error' => $error,
                    'duration_ms' => $duration,
                ]);
                return response()->json([
                    'error' => self::getFormattedError($error),
                ], $statusCode);
            }
            if ($response->json('error')) {
                return response()->json([
                    'error' => self::getFormattedError($response->json('error')),
                ], Response::HTTP_CONFLICT);
            }
            $data = $response->json();
            $groups = $data['groups'] ?? [];
            if (!is_array($groups)) {
                $groups = [];
            }
            $filteredGroups = collect($groups)
                ->filter(fn($group) => isset($group['id']) || isset($group['JID']))
                ->values();
            return GroupsResource::collection($filteredGroups);
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            captureException($e);
            logger()->error('deviceUid #' . $this->uid . ' groups() exception', [
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            return GroupsResource::collection([]);
        }
    }

    public function group(Request $request): GroupResource|JsonResponse
    {
        $validated = $request->validate(['id' => ['string', 'required']]);
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            ['groupjid' => $validated['id']],
            config('uazapi.endpoints.group')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new GroupResource($response->json());
    }

    public function createGroup(Request $request): CreateGroupResource|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['string', 'required'],
            'participants' => ['array', 'required'],
            'photo' => ['url', 'sometimes'],
            'admins' => [
                'array',
                'sometimes',
                fn($attribute, $value, $fail) => !empty(array_diff($value, $request->input('participants')))
                    ? $fail($attribute . ' elements are not contained in participants.')
                    : null
            ]
        ]);
        $instancePhone = $this->getInstancePhoneNumber();
        $participants = array_values(array_filter(
            $validated['participants'],
            fn($phone) => $phone !== $instancePhone
        ));
        $admins = [];
        if (isset($validated['admins'])) {
            $admins = array_values(array_filter(
                $validated['admins'],
                fn($phone) => $phone !== $instancePhone
            ));
        }
        $params = [
            'name' => $validated['name'],
            'participants' => $participants,
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.create_group')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Group creation failed';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        $data = $response->json();
        if (!empty($data['error'])) {
            return response()->json([
                'error' => self::getFormattedError($data['error']),
            ], Response::HTTP_CONFLICT);
        }
        $groupId = $data['group']['JID'] ?? $data['id'] ?? $data['phone'] ?? $data['groupId'] ?? null;
        if (!$groupId) {
            return response()->json([
                'error' => 'Group created but group ID is missing.',
            ], Response::HTTP_CONFLICT);
        }
        if (!empty($admins)) {
            $adminResponse = $this->sendHttpRequest(
                RequestAlias::METHOD_POST,
                [
                    'groupjid' => $groupId,
                    'action' => 'promote',
                    'participants' => $admins
                ],
                '/group/updateParticipants'
            );
            if ($adminResponse->failed()) {
                $errorMessage = $adminResponse->json('message') ?? $adminResponse->json('error') ?? 'Failed to promote admins';
                return response()->json([
                    'error' => self::getFormattedError($errorMessage),
                ], Response::HTTP_CONFLICT);
            }
            if ($adminResponse->json('error')) {
                return response()->json([
                    'error' => self::getFormattedError($adminResponse->json('error')),
                ], Response::HTTP_CONFLICT);
            }
        }
        if (!empty($validated['photo'])) {
            $photoResponse = $this->sendHttpRequest(
                RequestAlias::METHOD_POST,
                [
                    'groupjid' => $groupId,
                    'image' => $validated['photo']
                ],
                '/group/updateImage'
            );
            if ($photoResponse->failed()) {
                $errorMessage = $photoResponse->json('message') ?? $photoResponse->json('error') ?? 'Failed to update group photo';
                return response()->json([
                    'error' => self::getFormattedError($errorMessage),
                ], Response::HTTP_CONFLICT);
            }
            if ($photoResponse->json('error')) {
                return response()->json([
                    'error' => self::getFormattedError($photoResponse->json('error')),
                ], Response::HTTP_CONFLICT);
            }
        }
        return new CreateGroupResource($data);
    }

    public function sendOptionList(Request $request): JsonResponse|MessageResource
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'message' => ['string', 'required'],
            'buttonLabel' => ['string', 'required'],
            'optionList' => ['array', 'required'],
            'optionList.*.id' => ['required', 'string'],
            'optionList.*.title' => ['required', 'string'],
            'optionList.*.description' => ['sometimes', 'string'],
            'delayMessage' => ['sometimes', 'int']
        ]);
        $choices = [];
        $choices[] = '[Opciones]';
        foreach ($validated['optionList'] as $option) {
            $choice = $option['title'] . '|' . $option['id'];
            if (isset($option['description'])) {
                $choice .= '|' . $option['description'];
            }
            $choices[] = $choice;
        }
        $params = [
            'number' => $validated['phone'],
            'type' => 'list',
            'text' => $validated['message'],
            'choices' => $choices,
            'listButton' => $validated['buttonLabel']
        ];
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_list')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function updateGroupName(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'name' => ['string', 'required', 'min:1', 'max:25'],
        ]);
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $params = [
            'groupjid' => $groupjid,
            'name' => $validated['name'],
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.update_group_name')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function updateGroupDescription(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'description' => ['string', 'nullable', 'max:512'],
        ]);
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $params = [
            'groupjid' => $groupjid,
            'description' => $validated['description'] ?? '',
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.update_group_description')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function updateGroupSettings(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'admin_only_message' => ['bool', 'required'],
            'admin_only_settings' => ['bool', 'required'],
        ]);
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $errors = [];
        $announceParams = [
            'groupjid' => $groupjid,
            'announce' => $validated['admin_only_message'],
        ];
        $announceResponse = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $announceParams,
            config('uazapi.endpoints.update_group_announce')
        );
        if ($announceResponse->failed() || $announceResponse->json('error')) {
            $errors[] = 'Failed to update message permissions: ' . self::getFormattedError($announceResponse->json('error'));
        }
        $lockedParams = [
            'groupjid' => $groupjid,
            'locked' => $validated['admin_only_settings'],
        ];
        $lockedResponse = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $lockedParams,
            config('uazapi.endpoints.update_group_locked')
        );
        if ($lockedResponse->failed() || $lockedResponse->json('error')) {
            $errors[] = 'Failed to update settings permissions: ' . self::getFormattedError($lockedResponse->json('error'));
        }
        if (!empty($errors)) {
            return response()->json([
                'error' => implode('. ', $errors)
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function updateGroupPhoto(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'photo' => ['url', 'required'],
        ]);
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $params = [
            'groupjid' => $groupjid,
            'image' => $validated['photo'],
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.update_group_photo')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function addParticipants(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'phones' => ['array', 'required']
        ]);
        $instancePhone = $this->getInstancePhoneNumber();
        $phones = array_values(array_filter(
            $validated['phones'],
            fn($phone) => $phone !== $instancePhone
        ));
        if (empty($phones)) {
            return response()->noContent();
        }
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $params = [
            'groupjid' => $groupjid,
            'action' => 'add',
            'participants' => $phones
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            '/group/updateParticipants'
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to add participants';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function addAdmins(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'phones' => ['array', 'required']
        ]);
        $instancePhone = $this->getInstancePhoneNumber();
        $phones = array_values(array_filter(
            $validated['phones'],
            fn($phone) => $phone !== $instancePhone
        ));
        if (empty($phones)) {
            return response()->noContent();
        }
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $params = [
            'groupjid' => $groupjid,
            'action' => 'promote',
            'participants' => $phones
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            '/group/updateParticipants'
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to add admins';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function removeParticipants(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'phones' => ['array', 'required']
        ]);
        $instancePhone = $this->getInstancePhoneNumber();
        $phones = array_values(array_filter(
            $validated['phones'],
            fn($phone) => $phone !== $instancePhone
        ));
        if (empty($phones)) {
            return response()->noContent();
        }
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $params = [
            'groupjid' => $groupjid,
            'action' => 'remove',
            'participants' => $phones
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            '/group/updateParticipants'
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to remove participants';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function removeAdmins(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'phones' => ['array', 'required']
        ]);
        $instancePhone = $this->getInstancePhoneNumber();
        $phones = array_values(array_filter(
            $validated['phones'],
            fn($phone) => $phone !== $instancePhone
        ));
        if (empty($phones)) {
            return response()->noContent();
        }
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $params = [
            'groupjid' => $groupjid,
            'action' => 'demote',
            'participants' => $phones
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            '/group/updateParticipants'
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to remove admins';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function community(Request $request): AdGroupResource|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['string', 'required'],
            'description' => ['string', 'sometimes']
        ]);
        $params = [
            'name' => $validated['name']
        ];
        if (isset($validated['description'])) {
            $params['description'] = $validated['description'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.create_community')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to create community';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new AdGroupResource($response->json());
    }

    public function communities(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes']
        ]);
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_GET,
            [],
            config('uazapi.endpoints.communities')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to get communities';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        $data = $response->json();
        $groups = $data['groups'] ?? $data ?? [];
        $communities = collect($groups)
            ->filter(fn($group) => ($group['IsParent'] ?? false) === true)
            ->values();
        return CommunityResource::collection($communities);
    }

    public function communitiesMetadata(Request $request): CommunityResource|JsonResponse
    {
        $validated = $request->validate(['id' => ['string', 'required']]);
        $params = [
            'groupjid' => $validated['id']
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.community_metadata')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to get community metadata';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new CommunityResource($response->json());
    }

    public function chats(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes']
        ]);
        $filters = [];
        if (isset($validated['pageSize'])) {
            $filters['limit'] = $validated['pageSize'];
        }
        if (isset($validated['page']) && isset($validated['pageSize'])) {
            $filters['offset'] = ($validated['page'] - 1) * $validated['pageSize'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $filters,
            config('uazapi.endpoints.chats')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to get chats';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        $chats = $response->json() ?? [];
        return ChatResource::collection($chats);
    }

    public function sendFile(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'caption' => ['string', 'nullable'],
            'fileUrl' => ['url', 'required'],
            'fileName' => ['string', 'nullable'],
            'mentioned' => ['sometimes'],
            'delayMessage' => ['sometimes', 'int'],
            'delayTyping' => ['sometimes', 'int']
        ]);
        $ext = strtolower(pathinfo($validated['fileUrl'], PATHINFO_EXTENSION));
        $typeMapping = [
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
            'webp' => 'sticker', 'svg' => 'sticker',
            'pdf' => 'document', 'doc' => 'document', 'docx' => 'document',
            'mp3' => 'audio', 'ogg' => 'audio', 'aac' => 'audio', 'm4a' => 'audio', 'opus' => 'audio', 'oga' => 'audio', 'wav' => 'audio',
            'mp4' => 'video', 'mov' => 'video', 'avi' => 'video', 'wmv' => 'video'
        ];
        if (!isset($typeMapping[$ext])) {
            return response()->json(['error' => 'Invalid file extension'], Response::HTTP_CONFLICT);
        }
        $mediaType = $typeMapping[$ext];
        $params = [
            'number' => $validated['phone'],
            'type' => $mediaType,
            'file' => $validated['fileUrl'],
        ];
        if (isset($validated['caption'])) {
            $params['text'] = $validated['caption'];
        }
        if (isset($validated['fileName']) && $mediaType === 'document') {
            $params['docName'] = $validated['fileName'];
        }
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_document')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function sendButtonLink(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'message' => ['required', 'string'],
            'url' => ['required', 'url'],
            'label' => ['required', 'string'],
            'mentioned' => ['sometimes'],
            'delayMessage' => ['sometimes', 'int']
        ]);
        $params = [
            'number' => $validated['phone'],
            'type' => 'button',
            'text' => $validated['message'],
            'choices' => [
                $validated['label'] . '|' . $validated['url']
            ]
        ];
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_buttons')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function sendButtons(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'message' => ['required', 'string'],
            'buttons' => ['required', 'array', 'max:10'],
            'buttons.*.id' => ['required', 'string'],
            'buttons.*.label' => ['required', 'string'],
            'mentioned' => ['sometimes'],
            'delayMessage' => ['sometimes', 'int']
        ]);
        $params = [
            'number' => $validated['phone'],
            'type' => 'button',
            'text' => $validated['message'],
            'choices' => collect($validated['buttons'])->map(function ($button) {
                return $button['label'] . '|' . $button['id'];
            })->toArray()
        ];
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_buttons')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function sendPoll(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'message' => ['required', 'string'],
            'pollMaxOptions' => ['sometimes', 'int'],
            'poll' => ['required', 'array'],
            'poll.*.name' => ['required', 'string'],
            'mentioned' => ['sometimes'],
            'delayMessage' => ['sometimes', 'int']
        ]);
        $params = [
            'number' => $validated['phone'],
            'type' => 'poll',
            'text' => $validated['message'],
            'choices' => collect($validated['poll'])->pluck('name')->toArray(),
            'selectableCount' => $validated['pollMaxOptions'] ?? 1
        ];
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_poll')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function sendEvent(Request $request): JsonResponse|MessageResource
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\d+-group$/'],
            'event' => ['required', 'array'],
            'event.name' => ['required', 'string'],
            'event.description' => ['nullable', 'string'],
            'event.dateTime' => ['required', 'date_format:Y-m-d\TH:i:s.v\Z', 'after:now'],
            'event.location' => ['nullable', 'array'],
            'event.callLinkType' => ['nullable', 'string', 'in:voice,video'],
            'event.canceled' => ['sometimes', 'boolean']
        ]);
        $eventMessage = "📅 *{$validated['event']['name']}*\n\n";
        if (isset($validated['event']['description'])) {
            $eventMessage .= $validated['event']['description'] . "\n\n";
        }
        $eventMessage .= '🗓️ Fecha: ' . date('d/m/Y H:i', strtotime($validated['event']['dateTime']));
        if (isset($validated['event']['location'])) {
            $eventMessage .= "\n📍 Ubicación: " . ($validated['event']['location']['name'] ?? 'No especificada');
            if (isset($validated['event']['location']['address'])) {
                $eventMessage .= "\n   " . $validated['event']['location']['address'];
            }
        }
        if (isset($validated['event']['callLinkType'])) {
            $callType = $validated['event']['callLinkType'] === 'video' ? 'Videollamada' : 'Llamada de voz';
            $eventMessage .= "\n📞 Tipo: " . $callType;
        }
        $params = [
            'number' => $validated['phone'],
            'text' => $eventMessage
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_message')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function sendLink(Request $request): JsonResponse|MessageResource
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'message' => ['required', 'string'],
            'image' => ['sometimes', 'url'],
            'linkUrl' => ['required', 'url'],
            'title' => ['required', 'string'],
            'linkDescription' => ['required', 'string'],
            'delayMessage' => ['sometimes', 'int'],
            'delayTyping' => ['sometimes', 'int'],
            'mentioned' => ['sometimes']
        ]);
        $params = [
            'number' => $validated['phone'],
            'text' => $validated['message'] . ' ' . $validated['linkUrl'],
            'linkPreview' => true,
            'linkPreviewTitle' => $validated['title'],
            'linkPreviewDescription' => $validated['linkDescription'],
            'linkPreviewLarge' => true
        ];
        if (isset($validated['image'])) {
            $params['linkPreviewImage'] = $validated['image'];
        }
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_link')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function sendLocation(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'lat' => ['numeric', 'required'],
            'lng' => ['numeric', 'required'],
            'name' => ['string', 'nullable'],
            'address' => ['string', 'nullable'],
            'mentioned' => ['sometimes'],
            'delayMessage' => ['sometimes', 'int'],
            'delayTyping' => ['sometimes', 'int']
        ]);
        $params = [
            'number' => $validated['phone'],
            'latitude' => $validated['lat'],
            'longitude' => $validated['lng'],
        ];
        if (isset($validated['name'])) {
            $params['name'] = $validated['name'];
        }
        if (isset($validated['address'])) {
            $params['address'] = $validated['address'];
        }
        if (isset($validated['delayMessage'])) {
            $params['delay'] = $validated['delayMessage'];
        }
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.send_location')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function contacts(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes'],
            'sync' => ['bool', 'sometimes'],
            'device_id' => ['required_if:sync,1,true', 'int']
        ]);
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_GET,
            [],
            config('uazapi.endpoints.contacts')
        );
        if ($response->failed() || $response->json('error')) {
            $error = $response->json('error') ?? $response->json('message') ?? 'Unknown error';
            return response()->json([
                'error' => self::getFormattedError($error),
            ], Response::HTTP_CONFLICT);
        }
        $data = $response->json();
        if (!is_array($data)) {
            $data = [];
        }
        return ContactResource::collection($data);
    }

    public function adGroups(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes']
        ]);
        $startTime = microtime(true);
        $url = $this->baseUrl . config('uazapi.endpoints.groups');
        try {
            $response = Http::withHeaders([
                'token' => $this->token,
                'Accept' => 'application/json',
            ])->timeout(30)->get($url);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            info('deviceUid #' . $this->uid . ' adGroups() request completed', [
                'duration_ms' => $duration,
                'status' => $response->status(),
                'url' => $url,
            ]);
            if ($response->failed()) {
                $error = $response->json('error') ?? $response->json('message') ?? 'Unknown error';
                return response()->json([
                    'error' => self::getFormattedError($error),
                ], $response->status());
            }
            if ($response->json('error')) {
                return response()->json([
                    'error' => self::getFormattedError($response->json('error')),
                ], Response::HTTP_CONFLICT);
            }
            $data = $response->json();
            $groups = $data['groups'] ?? [];
            if (!is_array($groups)) {
                $groups = [];
            }
            $filteredGroups = collect($groups)
                ->filter(fn($group) => ($group['IsAnnounce'] ?? false) === true)
                ->values();
            return AdGroupResource::collection($filteredGroups);
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            captureException($e);
            logger()->error('deviceUid #' . $this->uid . ' adGroups() exception', [
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            return response()->json([
                'error' => 'Exception occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function leaveGroup(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required']
        ]);
        $groupjid = $validated['id'];
        if (!str_contains($groupjid, '@g.us')) {
            $groupjid .= '@g.us';
        }
        $params = [
            'groupjid' => $groupjid
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.leave_group')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to leave group';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function groupInvitationMetadata(Request $request): JsonResponse
    {
        $validated = $request->validate(['url' => ['url', 'required']]);
        $inviteCode = basename(parse_url($validated['url'], PHP_URL_PATH));
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_GET,
            [],
            config('uazapi.endpoints.group_invitation') . '/' . $inviteCode
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->json($response->json());
    }

    public function deleteChat(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required']
        ]);
        $params = [
            'number' => $validated['phone']
        ];
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            $params,
            config('uazapi.endpoints.delete_chat')
        );
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? 'Failed to delete chat';
            return response()->json([
                'error' => self::getFormattedError($errorMessage),
            ], Response::HTTP_CONFLICT);
        }
        if ($response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function deleteMessage(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'messageId' => ['string', 'required'],
            'phone' => ['string', 'required'],
            'owner' => ['bool', 'required']
        ]);
        $response = $this->sendHttpRequest(
            RequestAlias::METHOD_POST,
            [
                'id' => $validated['messageId']
            ],
            config('uazapi.endpoints.delete_message')
        );
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => self::getFormattedError($response->json('error')),
            ], Response::HTTP_CONFLICT);
        }
        return response()->noContent();
    }

    public function showMessagesQueue(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes']
        ]);
        return QueuedMessageResource::collection([]);
    }

    public function getQueueCount(): JsonResponse
    {
        return response()->json(['count' => 0]);
    }

    public function deleteMessagesQueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'messageQueueUid' => 'required|string'
        ]);
        return response()->json(['message' => 'Queue message deleted successfully']);
    }

    public function clearQueue(): JsonResponse
    {
        return response()->json(['message' => 'Queue cleared successfully']);
    }

    public function updateWebhookReceived(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'userId' => ['int', 'required'],
            'deviceId' => ['int', 'required'],
            'updateReceivedAndDeliveryWebhook' => ['sometimes', 'boolean'],
            'privateMessages' => ['sometimes', 'boolean']
        ]);
        $updateReceivedAndDeliveryWebhook = $validated['updateReceivedAndDeliveryWebhook'] ?? true;
        $webhookUrl = config('app.url') . '/webhooks/uazapi';
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'enabled' => true,
            'url' => $webhookUrl,
            'events' => ['messages', 'messages_update', 'connection'],
            'excludeMessages' => ['wasSentByApi'],
            'addUrlEvents' => true
        ], '/webhook');
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => $response->json('error')
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if ($updateReceivedAndDeliveryWebhook) {
            return $this->updateWebhookReceivedAndDelivery($request);
        }
        return response()->noContent();
    }

    public function updateWebhookReceivedAndDelivery(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'userId' => ['int', 'required'],
            'deviceId' => ['int', 'required'],
            'privateMessages' => ['sometimes', 'boolean']
        ]);
        return response()->noContent();
    }

    private function getInstancePhoneNumber(): ?string
    {
        try {
            $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], config('uazapi.endpoints.status'));
            if ($response->successful() && !$response->json('error')) {
                $data = $response->json();
                $instanceData = $data['instance'] ?? [];
                return $instanceData['owner'] ?? null;
            }
        } catch (\Throwable $e) {
            logger()->warning('Could not get instance phone number for filtering', [
                'error' => $e->getMessage(),
                'uid' => $this->uid
            ]);
        }
        return null;
    }
}
