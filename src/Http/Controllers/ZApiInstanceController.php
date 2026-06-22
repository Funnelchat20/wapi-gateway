<?php

namespace Funnelchat\WapiGateway\Http\Controllers;

use Funnelchat\WapiGateway\Interfaces\WhatsAppProviderInstanceInterface;
use Illuminate\Routing\Controller;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ZApiInstanceController extends Controller implements WhatsAppProviderInstanceInterface
{
    private const SESSION_NAME = 'Funnelchat';
    private const DISCONNECTED = 'disconnect';
    private const LIGHT_GROUP_METADATA = 'light-group-metadata';
    private const INSTANCE_CREATION_ERROR_MESSAGE = 'Failed to create instance';
    private const FAILED_TO_FETCH_PARTICIPANTS_MESSAGE = 'Failed to fetch participants';
    private const UNKNOWN_ERROR_MESSAGE = 'Unknown error';

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate(['user_id' => 'required|int', 'device_id' => 'required|int']);
        $userId = $validated['user_id'];
        $deviceId = $validated['device_id'];
        $name = 'U-' . $userId . ' D-' . $deviceId;
        $baseUrl = config('app.url');
        try {
            $response = Http::withToken(config('zapi.token'))->post(config('zapi.on_demand_url'), [
                'name' => $name,
                'sessionName' => self::SESSION_NAME,
                'receivedCallbackUrl' => $baseUrl . '/webhooks/zapi/received?userId=' . $userId . '&deviceId=' . $deviceId,
                'receivedAndDeliveryCallbackUrl' => $baseUrl . '/webhooks/zapi/received-and-delivery?userId=' . $userId . '&deviceId=' . $deviceId,
                'disconnectedCallbackUrl' => $baseUrl . '/webhooks/zapi/disconnected?userId=' . $userId . '&deviceId=' . $deviceId,
                'connectedCallbackUrl' => $baseUrl . '/webhooks/zapi/connected?userId=' . $userId . '&deviceId=' . $deviceId,
                'messageStatusCallbackUrl' => $baseUrl . '/webhooks/zapi/message-status?userId=' . $userId . '&deviceId=' . $deviceId,
                'blockCallbackUrl' => $baseUrl . '/webhooks/zapi/block?userId=' . $userId . '&deviceId=' . $deviceId
            ]);
            if ($response->failed()) {
                return response()->json(['message' => $response->json('error', self::INSTANCE_CREATION_ERROR_MESSAGE)], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } catch (RequestException $e) {
            logger()->error('deviceId #' . $deviceId . ' ZApiInstanceController create RequestException error', [
                'error' => $e->getMessage(),
                'userId' => $userId,
                'deviceId' => $deviceId,
                'url' => config('zapi.on_demand_url'),
            ]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            logger()->error('deviceId #' . $deviceId . ' ZApiInstanceController create Throwable error', [
                'error' => $e->getMessage(),
                'userId' => $userId,
                'deviceId' => $deviceId,
                'url' => config('zapi.on_demand_url'),
            ]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return response()->json(['uid' => $response->json('id'), 'token' => $response->json('token')]);
    }

    public function subscribe(string $uid, string $token): JsonResponse
    {
        $uid = Str::before($uid, '-');
        $url = str_replace(['UID', 'TOKEN'], [$uid, $token], config('zapi.subscription_url'));
        $response = Http::withToken(config('zapi.token'))->post($url);
        if ($response->failed() || $response->json('error')) return response()->json([
            'message' => 'Failed to subscribe',
            'error' => $response->json('error')
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
        return response()->json(['message' => 'Subscribed successfully', 'paidTill' => date('Y-m-d H:i:s', $response->json('due') / 1000)]);
    }

    public function unsubscribe(string $uid, string $token): JsonResponse
    {
        $instanceUid = Str::before($uid, '-');
        try {
            $disconnectUrl = str_replace(['UID', 'TOKEN', 'ACTION'], [$instanceUid, $token, self::DISCONNECTED], config('zapi.zapi_url'));
            $disconnectResponse = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($disconnectUrl);
            if ($disconnectResponse->failed() || $disconnectResponse->json('error')) {
                return response()->json([
                    'message' => 'Failed to disconnect instance',
                    'error' => $disconnectResponse->json('error') ?? self::UNKNOWN_ERROR_MESSAGE
                ], Response::HTTP_BAD_GATEWAY);
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            logger()->error('deviceUid #' . $instanceUid . ' Z-API disconnect failed (RequestException)', ['uid' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            logger()->error('deviceUid #' . $instanceUid . ' Z-API disconnect failed (Throwable)', ['uid' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        try {
            $unsubscribeUrl = str_replace(['UID', 'TOKEN'], [$instanceUid, $token], config('zapi.unsubscription_url'));
            $unsubscribeResponse = Http::withToken(config('zapi.token'))->post($unsubscribeUrl);
            if ($unsubscribeResponse->failed() || $unsubscribeResponse->json('error')) {
                return response()->json([
                    'message' => 'Failed to unsubscribe instance',
                    'error' => $unsubscribeResponse->json('error') ?? 'Unknown unsubscribe error'
                ], Response::HTTP_BAD_GATEWAY);
            }
            return response()->json([
                'message' => 'Unsubscribed successfully',
                'paidTill' => date('Y-m-d H:i:s', $unsubscribeResponse->json('due') / 1000)
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            logger()->error('deviceUid #' . $instanceUid . ' Z-API unsubscribe failed (RequestException)', ['uid' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            logger()->error('deviceUid #' . $instanceUid . ' Z-API unsubscribe failed (Throwable)', ['uid' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function configureProxy(Request $request, string $uid, string $token): JsonResponse
    {
        $validated = $request->validate([
            'proxyUrl' => ['nullable', 'string'],
            'enable' => ['required', 'boolean'],
        ]);
        $instanceUid = Str::before($uid, '-');
        $url = str_replace(['UID', 'TOKEN'], [$instanceUid, $token], config('zapi.proxy_url'));
        try {
            $response = Http::withToken(config('zapi.token'))->put($url, [
                'proxyUrl' => $validated['proxyUrl'] ?? '',
                'enable' => $validated['enable'],
            ]);
            if ($response->failed() || $response->json('error')) {
                return response()->json([
                    'message' => 'Failed to configure proxy',
                    'error' => $response->json('error') ?? self::UNKNOWN_ERROR_MESSAGE,
                ], Response::HTTP_BAD_GATEWAY);
            }
            return response()->json(['value' => $response->json('value', true)]);
        } catch (RequestException $e) {
            logger()->error('deviceUid #' . $instanceUid . ' Z-API configure proxy failed (RequestException)', ['uid' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            logger()->error('deviceUid #' . $instanceUid . ' Z-API configure proxy failed (Throwable)', ['uid' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getParticipants(string $uid, string $token, string $phone): array|JsonResponse
    {
        $url = str_replace(['UID', 'TOKEN', 'ACTION'], [$uid, $token, self::LIGHT_GROUP_METADATA], config('zapi.zapi_url')) . '/' . $phone;
        try {
            $response = Http::withHeaders(['Client-Token' => config('zapi.client_token')])->get($url);
            if ($response->failed() || $response->json('error') || $response->json('success') === false) {
                logger()->error('deviceUid #' . $uid . ' ZApiInstanceController getParticipants error response', [
                    'message' => self::FAILED_TO_FETCH_PARTICIPANTS_MESSAGE,
                    'error' => $response->json('error') ?? self::UNKNOWN_ERROR_MESSAGE,
                    'url' => $url
                ]);
                return [];
            }
        } catch (RequestException $e) {
            $this->handleHttpException($e, $url, 'RequestException', $uid);
            return [];
        } catch (\Throwable $e) {
            $this->handleHttpException($e, $url, 'Throwable', $uid);
            return [];
        }
        return $response->json('participants');
    }

    private function handleHttpException(\Throwable $e, string $url, string $type, ?string $uid): void
    {
        logger()->error('deviceUid #' . $uid . ' ZApiInstanceController getParticipants ' . $type . ' error', [
            'error' => $e->getMessage(),
            'deviceUid' => $uid,
            'url' => $url
        ]);
    }
}
