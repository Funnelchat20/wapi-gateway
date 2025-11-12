<?php

namespace Funnelchat\WapiGateway\Http\Controllers;

use Funnelchat\WapiGateway\Interfaces\WhatsAppProviderInstanceInterface;
use Illuminate\Routing\Controller;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use function Sentry\captureException;

class UazapiInstanceController extends Controller implements WhatsAppProviderInstanceInterface
{
    private const SESSION_NAME = 'Funnelchat';
    private const UNKNOWN_ERROR_MESSAGE = 'Unknown error';
    private const INSTANCE_CREATION_ERROR_MESSAGE = 'Failed to create instance';

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate(['user_id' => 'required|int', 'device_id' => 'required|int']);
        $userId = $validated['user_id'];
        $deviceId = $validated['device_id'];
        $name = 'U-' . $userId . ' D-' . $deviceId;
        $baseUrl = config('app.url');
        $uazapiBaseUrl = config('uazapi.base_url');
        $createInstanceUrl = $uazapiBaseUrl . '/instance/init';
        $headers = ['admintoken' => config('uazapi.admin_token')];
        $timeout = config('uazapi.timeout', 120);
        $requestData = ['name' => $name];
        try {
            $response = Http::withHeaders($headers)->timeout($timeout)->post($createInstanceUrl, $requestData);
            Log::info('UAZ API instance creation attempt', [
                'event' => 'uazapi.instance.create',
                'step' => __METHOD__,
                'url' => $createInstanceUrl,
                'headers' => $headers,
                'request_data' => $requestData,
                'userId' => $userId,
                'deviceId' => $deviceId,
                'status' => $response->status(),
                'response' => $response->json(),
                'timestamp' => now()->toIso8601String()
            ]);
            if ($response->failed()) {
                return response()->json([
                    'message' => $response->json('error', self::INSTANCE_CREATION_ERROR_MESSAGE),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $instanceToken = $response->json('instance.token');
            $instanceId = $response->json('instance.id');
            if ($instanceToken) {
                $webhookEvents = [
                    ['event' => 'messages', 'route' => 'messages'],
                    ['event' => 'messages_update', 'route' => 'messages_update'],
                    ['event' => 'connection', 'route' => 'connection'],
                    ['event' => 'groups', 'route' => 'messages'],
                ];
                foreach ($webhookEvents as $webhookConfig) {
                    $webhookUrl = $baseUrl . '/webhooks/uazapi/' . $webhookConfig['route'] . '?userId=' . $userId . '&deviceId=' . $deviceId;
                    $webhookResponse = Http::withHeaders(['token' => $instanceToken])
                        ->timeout($timeout)
                        ->post($uazapiBaseUrl . '/webhook', [
                            'action' => 'add',
                            'enabled' => true,
                            'url' => $webhookUrl,
                            'events' => [$webhookConfig['event']],
                            'excludeMessages' => ['wasSentByApi'],
                        ]);
                    Log::info('UAZ API webhook configuration attempt', [
                        'event' => 'uazapi.webhook.configure',
                        'step' => __METHOD__,
                        'instanceId' => $instanceId,
                        'userId' => $userId,
                        'deviceId' => $deviceId,
                        'webhookEvent' => $webhookConfig['event'],
                        'webhookUrl' => $webhookUrl,
                        'status' => $webhookResponse->status(),
                        'response' => $webhookResponse->json(),
                        'timestamp' => now()->toIso8601String()
                    ]);
                }
            }
            return response()->json([
                'uid' => $instanceId,
                'token' => $instanceToken
            ]);
        } catch (RequestException $e) {
            captureException($e);
            logger()->error('deviceId #' . $deviceId . ' UazapiInstanceController create RequestException error', [
                'error' => $e->getMessage(),
                'userId' => $userId,
                'deviceId' => $deviceId,
                'url' => config('uazapi.base_url') . '/instance/create',
            ]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            captureException($e);
            logger()->error('deviceId #' . $deviceId . ' UazapiInstanceController create Throwable error', [
                'error' => $e->getMessage(),
                'userId' => $userId,
                'deviceId' => $deviceId,
                'url' => config('uazapi.base_url') . '/instance/create',
            ]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function subscribe(string $uid, string $token): JsonResponse
    {
        $uid = Str::before($uid, '-');
        logger()->info('deviceUid #' . $uid . ' UazapiInstanceController subscribe (no-op for UAZ API)', [
            'uid' => $uid,
        ]);
        return response()->json([
            'message' => 'Subscribed successfully',
            'paidTill' => date('Y-m-d H:i:s', strtotime('+10 years')),
        ]);
    }

    public function unsubscribe(string $uid, string $token): JsonResponse
    {
        $instanceUid = Str::before($uid, '-');
        try {
            $disconnectResponse = Http::withHeaders(['token' => $token])
                ->timeout(config('uazapi.timeout', 120))
                ->post(config('uazapi.base_url') . '/instance/disconnect');
            if ($disconnectResponse->failed() || $disconnectResponse->json('error')) {
                return response()->json([
                    'message' => 'Failed to disconnect instance',
                    'error' => $disconnectResponse->json('error') ?? self::UNKNOWN_ERROR_MESSAGE,
                ], Response::HTTP_BAD_GATEWAY);
            }
            logger()->info('deviceUid #' . $instanceUid . ' UazapiInstanceController unsubscribe success', [
                'uid' => $uid,
            ]);
            return response()->json([
                'message' => 'Unsubscribed successfully',
                'paidTill' => date('Y-m-d H:i:s'),
            ]);
        } catch (RequestException $e) {
            captureException($e);
            logger()->error('deviceUid #' . $instanceUid . ' UazapiInstanceController unsubscribe RequestException', [
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            captureException($e);
            logger()->error('deviceUid #' . $instanceUid . ' UazapiInstanceController unsubscribe Throwable', [
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
