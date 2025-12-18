<?php

require __DIR__ . '/vendor/autoload.php';

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;

// Configurar para las pruebas
$_ENV['FUNAPI_BASE_URL'] = 'http://homolog-whatsmeow-alb-731186848.us-east-1.elb.amazonaws.com';
$_ENV['FUNAPI_CLIENT_TOKEN'] = 'iB4uIxOYOMFSnScXWlphBg==';
$_ENV['ZAPI_CLIENT_TOKEN'] = 'not-needed-for-test';
$_ENV['UAZAPI_BASE_URL'] = 'https://funnelchat.uazapi.com';

// Simular config() helper
if (!function_exists('config')) {
    function config($key, $default = null) {
        $configs = [
            'funapi.base_url' => $_ENV['FUNAPI_BASE_URL'],
            'funapi.client_token' => $_ENV['FUNAPI_CLIENT_TOKEN'],
            'zapi.client_token' => $_ENV['ZAPI_CLIENT_TOKEN'],
            'uazapi.base_url' => $_ENV['UAZAPI_BASE_URL'],
            'uazapi.timeout' => 30,
            'uazapi.endpoints.status' => '/instance/status',
            'uazapi.endpoints.qr_code' => '/instance/connect',
        ];
        return $configs[$key] ?? $default;
    }
}

echo "=== PRUEBA DE COMPATIBILIDAD ENTRE PROVEEDORES ===\n\n";

// Credenciales
$funapi = ['uid' => 'eb77158b-0be7-409c-a742-72bc36ee4596', 'token' => '620a2169-8438-4a78-8db4-a57eaa09d447'];
$zapi = ['uid' => '3EBCAF7A99F7302BD20EDAD09DD89927', 'token' => '82FE6A7C7A9543D2AEF2E0EE8A20BD17'];
$uazapi = ['token' => '03ee4f7a-07e6-4da8-ba23-b92544670c07'];
$phone = '5493764901973';

echo "Número de teléfono en todos: $phone\n\n";

// ========================================
// TEST 1: STATUS (CRÍTICO - con QR fetch automático)
// ========================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 1: STATUS (debe incluir accountStatus y QR)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Funapi Status
echo "📱 FUNAPI (desconectada):\n";
$funapiClient = new FunapiClient();
$funapiStatus = $funapiClient->status($funapi['uid'], $funapi['token']);
echo "accountStatus: " . ($funapiStatus['accountStatus'] ?? 'MISSING') . "\n";
echo "qrCode: " . (isset($funapiStatus['qrCode']) ? substr($funapiStatus['qrCode'], 0, 50) . '...' : 'MISSING') . "\n";
if (isset($funapiStatus['error'])) {
    echo "❌ Error: " . $funapiStatus['error'] . "\n";
}
echo "\n";

// ZApi Status
echo "📱 ZAPI:\n";
$zapiClient = new ZApiClient();
$zapiStatus = $zapiClient->status($zapi['uid'], $zapi['token']);
echo "accountStatus: " . ($zapiStatus['accountStatus'] ?? 'MISSING') . "\n";
echo "qrCode: " . (isset($zapiStatus['qrCode']) ? substr($zapiStatus['qrCode'], 0, 50) . '...' : 'NOT PRESENT') . "\n";
if (isset($zapiStatus['error'])) {
    echo "❌ Error: " . $zapiStatus['error'] . "\n";
}
echo "\n";

// Uazapi Status
echo "📱 UAZAPI:\n";
$uazapiClient = new UazapiClient();
$uazapiStatus = $uazapiClient->status('', $uazapi['token']);
echo "accountStatus: " . ($uazapiStatus['accountStatus'] ?? 'MISSING') . "\n";
echo "qrCode: " . (isset($uazapiStatus['qrCode']) ? substr($uazapiStatus['qrCode'], 0, 50) . '...' : 'NOT PRESENT') . "\n";
if (isset($uazapiStatus['error'])) {
    echo "❌ Error: " . $uazapiStatus['error'] . "\n";
}
echo "\n";

// Verificación de consistencia
echo "✅ VERIFICACIÓN:\n";
$hasAccountStatus = isset($funapiStatus['accountStatus']) && isset($zapiStatus['accountStatus']) && isset($uazapiStatus['accountStatus']);
echo "- accountStatus presente en todos: " . ($hasAccountStatus ? "SÍ ✅" : "NO ❌") . "\n";
echo "\n";

echo "=== FIN DE PRUEBAS ===\n";
