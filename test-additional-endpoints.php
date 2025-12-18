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

function printResult(string $status, float $elapsed, array $result, string $detail = '') {
    $icon = match($status) {
        'SUCCESS' => '✅',
        'ERROR' => '❌',
        'SKIP' => '⏭️',
        default => '❓'
    };
    
    echo "│ Status: $icon $status\n";
    echo "│ Time: {$elapsed}ms\n";
    if ($detail) {
        echo "│ $detail\n";
    }
    if (isset($result['error'])) {
        echo "│ Error: " . substr($result['error'], 0, 60) . "\n";
    }
    echo "└" . str_repeat('─', 78) . "┘\n";
}

function summarize(array $results) {
    $total = count($results);
    $success = count(array_filter($results, fn($r) => $r['success']));
    $skipped = count(array_filter($results, fn($r) => $r['skipped'] ?? false));
    $failed = $total - $success - $skipped;
    
    echo "\n📊 SUMMARY: $success/$total passed";
    if ($skipped > 0) echo " ($skipped skipped)";
    if ($failed > 0) echo ", $failed failed";
    echo "\n";
}

// ========================================
// TEST 1: QR CODE (Read-Only cuando no conectado)
// ========================================
printHeader('TEST 1: QR CODE ENDPOINT (Read-Only)');
echo "Note: This will fail if instances are already connected (expected)\n";

$qrResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->qrCode($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        $hasError = isset($result['error']);
        $hasQR = isset($result['value']) || isset($result['qrcode']) || isset($result['image']);
        
        // If connected, error is expected
        $isConnectedError = $hasError && (
            str_contains(strtolower($result['error']), 'already connected') ||
            str_contains(strtolower($result['error']), 'connected')
        );
        
        if ($isConnectedError) {
            $status = 'SKIP';
            $detail = 'Already connected (expected)';
            $qrResults[$providerName] = ['success' => false, 'skipped' => true];
        } elseif ($hasQR) {
            $status = 'SUCCESS';
            $detail = 'QR code retrieved';
            $qrResults[$providerName] = ['success' => true];
        } else {
            $status = 'ERROR';
            $detail = '';
            $qrResults[$providerName] = ['success' => false];
        }
        
        printResult($status, $elapsed, $result, $detail);
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        printResult('ERROR', $elapsed, ['error' => $e->getMessage()]);
        $qrResults[$providerName] = ['success' => false];
    }
}

summarize($qrResults);

// ========================================
// TEST 2: CONTACTS (Read-Only)
// ========================================
printTest("CONTACTS LIST (Read-Only)");
echo "Getting first page of contacts...\n";

$contactsResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->contacts($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        $hasError = isset($result['error']);
        $hasContacts = isset($result['contacts']) || isset($result['data']) || is_array($result);
        
        if (!$hasError && $hasContacts) {
            $count = count($result['contacts'] ?? $result['data'] ?? $result);
            $status = 'SUCCESS';
            $detail = "Retrieved $count contacts";
            $contactsResults[$providerName] = ['success' => true];
        } else {
            $status = 'ERROR';
            $detail = '';
            $contactsResults[$providerName] = ['success' => false];
        }
        
        printResult($status, $elapsed, $result, $detail);
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        printResult('ERROR', $elapsed, ['error' => $e->getMessage()]);
        $contactsResults[$providerName] = ['success' => false];
    }
}

summarize($contactsResults);

// ========================================
// TEST 3: DEVICE INFO (Read-Only)
// ========================================
printTest("DEVICE INFO (Read-Only)");
echo "Getting connected device information...\n";

$deviceResults = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    $startTime = microtime(true);
    
    try {
        // Use me() as proxy for device info since it's more standardized
        $result = $config['client']->me($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        $hasError = isset($result['error']);
        
        if (!$hasError) {
            $phone = $result['phone'] ?? $result['wid'] ?? 'N/A';
            $name = $result['name'] ?? $result['pushname'] ?? 'N/A';
            $status = 'SUCCESS';
            $detail = "Phone: $phone, Name: $name";
            $deviceResults[$providerName] = ['success' => true];
        } else {
            $status = 'ERROR';
            $detail = '';
            $deviceResults[$providerName] = ['success' => false];
        }
        
        printResult($status, $elapsed, $result, $detail);
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        printResult('ERROR', $elapsed, ['error' => $e->getMessage()]);
        $deviceResults[$providerName] = ['success' => false];
    }
}

summarize($deviceResults);

// ========================================
// FINAL SUMMARY
// ========================================
printHeader('FINAL SUMMARY - ADDITIONAL ENDPOINTS');

$allTests = [
    'QR Code (read-only)' => $qrResults,
    'Contacts List (read-only)' => $contactsResults,
    'Device Info (read-only)' => $deviceResults,
];

echo "Provider        | QR Code | Contacts | Device | Score\n";
echo str_repeat('─', 60) . "\n";

foreach (['ZApi', 'Funapi', 'Uazapi'] as $provider) {
    $scores = [];
    foreach ($allTests as $test => $results) {
        if (isset($results[$provider])) {
            if ($results[$provider]['skipped'] ?? false) {
                $scores[] = '⏭️';
            } elseif ($results[$provider]['success']) {
                $scores[] = '✅';
            } else {
                $scores[] = '❌';
            }
        } else {
            $scores[] = '❌';
        }
    }
    
    $totalSuccess = count(array_filter($scores, fn($s) => $s === '✅'));
    $totalTests = count($scores);
    
    printf("%-15s | %-7s | %-8s | %-6s | %d/%d\n", 
        $provider, 
        $scores[0], 
        $scores[1], 
        $scores[2],
        $totalSuccess,
        $totalTests
    );
}

echo "\nLegend:\n";
echo "  ✅ = Test passed\n";
echo "  ❌ = Test failed\n";
echo "  ⏭️  = Skipped (expected behavior)\n";
echo "\n";
