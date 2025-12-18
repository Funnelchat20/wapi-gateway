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
            'send_message' => '/message/text',
            'check_phone' => '/chat/whatsappNumbers',
        ],
    ],
    'app' => [
        'url' => $_ENV['APP_URL'],
    ],
]);

$app = new Illuminate\Container\Container();
$app->instance('config', $configRepository);

// Register logger mock
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

if (!function_exists('logger')) {
    function logger() {
        return new class {
            public function info($msg, $context = []) { /* silent */ }
            public function error($msg, $context = []) { /* silent */ }
        };
    }
}

// ========================================
// CREDENCIALES Y CONFIGURACIÓN
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

// Números de prueba
$SENDER = '5493764901973';   // Número conectado en las 3 instancias
$RECIPIENT = '5493764734151'; // Número destino para mensajes

// ========================================
// FUNCIONES DE UTILIDAD
// ========================================
function printHeader(string $title) {
    $line = str_repeat('=', 80);
    echo "\n$line\n";
    echo "  $title\n";
    echo "$line\n\n";
}

function printTest(string $testName) {
    echo "\n" . str_repeat('─', 80) . "\n";
    echo "  🧪 TEST: $testName\n";
    echo str_repeat('─', 80) . "\n";
}

function printProvider(string $name) {
    echo "\n┌─ " . str_pad($name, 74, '─') . "┐\n";
}

function printResult(string $status, float $elapsed, array $result, array $expected = []) {
    $icon = match($status) {
        'SUCCESS' => '✅',
        'ERROR' => '❌',
        'PARTIAL' => '⚠️',
        default => '❓'
    };
    
    echo "│ Status: $icon $status\n";
    echo "│ Time: {$elapsed}ms\n";
    
    if (!empty($expected)) {
        echo "│ Expected fields:\n";
        foreach ($expected as $field) {
            $present = isset($result[$field]) ? '✅' : '❌';
            $value = isset($result[$field]) ? (is_bool($result[$field]) ? ($result[$field] ? 'true' : 'false') : substr(json_encode($result[$field]), 0, 30)) : 'N/A';
            echo "│   $present $field: $value\n";
        }
    }
    
    echo "└" . str_repeat('─', 78) . "┘\n";
}

function summarize(array $results) {
    $total = count($results);
    $success = count(array_filter($results, fn($r) => $r['success']));
    $partial = count(array_filter($results, fn($r) => $r['partial'] ?? false));
    $failed = $total - $success - $partial;
    
    echo "\n📊 SUMMARY: ";
    echo "$success/$total passed";
    if ($partial > 0) echo " ($partial partial)";
    if ($failed > 0) echo ", $failed failed";
    echo "\n";
}

// ========================================
// TEST 1: STATUS (READ-ONLY)
// ========================================
printHeader('TEST 1: STATUS ENDPOINT (Read-Only)');
echo "Verifying all instances are authenticated...\n";

$statusResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->status($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        $expected = ['accountStatus', 'connected', 'smartphoneConnected'];
        $isAuthenticated = isset($result['accountStatus']) && $result['accountStatus'] === 'authenticated';
        $hasAllFields = count(array_intersect_key(array_flip($expected), $result)) === count($expected);
        
        $status = $isAuthenticated && $hasAllFields ? 'SUCCESS' : 'PARTIAL';
        printResult($status, $elapsed, $result, $expected);
        
        $statusResults[$providerName] = [
            'success' => $isAuthenticated,
            'partial' => !$isAuthenticated && $hasAllFields,
            'authenticated' => $isAuthenticated
        ];
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        printResult('ERROR', $elapsed, ['error' => $e->getMessage()]);
        $statusResults[$providerName] = ['success' => false, 'authenticated' => false];
    }
}

summarize($statusResults);

// Check if all are authenticated before proceeding
$allAuthenticated = array_reduce($statusResults, fn($carry, $r) => $carry && $r['authenticated'], true);

if (!$allAuthenticated) {
    echo "\n⚠️  WARNING: Not all instances are authenticated. Skipping message tests.\n";
    echo "Please connect all instances first.\n";
    exit(0);
}

// ========================================
// TEST 2: CHECK PHONE (READ-ONLY)
// ========================================
printTest("CHECK PHONE (Read-Only - Non-Intrusive)");
echo "Checking if recipient number exists on WhatsApp: +$RECIPIENT\n";

$checkPhoneResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->checkPhone($config['uid'], $config['token'], $RECIPIENT);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        $hasError = isset($result['error']);
        $status = !$hasError ? 'SUCCESS' : 'ERROR';
        
        echo "│ Status: " . ($hasError ? '❌ ERROR' : '✅ SUCCESS') . "\n";
        echo "│ Time: {$elapsed}ms\n";
        echo "│ Phone exists on WhatsApp: " . (isset($result['exists']) ? ($result['exists'] ? 'YES' : 'NO') : 'UNKNOWN') . "\n";
        if (!$hasError && isset($result['numberExists'])) {
            echo "│ Number: " . ($result['numberExists'] ? $RECIPIENT : 'Not found') . "\n";
        }
        echo "└" . str_repeat('─', 78) . "┘\n";
        
        $checkPhoneResults[$providerName] = ['success' => !$hasError];
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "│ Status: 💥 EXCEPTION\n";
        echo "│ Time: {$elapsed}ms\n";
        echo "│ Error: " . $e->getMessage() . "\n";
        echo "└" . str_repeat('─', 78) . "┘\n";
        $checkPhoneResults[$providerName] = ['success' => false];
    }
}

summarize($checkPhoneResults);

// ========================================
// TEST 3: SEND TEXT MESSAGE (MINIMAL)
// ========================================
printTest("SEND TEXT MESSAGE (Single test message)");
echo "⚠️  This will send ONE test message to +$RECIPIENT from each provider\n";
echo "Recipient: +$RECIPIENT\n";
echo "Message: \"[Test] Hello from {Provider} - Parity test at " . date('H:i:s') . "\"\n\n";

$sendResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    $message = "[Test] Hello from $providerName - Parity test at " . date('H:i:s');
    
    try {
        $result = $config['client']->sendText(
            $config['uid'], 
            $config['token'], 
            $RECIPIENT, 
            $message,
            ['retry' => false] // No retry for test
        );
        
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        $hasError = isset($result['error']);
        $expected = ['messageId', 'phone'];
        
        $status = !$hasError ? 'SUCCESS' : 'ERROR';
        printResult($status, $elapsed, $result, $expected);
        
        $sendResults[$providerName] = [
            'success' => !$hasError,
            'messageId' => $result['messageId'] ?? $result['id'] ?? null
        ];
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        printResult('ERROR', $elapsed, ['error' => $e->getMessage()]);
        $sendResults[$providerName] = ['success' => false, 'messageId' => null];
    }
}

summarize($sendResults);

// ========================================
// TEST 4: ME/PROFILE (READ-ONLY)
// ========================================
printTest("ME/PROFILE ENDPOINT (Read-Only)");
echo "Getting instance profile information...\n";

$meResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->me($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        $hasError = isset($result['error']);
        $expected = ['phone', 'name'];
        
        $status = !$hasError ? 'SUCCESS' : 'ERROR';
        
        echo "│ Status: " . ($hasError ? '❌ ERROR' : '✅ SUCCESS') . "\n";
        echo "│ Time: {$elapsed}ms\n";
        if (!$hasError) {
            echo "│ Phone: " . ($result['phone'] ?? $result['wid'] ?? 'N/A') . "\n";
            echo "│ Name: " . ($result['name'] ?? $result['pushname'] ?? 'N/A') . "\n";
        } else {
            echo "│ Error: " . $result['error'] . "\n";
        }
        echo "└" . str_repeat('─', 78) . "┘\n";
        
        $meResults[$providerName] = ['success' => !$hasError];
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "│ Status: 💥 EXCEPTION\n";
        echo "│ Time: {$elapsed}ms\n";
        echo "│ Error: " . $e->getMessage() . "\n";
        echo "└" . str_repeat('─', 78) . "┘\n";
        $meResults[$providerName] = ['success' => false];
    }
}

summarize($meResults);

// ========================================
// FINAL SUMMARY
// ========================================
printHeader('FINAL SUMMARY - ALL TESTS');

$allTests = [
    'Status (read-only)' => $statusResults,
    'Check Phone (read-only)' => $checkPhoneResults,
    'Send Text Message' => $sendResults,
    'Me/Profile (read-only)' => $meResults,
];

echo "Provider        | Status | Check  | Send   | Me     | Score\n";
echo str_repeat('─', 65) . "\n";

foreach (['ZApi', 'Funapi', 'Uazapi'] as $provider) {
    $scores = [];
    foreach ($allTests as $test => $results) {
        $scores[] = isset($results[$provider]) && $results[$provider]['success'] ? '✅' : '❌';
    }
    
    $totalSuccess = count(array_filter($scores, fn($s) => $s === '✅'));
    $totalTests = count($scores);
    
    printf("%-15s | %-6s | %-6s | %-6s | %-6s | %d/%d\n", 
        $provider, 
        $scores[0], 
        $scores[1], 
        $scores[2], 
        $scores[3],
        $totalSuccess,
        $totalTests
    );
}

echo "\n";
echo "Legend:\n";
echo "  ✅ = Test passed\n";
echo "  ❌ = Test failed\n";
echo "\n";
