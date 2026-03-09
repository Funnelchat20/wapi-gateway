<?php

namespace Funnelchat\WapiGateway\Http\Controllers;

use Funnelchat\WapiGateway\Http\Resources\Zapi\AdGroupResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\ChatResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\CheckPhoneResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\CommunityResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\ContactResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\CreateGroupResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\GroupResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\GroupsAnnouncementResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\GroupsResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\LogOutResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\MeResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\MessageResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\QrCodeResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\QueuedMessageResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\RebootResource;
use Funnelchat\WapiGateway\Http\Resources\Zapi\StatusResource;
use Funnelchat\WapiGateway\Interfaces\WhatsAppProviderControllerInterface;
use Funnelchat\WapiGateway\Jobs\ContactApp\ContactSynchronizationJob;
use Funnelchat\WapiGateway\Rules\MentionedRule;
use Funnelchat\WapiGateway\Traits\LogsDeviceRequests;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Request as RequestAlias;
use Symfony\Component\HttpFoundation\Response;
use function Sentry\captureException;

class FunapiController implements WhatsAppProviderControllerInterface
{
    use LogsDeviceRequests;

    private const YOU_ARE_NOT_CONNECTED = 'You are not connected.';
    private const YOU_ARE_ALREADY_CONNECTED = 'You are already connected.';
    private const PENDING_SUBSCRIPTION = 'To continue sending a message, you must subscribe to this instance again';
    private const QR_CODE_RETRIEVAL_ERROR_MESSAGE = 'Error retrieving QR code value.';
    private const INSTANCE_STATUSES = [self::YOU_ARE_ALREADY_CONNECTED, self::YOU_ARE_NOT_CONNECTED];
    private const GROUP_CREATED_PHONE_MISSING_MESSAGE = 'Group created, but phone is missing. Admins not added.';

    private string|null|object $uid;
    private string|array|null $token;

    protected function getProviderName(): string
    {
        return 'funapi';
    }

    public function __construct()
    {
        $this->uid = request()->route('uid');
        $this->token = request()->header('token');
    }

    private function buildUrl(string $action): string
    {
        $baseUrl = rtrim(config('funapi.base_url'), '/');
        return "{$baseUrl}/instances/{$this->uid}/token/{$this->token}/{$action}";
    }

    private function sendHttpRequest(string $method, array $params, string $action): \Illuminate\Http\Client\Response
    {
        $url = $this->buildUrl($action);
        $startTime = microtime(true);
        try {
            $response = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
                ->timeout(config('funapi.timeout', 29))
                ->{$method}($url, $params);
            $this->logRequest($action, $this->uid, [], $startTime, $response, $url, $params);
            return $response;
        } catch (ConnectionException $e) {
            return $this->handleHttpException($e, $url);
        }
    }

