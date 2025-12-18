#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;

// ========================================
// CONFIGURACIÓN DE ENTORNO
// ========================================
$_ENV['FUNAPI_BASE_URL'] = getenv('FUNAPI_BASE_URL') ?: 'http://homolog-whatsmeow-alb-731186848.us-east-1.elb.amazonaws.com';
$_ENV['FUNAPI_CLIENT_TOKEN'] = getenv('FUNAPI_CLIENT_TOKEN') ?: 'iB4uIxOYOMFSnScXWlphBg==';
$_ENV['FUNAPI_TIMEOUT'] = 29;
$_ENV['FUNAPI_MAX_ATTEMPTS'] = 2;
$_ENV['FUNAPI_RETRY_DELAY'] = 500;

$_ENV['ZAPI_CLIENT_TOKEN'] = getenv('ZAPI_CLIENT_TOKEN') ?: 'F6a40d22236054786ad4e761cb766c5d7S';
$_ENV['ZAPI_TIMEOUT'] = 29;
$_ENV['ZAPI_MAX_ATTEMPTS'] = 2;
$_ENV['ZAPI_RETRY_DELAY'] = 500;

$_ENV['UAZAPI_BASE_URL'] = getenv('UAZAPI_BASE_URL') ?: 'https://funnelchat.uazapi.com';
$_ENV['UAZAPI_ADMIN_TOKEN'] = getenv('UAZAPI_ADMIN_TOKEN') ?: 'dummy-admin-token';
$_ENV['UAZAPI_TIMEOUT'] = 120;

$_ENV['APP_URL'] = 'http://localhost';

// Bootstrap Laravel Config
$configRepository = new Repository([
    'funapi' => [
        'base_url' => $_ENV['FUNAPI_BASE_URL'],
        'client_token' => $_ENV['FUNAPI_CLIENT_TOKEN'],
        'timeout' => (int)$_ENV['FUNAPI_TIMEOUT'],
        'max_attempts' => (int)$_ENV['FUNAPI_MAX_ATTEMPTS'],
        'retry_delay' => (int)$_ENV['FUNAPI_RETRY_DELAY'],
    ],
    'zapi' => [
        'client_token' => $_ENV['ZAPI_CLIENT_TOKEN'],
        'timeout' => (int)$_ENV['ZAPI_TIMEOUT'],
        'max_attempts' => (int)$_ENV['ZAPI_MAX_ATTEMPTS'],
        'retry_delay' => (int)$_ENV['ZAPI_RETRY_DELAY'],
    ],
    'uazapi' => [
        'base_url' => $_ENV['UAZAPI_BASE_URL'],
        'admin_token' => $_ENV['UAZAPI_ADMIN_TOKEN'],
        'timeout' => (int)$_ENV['UAZAPI_TIMEOUT'],
        'endpoints' => [
            'status' => '/instance/status',
            'qr_code' => '/instance/connect',
            'connect' => '/instance/connect',
            'disconnect' => '/instance/disconnect',
            'send_message' => '/send/text',
            'contacts' => '/contacts',
        ],
    ],
    'app' => [
        'url' => $_ENV['APP_URL'],
    ],
]);

$app = new Illuminate\Container\Container();
$app->instance('config', $configRepository);

// Register PSR-3 logger
$app->singleton('log', function() {
    return new class implements \Psr\Log\LoggerInterface {
        public function emergency($message, array $context = []): void {}
        public function alert($message, array $context = []): void {}
        public function critical($message, array $context = []): void {}
        public function error($message, array $context = []): void {}
        public function warning($message, array $context = []): void {}
        public function notice($message, array $context = []): void {}
        public function info($message, array $context = []): void {}
        public function debug($message, array $context = []): void {}
        public function log($level, $message, array $context = []): void {}
    };
});

Illuminate\Container\Container::setInstance($app);
Facade::setFacadeApplication($app);

if (!function_exists('config')) {
    function config($key = null, $default = null) {
        if (is_null($key)) return app('config');
        if (is_array($key)) return app('config')->set($key);
        return app('config')->get($key, $default);
    }
}

if (!function_exists('app')) {
    function app($abstract = null) {
        $container = Illuminate\Container\Container::getInstance();
        if (is_null($abstract)) return $container;
        return $container->make($abstract);
    }
}

// ========================================
// CREDENCIALES
// ========================================
$testCases = [
    'ZApi' => [
        'uid' => getenv('ZAPI_UID') ?: '3EBCAF7A99F7302BD20EDAD09DD89927',
        'token' => getenv('ZAPI_TOKEN') ?: '82FE6A7C7A9543D2AEF2E0EE8A20BD17',
        'client' => new ZApiClient(),
    ],
    'Funapi' => [
        'uid' => getenv('FUNAPI_UID') ?: 'eb77158b-0be7-409c-a742-72bc36ee4596',
        'token' => getenv('FUNAPI_TOKEN') ?: '620a2169-8438-4a78-8db4-a57eaa09d447',
        'client' => new FunapiClient(),
    ],
    'Uazapi' => [
        'uid' => getenv('UAZAPI_UID') ?: 'dummy-uid',
        'token' => getenv('UAZAPI_TOKEN') ?: '03ee4f7a-07e6-4da8-ba23-b92544670c07',
        'client' => new UazapiClient(),
    ],
];

