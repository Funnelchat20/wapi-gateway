<?php

require __DIR__ . '/vendor/autoload.php';

use Funnelchat\WapiGateway\Clients\FunapiClient;

// Configurar temporalmente para la prueba
$_ENV['FUNAPI_BASE_URL'] = 'http://homolog-whatsmeow-alb-731186848.us-east-1.elb.amazonaws.com';
$_ENV['FUNAPI_TOKEN'] = 'iB4uIxOYOMFSnScXWlphBg==';
$_ENV['FUNAPI_CLIENT_TOKEN'] = 'iB4uIxOYOMFSnScXWlphBg==';
$_ENV['APP_URL'] = 'http://localhost';

// Simular config() helper
if (!function_exists('config')) {
    function config($key, $default = null) {
        $configs = [
            'funapi.base_url' => $_ENV['FUNAPI_BASE_URL'],
            'funapi.token' => $_ENV['FUNAPI_TOKEN'],
            'funapi.client_token' => $_ENV['FUNAPI_CLIENT_TOKEN'],
            'funapi.on_demand_url' => $_ENV['FUNAPI_BASE_URL'] . '/instances/integrator/on-demand',
            'funapi.timeout' => 29,
            'funapi.max_attempts' => 2,
            'funapi.retry_delay' => 500,
            'app.url' => $_ENV['APP_URL'],
        ];
        return $configs[$key] ?? $default;
    }
}

echo "=== Test de Creación de Instancia Funapi ===\n";
echo "Base URL: " . config('funapi.base_url') . "\n";
echo "Token: " . substr(config('funapi.token'), 0, 10) . "...\n\n";

$client = new FunapiClient();

// Probar crear instancia
$userId = 1;
$deviceId = 999;

echo "Creando instancia para User ID: $userId, Device ID: $deviceId\n";
echo "Endpoint: " . config('funapi.on_demand_url') . "\n\n";

try {
    $result = $client->create($userId, $deviceId);
    
    echo "Resultado:\n";
    print_r($result);
    
    if (isset($result['error'])) {
        echo "\n❌ Error: " . $result['error'] . "\n";
    } else {
        echo "\n✅ Instancia creada exitosamente!\n";
        if (isset($result['uid'])) {
            echo "UID: " . $result['uid'] . "\n";
        }
        if (isset($result['token'])) {
            echo "Token: " . substr($result['token'], 0, 20) . "...\n";
        }
    }
} catch (Exception $e) {
    echo "\n❌ Excepción: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Fin del test ===\n";