    public function status(Request $request): JsonResponse|StatusResource
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'status');
        if ($response->failed()) {
            $error = $response->json('error');
            if ($error === self::PENDING_SUBSCRIPTION) return new StatusResource(['accountStatus' => $this->getFormattedError(self::PENDING_SUBSCRIPTION)]);
            if ($error && !in_array($error, self::INSTANCE_STATUSES)) return response()->json(['error' => $this->getFormattedError($error)], Response::HTTP_CONFLICT);
        }
        $data = $response->json();
        $data['accountStatus'] = 'authenticated';
        if (($data['error'] ?? null) == self::YOU_ARE_NOT_CONNECTED) {
            $data['accountStatus'] = 'got qr code';
            $tmp = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'qr-code/image');
            if (isset($tmp['error'])) return response()->json(['error' => $tmp['error']], Response::HTTP_CONFLICT);
            if (!isset($tmp['value'])) return response()->json(['error' => self::QR_CODE_RETRIEVAL_ERROR_MESSAGE], Response::HTTP_CONFLICT);
            $data['qrCode'] = $tmp['value'];
        }
        return new StatusResource($data);
    }

    public function checkPhone(Request $request): CheckPhoneResource|JsonResponse
    {
        $validated = $request->validate(['phone' => ['string', 'required']]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, $validated, 'phone-exists/' . $validated['phone']);
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return new CheckPhoneResource($response->json());
    }

    public function qrCode(Request $request): QrCodeResource|JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'qr-code/image');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return new QrCodeResource($response->json());
    }

    public function logout(Request $request): JsonResponse|LogOutResource
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'disconnect');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return new LogOutResource($response->json());
    }

    public function reboot(Request $request): RebootResource|JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'restart');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return new RebootResource($response->json());
    }

    public function me(Request $request): MeResource|JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'device');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return new MeResource($response->json());
    }

    public function contact(Request $request): ContactResource|JsonResponse
    {
        $validated = $request->validate(['phone' => ['string', 'required']]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, $validated, 'contacts/' . $validated['phone']);
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('message') ?? $response->json('error'))], Response::HTTP_CONFLICT);
        return new ContactResource($response->json());
    }

    public function contacts(Request $request)
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes'],
            'sync' => ['bool', 'sometimes'],
            'device_id' => ['required_if:sync,1,true', 'int']
        ]);
        if (isset($validated['sync']) && ($validated['sync'] == 1 || $validated['sync'] == true)) {
            $page = $validated['page'] ?? 1;
            $pageSize = $validated['pageSize'] ?? 50;
            ContactSynchronizationJob::dispatch($page, $pageSize, $validated['device_id'])->onQueue('contact-api-' . app()->environment());
            return response()->noContent();
        }
        $validated['pageSize'] = $validated['pageSize'] ?? 50;
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, $validated, 'contacts');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('message') ?? $response->json('error'))], Response::HTTP_CONFLICT);
        return ContactResource::collection($response->collect());
    }

    public function sendMessage(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'message' => ['string', 'required'],
            'mentioned' => ['sometimes', new MentionedRule($request)],
            'delayMessage' => ['sometimes', 'int'],
            'delayTyping' => ['sometimes', 'int']
        ]);
        if (isset($validated['mentioned']) && !is_array($validated['mentioned'])) {
            $validated['mentioned'] = $this->getParticipants($validated['phone']);
        }
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'send-text');
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => $this->getFormattedError($response->json('error', 'An error has occurred'))
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function sendContact(Request $request): JsonResponse|MessageResource
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'contactName' => ['string', 'required'],
            'contactPhone' => ['string', 'required'],
            'mentioned' => ['sometimes', new MentionedRule($request)],
            'delayMessage' => ['sometimes', 'int']
        ]);
        if (isset($validated['mentioned']) && !is_array($validated['mentioned'])) {
            $validated['mentioned'] = $this->getParticipants($validated['phone']);
        }
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'send-contact');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return new MessageResource($response->json());
    }

    public function sendFile(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'caption' => ['string', 'nullable'],
            'fileUrl' => ['url', 'required'],
            'fileName' => ['string', 'nullable'],
            'mentioned' => ['sometimes', new MentionedRule($request)],
            'delayMessage' => ['sometimes', 'int'],
            'delayTyping' => ['sometimes', 'int']
        ]);
        $ext = strtolower(pathinfo($validated['fileUrl'], PATHINFO_EXTENSION));
        $action = $this->getAction($ext);
        if ($action === 'invalid-file') return response()->json(['error' => 'Invalid file extension'], Response::HTTP_CONFLICT);
        if ($action == 'send-document') $action = $action . '/' . $ext;
        $fileAttribute = $this->getFileAttribute($ext);
        $params = ['phone' => $validated['phone'], $fileAttribute => $validated['fileUrl']];
        if (isset($validated['fileName'])) $params['fileName'] = $validated['fileName'];
        if (isset($validated['caption'])) $params['caption'] = $validated['caption'];
        if (isset($validated['mentioned'])) {
            if ($validated['mentioned'] === true) {
                $params['mentioned'] = $this->getParticipants($validated['phone']);
            } elseif (is_array($validated['mentioned'])) {
                $params['mentioned'] = $validated['mentioned'];
            }
        }
        $params['async'] = true;
        $params['delayMessage'] = $validated['delayMessage'] ?? 0;
        $params['delayTyping'] = $validated['delayTyping'] ?? 0;
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $params, $action);
        if ($response->failed() || $response->json('error')) return response()->json([
            'error' => $this->getFormattedError($response->json('error', 'An error has occurred'))
        ], Response::HTTP_CONFLICT);
        return new MessageResource($response->json());
    }

    public function sendButtonLink(Request $request): MessageResource|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'message' => ['required', 'string'],
            'url' => ['required', 'url'],
            'label' => ['required', 'string'],
            'mentioned' => ['sometimes', new MentionedRule($request)],
            'delayMessage' => ['sometimes', 'int']
        ]);
        $params = [
            'phone' => $validated['phone'],
            'message' => $validated['message'],
            'buttonActions' => [
                [
                    'type' => 'URL',
                    'url' => $validated['url'],
                    'label' => $validated['label']
                ]
            ]
        ];
        if (isset($validated['mentioned'])) {
            if ($validated['mentioned'] === true) {
                $params['mentioned'] = $this->getParticipants($validated['phone']);
            } elseif (is_array($validated['mentioned'])) {
                $params['mentioned'] = $validated['mentioned'];
            }
        }
        if (isset($validated['delayMessage'])) $params['delayMessage'] = $validated['delayMessage'];
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $params, 'send-button-actions');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
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
            'mentioned' => ['sometimes', new MentionedRule($request)],
            'delayMessage' => ['sometimes', 'int']
        ]);
        if (isset($validated['mentioned']) && !is_array($validated['mentioned'])) {
            $validated['mentioned'] = $this->getParticipants($validated['phone']);
        }
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'send-poll');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
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
            'mentioned' => ['sometimes', new MentionedRule($request)],
            'delayMessage' => ['sometimes', 'int']
        ]);
        $params = [
            'phone' => $validated['phone'],
            'message' => $validated['message'],
            'buttonList' => [
                'buttons' => $validated['buttons']
            ],
        ];
        if (isset($validated['mentioned'])) {
            if ($validated['mentioned'] === true) {
                $params['mentioned'] = $this->getParticipants($validated['phone']);
            } elseif (is_array($validated['mentioned'])) {
                $params['mentioned'] = $validated['mentioned'];
            }
        }
        if (isset($validated['delayMessage'])) $params['delayMessage'] = $validated['delayMessage'];
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $params, 'send-button-list');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return new MessageResource($response->json());
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
        $params = [
            'phone' => $validated['phone'],
            'message' => $validated['message'],
            'optionList' => [
                'options' => $validated['optionList'],
                'buttonLabel' => $validated['buttonLabel']
            ],
        ];
        if (isset($validated['delayMessage'])) $params['delayMessage'] = $validated['delayMessage'];
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $params, 'send-option-list');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
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
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'send-event');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
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
            'mentioned' => ['sometimes', new MentionedRule($request)]
        ]);
        if (isset($validated['mentioned']) && !is_array($validated['mentioned'])) {
            $validated['mentioned'] = $this->getParticipants($validated['phone']);
        }
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'send-link');
        if ($response->failed() || $response->json('error')) return response()->json([
            'error' => $this->getFormattedError($response->json('error', 'An error has occurred'))
        ], Response::HTTP_CONFLICT);
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
            'mentioned' => ['sometimes', new MentionedRule($request)],
            'delayMessage' => ['sometimes', 'int'],
            'delayTyping' => ['sometimes', 'int']
        ]);
        $params = [
            'phone' => $validated['phone'],
            'latitude' => $validated['lat'],
            'longitude' => $validated['lng'],
        ];
        if (isset($validated['name'])) $params['name'] = $validated['name'];
        if (isset($validated['address'])) $params['address'] = $validated['address'];
        if (isset($validated['mentioned'])) {
            if ($validated['mentioned'] === true) {
                $params['mentioned'] = $this->getParticipants($validated['phone']);
            } elseif (is_array($validated['mentioned'])) {
                $params['mentioned'] = $validated['mentioned'];
            }
        }
        if (isset($validated['delayMessage'])) $params['delayMessage'] = $validated['delayMessage'];
        if (isset($validated['delayTyping'])) $params['delayTyping'] = $validated['delayTyping'];
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $params, 'send-location');
        if ($response->failed() || $response->json('error')) {
            return response()->json([
                'error' => $this->getFormattedError($response->json('error', 'An error has occurred'))
            ], Response::HTTP_CONFLICT);
        }
        return new MessageResource($response->json());
    }

    public function groups(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes']
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [
            'page' => $validated['page'] ?? 1,
            'pageSize' => $validated['pageSize'] ?? 299
        ], 'groups');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        $filteredGroups = $response->collect()
            ->filter(fn($group) => array_key_exists('communityId', $group) && empty($group['communityId']))
            ->unique('phone');
        return GroupsResource::collection($filteredGroups);
    }

    public function adGroups(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes']
        ]);
        $validated['pageSize'] = $validated['pageSize'] ?? 999;
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, $validated, 'groups');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        $filteredCommunity = $response->collect()
            ->filter(fn($group) => array_key_exists('communityId', $group) && !empty($group['communityId']));
        return GroupsAnnouncementResource::collection($filteredCommunity);
    }

    public function group(Request $request): JsonResponse|GroupResource
    {
        $validated = $request->validate(['id' => ['string', 'required']]);
        $id = $validated['id'] . '-group';
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, $validated, 'group-metadata/' . $id);
        if ($response->failed() || $response->json('error') || $response->json('success') === false) {
            return response()->json([
                'error' => $this->getFormattedError($response->json('error', $response->json('message', 'An error has occurred')))
            ], Response::HTTP_CONFLICT);
        }
        $data = $response->json();
        $data['image'] = $this->sendHttpRequest(RequestAlias::METHOD_GET, $validated, 'chats/' . $id)->json('profileThumbnail', '');
        return new GroupResource($data);
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
                    ? $fail($attribute . ' elements are not contained in phones.')
                    : null
            ]
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'groupName' => $validated['name'],
            'phones' => $validated['participants'],
            'autoInvite' => true
        ], 'create-group');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        $data = $response->json();
        if (!isset($data['phone'])) {
            return response()->json(['error' => self::GROUP_CREATED_PHONE_MISSING_MESSAGE], Response::HTTP_CONFLICT);
        }
        if (isset($validated['admins'])) {
            $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
                'groupId' => $data['phone'],
                'phones' => $validated['admins']
            ], 'add-admin');
            if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        }
        if (isset($validated['photo'])) {
            $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
                'groupId' => $data['phone'],
                'groupPhoto' => $validated['photo']
            ], 'update-group-photo');
            if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        }
        return new CreateGroupResource($data);
    }

    public function updateGroupName(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'name' => ['string', 'required'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'groupId' => $validated['id'],
            'groupName' => $validated['name']
        ], 'update-group-name');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function updateGroupDescription(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'description' => ['string', 'required'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'groupId' => $validated['id'] . '-group',
            'groupDescription' => $validated['description']
        ], 'update-group-description');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function updateGroupSettings(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'admin_only_message' => ['bool', 'required'],
            'admin_only_settings' => ['bool', 'required'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'phone' => $validated['id'] . '-group',
            'adminOnlyMessage' => $validated['admin_only_message'],
            'adminOnlySettings' => $validated['admin_only_settings'],
        ], 'update-group-settings');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function updateGroupPhoto(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'photo' => ['url', 'required'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'groupId' => $validated['id'],
            'groupPhoto' => $validated['photo']
        ], 'update-group-photo');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function addParticipants(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'phones' => ['array', 'required']
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'autoInvite' => 'true',
            'groupId' => $validated['id'],
            'phones' => $validated['phones']
        ], 'add-participant');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function addAdmins(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'phones' => ['array', 'required']
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'groupId' => $validated['id'],
            'phones' => $validated['phones']
        ], 'add-admin');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function removeParticipants(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'phones' => ['array', 'required']
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'groupId' => $validated['id'],
            'phones' => $validated['phones']
        ], 'remove-participant');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function removeAdmins(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'phones' => ['array', 'required']
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'groupId' => $validated['id'],
            'phones' => $validated['phones']
        ], 'remove-admin');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function leaveGroup(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required']
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'groupId' => $validated['id'] . '-group'
        ], 'leave-group');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function community(Request $request): AdGroupResource|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['string', 'required'],
            'description' => ['string', 'sometimes'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'communities');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        $settingsResponse = $this->sendHttpRequest(RequestAlias::METHOD_POST, [
            'communityId' => $response->json('id'),
            'whoCanAddNewGroups' => 'admins',
        ], 'communities/settings');
        $data = $response->json();
        if ($settingsResponse->failed() || $settingsResponse->json('error')) {
            logger()->warning('wapi-gateway.funapi.community.settings_failed', [
                'instance_uid' => $this->uid,
                'community_id' => $response->json('id'),
                'error' => $settingsResponse->json('error'),
            ]);
            $data['settings_warning'] = 'Community created but settings update failed';
        }
        return new AdGroupResource($data);
    }

    public function communities(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [
            'page' => $validated['page'] ?? 1,
            'pageSize' => $validated['pageSize'] ?? 10
        ], 'communities');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return CommunityResource::collection($response->json());
    }

    public function communitiesMetadata(Request $request): CommunityResource|JsonResponse
    {
        $validated = $request->validate(['id' => ['string', 'required']]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'communities-metadata/' . $validated['id']);
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return new CommunityResource($response->json());
    }

    public function chats(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, $validated, 'chats');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return ChatResource::collection($response->json());
    }

    public function deleteChat(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate(['phone' => ['string', 'sometimes']]);
        $validated['action'] = 'delete';
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'modify-chat');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function deleteMessage(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'messageId' => ['string', 'required'],
            'phone' => ['string', 'required'],
            'owner' => ['bool', 'required']
        ]);
        $body = http_build_query(['messageId' => $validated['messageId'], 'phone' => $validated['phone']]) . '&owner=true';
        $url = $this->buildUrl('messages') . '?' . $body;
        $startTime = microtime(true);
        try {
            $response = Http::withHeaders(['Client-Token' => config('funapi.client_token')])->timeout(config('funapi.timeout', 29))->delete($url);
            $this->logRequest('deleteMessage', $this->uid, [], $startTime, $response, $url);
        } catch (ConnectionException $e) {
            $this->logRequest('deleteMessage', $this->uid, ['error' => $e->getMessage()], $startTime, null, $url);
            return response()->json(['error' => 'Connection error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function groupInvitationMetadata(Request $request)
    {
        $validated = $request->validate(['url' => ['url', 'required']]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, $validated, 'group-invitation-metadata');
        if ($response->failed() || $response->json('error')) {
            return response()->json(['error' => $this->getFormattedError($response->json('message'))], Response::HTTP_CONFLICT);
        }
        return $response->json();
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
        $privateMessages = $validated['privateMessages'] ?? true;
        $urlWebhook = config('funapi.webhook_base_url', config('app.url')) . '/webhooks/funapi/received?userId=' . $validated['userId'] . '&deviceId=' . $validated['deviceId'];
        if ($privateMessages === false) {
            $urlWebhook .= '&privateMessages=false';
        }
        $response = $this->sendHttpRequest(RequestAlias::METHOD_PUT, ['value' => $urlWebhook], 'update-webhook-received');
        if ($response->failed() || $response->json('error')) return response()->json([
            'error' => $response->json('error')
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
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
        $privateMessages = $validated['privateMessages'] ?? true;
        $urlWebhook = config('funapi.webhook_base_url', config('app.url')) . '/webhooks/funapi/received-and-delivery?userId=' . $validated['userId'] . '&deviceId=' . $validated['deviceId'];
        if ($privateMessages === false) {
            $urlWebhook .= '&privateMessages=false';
        }
        $response = $this->sendHttpRequest(RequestAlias::METHOD_PUT, ['value' => $urlWebhook], 'update-webhook-received-delivery');
        if ($response->failed() || $response->json('error')) return response()->json([
            'error' => $response->json('error')
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
        return response()->noContent();
    }

    public function showMessagesQueue(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['int', 'sometimes'],
            'pageSize' => ['int', 'sometimes']
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [
            'page' => $validated['page'] ?? 1,
            'pageSize' => $validated['pageSize'] ?? 499
        ], 'queue');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return QueuedMessageResource::collection($response->json());
    }

    public function getQueueCount(): JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'queue/count');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->json(['count' => $response->json('count')]);
    }

    public function deleteMessagesQueue(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'messageQueueUid' => 'required|string'
        ]);
        $url = $this->buildUrl('queue/' . $validated['messageQueueUid']);
        try {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'client-token' => config('funapi.client_token'),
            ])->delete($url);
        } catch (ConnectionException $e) {
            captureException($e);
            return response()->json(['error' => 'Connection error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function clearQueue(): \Illuminate\Http\Response|JsonResponse
    {
        $url = $this->buildUrl('queue');
        try {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'client-token' => config('funapi.client_token'),
            ])->delete($url);
        } catch (ConnectionException $e) {
            captureException($e);
            return response()->json(['error' => 'Connection error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    // --- Newsletter/Channel endpoints ---

    public function createNewsletter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['string', 'required'],
            'description' => ['string', 'sometimes'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'create-newsletter');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->json($response->json());
    }

    public function deleteNewsletter(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate(['id' => ['string', 'required']]);
        $url = $this->buildUrl('delete-newsletter');
        $startTime = microtime(true);
        try {
            $response = Http::withHeaders(['Client-Token' => config('funapi.client_token')])
                ->timeout(config('funapi.timeout', 29))
                ->delete($url, $validated);
            $this->logRequest('deleteNewsletter', $this->uid, [], $startTime, $response, $url, $validated);
        } catch (ConnectionException $e) {
            $this->logRequest('deleteNewsletter', $this->uid, ['error' => $e->getMessage()], $startTime, null, $url, $validated);
            return response()->json(['error' => 'Connection error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function newsletters(Request $request): JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'newsletter');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->json($response->json());
    }

    public function newsletterMetadata(Request $request, string $newsletterId): JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'newsletter/metadata/' . $newsletterId);
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->json($response->json());
    }

    public function updateNewsletterName(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'name' => ['string', 'required'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'update-newsletter-name');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function updateNewsletterDescription(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'description' => ['string', 'required'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'update-newsletter-description');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    public function updateNewsletterPicture(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'id' => ['string', 'required'],
            'pictureUrl' => ['url', 'required'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'update-newsletter-picture');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    // --- Group extra endpoints ---

    public function groupInvitationLink(Request $request, string $groupId): JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, [], 'group-invitation-link/' . $groupId);
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->json($response->json());
    }

    public function lightGroupMetadata(Request $request, string $groupId): JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'light-group-metadata/' . $groupId);
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->json($response->json());
    }

    public function groupMetadata(Request $request, string $groupId): JsonResponse
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'group-metadata/' . $groupId);
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->json($response->json());
    }

    // --- Pin message ---

    public function pinMessage(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['string', 'required'],
            'pin' => ['boolean', 'required'],
        ]);
        $response = $this->sendHttpRequest(RequestAlias::METHOD_POST, $validated, 'pin-message');
        if ($response->failed() || $response->json('error')) return response()->json(['error' => $this->getFormattedError($response->json('error'))], Response::HTTP_CONFLICT);
        return response()->noContent();
    }

    // --- Private helpers ---

    private function getAction(string $ext): string
    {
        $actions = [
            'jpg' => 'send-image',
            'jpeg' => 'send-image',
            'png' => 'send-image',
            'pdf' => 'send-document',
            'doc' => 'send-document',
            'docx' => 'send-document',
            'aac' => 'send-audio',
            'oga' => 'send-audio',
            'ogg' => 'send-audio',
            'mp3' => 'send-audio',
            'm4a' => 'send-audio',
            'opus' => 'send-audio',
            'wav' => 'send-audio',
            'gif' => 'send-video',
            'mp4' => 'send-video',
            'mov' => 'send-video',
            'svg' => 'send-sticker',
            'webp' => 'send-sticker'
        ];
        if (!in_array($ext, array_keys($actions))) return 'invalid-file';
        return $actions[$ext];
    }

    private function getFileAttribute(string $ext): string
    {
        return [
            'jpg' => 'image',
            'jpeg' => 'image',
            'png' => 'image',
            'pdf' => 'document',
            'doc' => 'document',
            'docx' => 'document',
            'aac' => 'audio',
            'oga' => 'audio',
            'ogg' => 'audio',
            'mp3' => 'audio',
            'm4a' => 'audio',
            'opus' => 'audio',
            'wav' => 'audio',
            'gif' => 'video',
            'mp4' => 'video',
            'mov' => 'video',
            'svg' => 'sticker',
            'webp' => 'sticker',
        ][$ext];
    }

    private function getFormattedError(?string $error): string
    {
        if (!$error) return 'unknown_error';
        return match ($error) {
            default => $error,
            'Instance not found' => 'instance_not_found',
            'To continue sending a message, you must subscribe to this instance again' => 'pending_subscription',
            'You need to be connected with whatsapp' => 'disconnected_device',
            'Whatsapp not connected' => 'not_connected',
            'Whatsapp did not respond' => 'not_respond',
            'Instance not initialize' => 'not_initialize',
            'GROUP_ERROR_PHONE', 'GROUP_FORBIDDEN' => 'group_forbidden',
            'Phone not exists', 'Phone is wrong', 'Invalid phone' => 'phone_not_exists',
        };
    }

    private function getParticipants(string $phone): array
    {
        $response = $this->sendHttpRequest(RequestAlias::METHOD_GET, [], 'group-metadata/' . $phone);
        if ($response->failed() || $response->json('error') || $response->json('success') === false) {
            logger()->warning('wapi-gateway.funapi.getParticipants.failed', [
                'instance_uid' => $this->uid,
                'phone' => $phone,
                'error' => $response->json('error'),
            ]);
            return [];
        }
        $participants = $response->json('participants');
        $filteredParticipants = array_values(array_filter($participants, fn($participant) => !$participant['isSuperAdmin']));
        return array_map(fn($participant) => $participant['phone'], $filteredParticipants);
    }

    private function handleHttpException(\Throwable $e, string $url): \Illuminate\Http\Client\Response
    {
        $errorMessage = $this->getFormattedError($e->getMessage());
        logger()->error('deviceUid #' . $this->uid . ' FunapiController sendHttpRequest ' . get_class($e) . ' error', [
            'error' => $errorMessage,
            'deviceId' => $this->uid,
            'url' => $url
        ]);
        $statusCode = $e instanceof RequestException ? Response::HTTP_BAD_GATEWAY : Response::HTTP_INTERNAL_SERVER_ERROR;
        $psrResponse = new Psr7Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => $errorMessage,
                'success' => false
            ])
        );
        return new \Illuminate\Http\Client\Response($psrResponse);
    }
}