$RECIPIENT = '5493764734151';

// ========================================
// FUNCIONES DE UTILIDAD
// ========================================
function printHeader(string $title) {
    $line = str_repeat('=', 80);
    echo "\n$line\n";
    echo "  $title\n";
    echo "$line\n\n";
}

function printTest(string $testName, string $expectedFields) {
    echo "\n" . str_repeat('─', 80) . "\n";
    echo "  🧪 TEST: $testName\n";
    echo "  📋 Expected: $expectedFields\n";
    echo str_repeat('─', 80) . "\n";
}

function printProvider(string $name) {
    echo "\n┌─ " . str_pad($name, 74, '─') . "┐\n";
}

function validateFields(array $result, array $expectedFields, string $provider): array {
    $resultKeys = array_keys($result);
    $missing = array_diff($expectedFields, $resultKeys);
    $extra = array_diff($resultKeys, $expectedFields);
    
    $valid = empty($missing) && empty($extra);
    
    return [
        'valid' => $valid,
        'missing' => $missing,
        'extra' => $extra,
        'fieldCount' => count($resultKeys),
    ];
}

function printResult(string $provider, array $result, array $expectedFields, float $elapsed) {
    $validation = validateFields($result, $expectedFields, $provider);
    
    $icon = $validation['valid'] ? '✅' : '❌';
    $status = $validation['valid'] ? 'COMPLIANT' : 'NON-COMPLIANT';
    
    echo "│ Status: $icon $status\n";
    echo "│ Time: {$elapsed}ms\n";
    echo "│ Fields: {$validation['fieldCount']}/" . count($expectedFields) . "\n";
    
    if (!empty($validation['missing'])) {
        echo "│ ❌ Missing: " . implode(', ', $validation['missing']) . "\n";
    }
    
    if (!empty($validation['extra'])) {
        echo "│ ⚠️  Extra: " . implode(', ', $validation['extra']) . "\n";
    }
    
    echo "│ Response:\n";
    foreach ($result as $key => $value) {
        $inExpected = in_array($key, $expectedFields) ? '✅' : '❌';
        $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : (is_string($value) ? substr($value, 0, 50) : json_encode($value));
        echo "│   $inExpected $key: $displayValue\n";
    }
    echo "└" . str_repeat('─', 78) . "┘\n";
    
    return $validation['valid'];
}

// ========================================
// TEST 1: status()
// ========================================
printHeader('WAPI COMPLIANCE TEST - CORRECTED ENDPOINTS');
printTest('status()', 'accountStatus, qrCode');

$statusResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->status($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        if (isset($result['error'])) {
            echo "│ Status: ⚠️  ERROR\n";
            echo "│ Error: {$result['error']}\n";
            echo "└" . str_repeat('─', 78) . "┘\n";
            $statusResults[$providerName] = false;
            continue;
        }
        
        $statusResults[$providerName] = printResult(
            $providerName, 
            $result, 
            ['accountStatus', 'qrCode'], 
            $elapsed
        );
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "│ Status: 💥 EXCEPTION\n";
        echo "│ Time: {$elapsed}ms\n";
        echo "│ Error: " . $e->getMessage() . "\n";
        echo "└" . str_repeat('─', 78) . "┘\n";
        $statusResults[$providerName] = false;
    }
}

// ========================================
// TEST 2: qrCode()
// ========================================
printTest('qrCode()', 'connected, qrCode');

$qrResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->qrCode($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        if (isset($result['error'])) {
            echo "│ Status: ⚠️  ERROR (Expected if connected)\n";
            echo "│ Error: {$result['error']}\n";
            echo "└" . str_repeat('─', 78) . "┘\n";
            $qrResults[$providerName] = 'skipped';
            continue;
        }
        
        $qrResults[$providerName] = printResult(
            $providerName, 
            $result, 
            ['connected', 'qrCode'], 
            $elapsed
        ) ? 'pass' : 'fail';
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "│ Status: ⚠️  EXCEPTION (Expected if connected)\n";
        echo "│ Time: {$elapsed}ms\n";
        echo "│ Error: " . $e->getMessage() . "\n";
        echo "└" . str_repeat('─', 78) . "┘\n";
        $qrResults[$providerName] = 'skipped';
    }
}

// ========================================
// TEST 3: me()
// ========================================
printTest('me()', 'phone, locale, name, avatar, isBusiness');

$meResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->me($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        if (isset($result['error'])) {
            echo "│ Status: ❌ ERROR\n";
            echo "│ Error: {$result['error']}\n";
            echo "└" . str_repeat('─', 78) . "┘\n";
            $meResults[$providerName] = false;
            continue;
        }
        
        $meResults[$providerName] = printResult(
            $providerName, 
            $result, 
            ['phone', 'locale', 'name', 'avatar', 'isBusiness'], 
            $elapsed
        );
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "│ Status: 💥 EXCEPTION\n";
        echo "│ Time: {$elapsed}ms\n";
        echo "│ Error: " . $e->getMessage() . "\n";
        echo "└" . str_repeat('─', 78) . "┘\n";
        $meResults[$providerName] = false;
    }
}

// ========================================
// TEST 4: sendText() - Solo ZApi y Uazapi
// ========================================
printTest('sendText()', 'sent, message, id, queueNumber');
echo "⚠️  Testing only ZApi and Uazapi (Funapi not implemented)\n";

$sendResults = [];

foreach (['ZApi', 'Uazapi'] as $providerName) {
    if (!isset($testCases[$providerName])) continue;
    
    $config = $testCases[$providerName];
    printProvider($providerName);
    $startTime = microtime(true);
    
    $message = "[Compliance Test] from $providerName at " . date('H:i:s');
    
    try {
        $result = $config['client']->sendText(
            $config['uid'], 
            $config['token'], 
            $RECIPIENT, 
            $message
        );
        
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        if (isset($result['error'])) {
            echo "│ Status: ❌ ERROR\n";
            echo "│ Error: {$result['error']}\n";
            echo "└" . str_repeat('─', 78) . "┘\n";
            $sendResults[$providerName] = false;
            continue;
        }
        
        $sendResults[$providerName] = printResult(
            $providerName, 
            $result, 
            ['sent', 'message', 'id', 'queueNumber'], 
            $elapsed
        );
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "│ Status: 💥 EXCEPTION\n";
        echo "│ Time: {$elapsed}ms\n";
        echo "│ Error: " . $e->getMessage() . "\n";
        echo "└" . str_repeat('─', 78) . "┘\n";
        $sendResults[$providerName] = false;
    }
}

$sendResults['Funapi'] = 'N/I';

// ========================================
// FINAL SUMMARY
// ========================================
printHeader('COMPLIANCE SUMMARY');

echo "Endpoint    | ZApi   | Funapi | Uazapi | Expected Fields\n";
echo str_repeat('─', 80) . "\n";

$tests = [
    'status()' => ['results' => $statusResults, 'fields' => 'accountStatus, qrCode'],
    'qrCode()' => ['results' => $qrResults, 'fields' => 'connected, qrCode'],
    'me()' => ['results' => $meResults, 'fields' => 'phone, locale, name, avatar, isBusiness'],
    'sendText()' => ['results' => $sendResults, 'fields' => 'sent, message, id, queueNumber'],
];

foreach ($tests as $endpoint => $data) {
    $zapi = isset($data['results']['ZApi']) ? ($data['results']['ZApi'] === true ? '✅' : ($data['results']['ZApi'] === 'skipped' ? '⏭️' : ($data['results']['ZApi'] === 'N/I' ? '⚠️' : '❌'))) : '❌';
    $funapi = isset($data['results']['Funapi']) ? ($data['results']['Funapi'] === true ? '✅' : ($data['results']['Funapi'] === 'skipped' ? '⏭️' : ($data['results']['Funapi'] === 'N/I' ? '⚠️' : '❌'))) : '❌';
    $uazapi = isset($data['results']['Uazapi']) ? ($data['results']['Uazapi'] === true ? '✅' : ($data['results']['Uazapi'] === 'skipped' ? '⏭️' : ($data['results']['Uazapi'] === 'N/I' ? '⚠️' : '❌'))) : '❌';
    
    printf("%-11s | %-6s | %-6s | %-6s | %s\n", $endpoint, $zapi, $funapi, $uazapi, $data['fields']);
}

echo "\nLegend:\n";
echo "  ✅ = Fully compliant (exact fields)\n";
echo "  ❌ = Non-compliant (missing/extra fields)\n";
echo "  ⏭️  = Skipped (expected behavior)\n";
echo "  ⚠️  = Not Implemented\n";

echo "\n" . str_repeat('─', 80) . "\n";

$totalTests = count($statusResults) + count(array_filter($qrResults, fn($r) => $r !== 'skipped')) + count($meResults) + count(array_filter($sendResults, fn($r) => $r !== 'N/I'));
$passedTests = count(array_filter($statusResults)) + count(array_filter($qrResults, fn($r) => $r === 'pass')) + count(array_filter($meResults)) + count(array_filter($sendResults, fn($r) => $r === true));

echo "\n📊 OVERALL COMPLIANCE: $passedTests tests passed\n";

if ($passedTests === $totalTests) {
    echo "✅ 100% WAPI COMPLIANT!\n";
} else {
    echo "⚠️  Some endpoints need attention\n";
}

echo "\n";

