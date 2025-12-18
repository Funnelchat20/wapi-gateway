#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Funnelchat\WapiGateway\Clients\FunapiClient;
use Funnelchat\WapiGateway\Clients\ZApiClient;
use Funnelchat\WapiGateway\Clients\UazapiClient;
use Funnelchat\WapiGateway\Clients\MetaClient;

// ========================================
// CONFIGURACIÓN DE ENTORNO
// ========================================
$_ENV['FUNAPI_BASE_URL'] = getenv('FUNAPI_BASE_URL') ?: 'http://homolog-whatsmeow-alb-731186848.us-east-1.elb.amazonaws.com';
$_ENV['FUNAPI_CLIENT_TOKEN'] = getenv('FUNAPI_CLIENT_TOKEN') ?: 'iB4uIxOYOMFSnScXWlphBg==';
$_ENV['FUNAPI_TIMEOUT'] = 29;
$_ENV['FUNAPI_MAX_ATTEMPTS'] = 2;
$_ENV['FUNAPI_RETRY_DELAY'] = 500;

$_ENV['ZAPI_CLIENT_TOKEN'] = getenv('ZAPI_CLIENT_TOKEN') ?: 'dummy-token-for-test';
$_ENV['ZAPI_TIMEOUT'] = 29;
$_ENV['ZAPI_MAX_ATTEMPTS'] = 2;
$_ENV['ZAPI_RETRY_DELAY'] = 500;

$_ENV['UAZAPI_BASE_URL'] = getenv('UAZAPI_BASE_URL') ?: 'https://funnelchat.uazapi.com';
$_ENV['UAZAPI_ADMIN_TOKEN'] = getenv('UAZAPI_ADMIN_TOKEN') ?: 'dummy-admin-token';
$_ENV['UAZAPI_TIMEOUT'] = 120;

$_ENV['APP_URL'] = 'http://localhost';

// Bootstrap Laravel Config Repository
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;

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
        ],
    ],
    'app' => [
        'url' => $_ENV['APP_URL'],
    ],
]);

// Register config in container
$app = new Illuminate\Container\Container();
$app->instance('config', $configRepository);
Illuminate\Container\Container::setInstance($app);
Facade::setFacadeApplication($app);

// Helper for compatibility
if (!function_exists('config')) {
    function config($key = null, $default = null) {
        if (is_null($key)) {
            return app('config');
        }
        if (is_array($key)) {
            return app('config')->set($key);
        }
        return app('config')->get($key, $default);
    }
}

if (!function_exists('app')) {
    function app($abstract = null) {
        $container = Illuminate\Container\Container::getInstance();
        if (is_null($abstract)) {
            return $container;
        }
        return $container->make($abstract);
    }
}

// Simular logger de Laravel
if (!function_exists('logger')) {
    function logger() {
        return new class {
            public function info($msg, $context = []) { /* silent */ }
            public function error($msg, $context = []) { echo "[ERROR] $msg\n"; }
        };
    }
}

// ========================================
// CREDENCIALES DE PRUEBA
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
        'token' => getenv('UAZAPI_TOKEN') ?: 'dummy-token',
        'client' => new UazapiClient(),
    ],
    'Meta' => [
        'uid' => getenv('META_UID') ?: 'dummy-uid',
        'token' => getenv('META_TOKEN') ?: 'dummy-token',
        'client' => new MetaClient(),
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

function printProvider(string $name) {
    echo "\n┌─ " . str_pad($name, 74, '─') . "┐\n";
}

function printResult(array $result) {
    echo "│ Response:\n";
    foreach ($result as $key => $value) {
        $valueStr = is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value;
        $lines = explode("\n", $valueStr);
        foreach ($lines as $idx => $line) {
            if ($idx === 0) {
                echo "│   " . str_pad("$key:", 20) . "$line\n";
            } else {
                echo "│   " . str_pad("", 20) . "$line\n";
            }
        }
    }
    echo "└" . str_repeat('─', 78) . "┘\n";
}

function compareResults(array $results) {
    echo "\n┌─ COMPARISON MATRIX " . str_repeat('─', 58) . "┐\n";
    
    // Collect all unique keys from all results
    $allKeys = [];
    foreach ($results as $result) {
        $allKeys = array_merge($allKeys, array_keys($result));
    }
    $allKeys = array_unique($allKeys);
    
    // Print header
    echo "│ " . str_pad("Field", 20) . " │ ";
    foreach (array_keys($results) as $provider) {
        echo str_pad($provider, 15) . " │ ";
    }
    echo "\n";
    echo "│ " . str_repeat('─', 20) . " │ ";
    foreach ($results as $provider => $result) {
        echo str_repeat('─', 15) . " │ ";
    }
    echo "\n";
    
    // Print each field
    foreach ($allKeys as $key) {
        echo "│ " . str_pad($key, 20) . " │ ";
        foreach ($results as $provider => $result) {
            $value = $result[$key] ?? '❌ N/A';
            if (is_array($value)) {
                $value = '📦 ' . count($value) . ' items';
            } elseif (is_bool($value)) {
                $value = $value ? '✅ true' : '❌ false';
            } else {
                $value = substr($value, 0, 13);
            }
            echo str_pad($value, 15) . " │ ";
        }
        echo "\n";
    }
    
    echo "└" . str_repeat('─', 78) . "┘\n";
}

// ========================================
// TEST: STATUS ENDPOINT
// ========================================
printHeader('TEST: STATUS ENDPOINT PARITY');

echo "Testing status() method across all providers...\n";
echo "Expected behavior:\n";
echo "  - All providers should return 'accountStatus' field\n";
echo "  - Authenticated instances should return accountStatus='authenticated'\n";
echo "  - Not connected should return accountStatus='got qr code' with QR data\n";
echo "  - Errors should be normalized\n\n";

$results = [];

foreach ($testCases as $providerName => $config) {
    printProvider($providerName);
    
    $startTime = microtime(true);
    
    try {
        $result = $config['client']->status($config['uid'], $config['token']);
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        
        echo "│ Status: " . (isset($result['error']) ? '❌ ERROR' : '✅ SUCCESS') . "\n";
        echo "│ Time: {$elapsed}ms\n";
        
        $results[$providerName] = $result;
        printResult($result);
        
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        echo "│ Status: 💥 EXCEPTION\n";
        echo "│ Time: {$elapsed}ms\n";
        echo "│ Message: " . $e->getMessage() . "\n";
        echo "└" . str_repeat('─', 78) . "┘\n";
        
        $results[$providerName] = ['error' => 'Exception: ' . $e->getMessage()];
    }
}

// ========================================
// COMPARISON
// ========================================
compareResults($results);

// ========================================
// SUMMARY
// ========================================
printHeader('SUMMARY');

$successful = array_filter($results, fn($r) => !isset($r['error']));
$withAccountStatus = array_filter($results, fn($r) => isset($r['accountStatus']));
$errors = array_filter($results, fn($r) => isset($r['error']));

echo "Total providers tested: " . count($results) . "\n";
echo "Successful responses: " . count($successful) . " ✅\n";
echo "With 'accountStatus' field: " . count($withAccountStatus) . " 🎯\n";
echo "Errors/Not supported: " . count($errors) . " ❌\n\n";

if (count($withAccountStatus) === count($results)) {
    echo "🎉 SUCCESS! All providers return 'accountStatus' field\n";
} else {
    echo "⚠️  WARNING: Not all providers return 'accountStatus' field\n";
    $missing = array_diff(array_keys($results), array_keys($withAccountStatus));
    echo "   Missing in: " . implode(', ', $missing) . "\n";
}

echo "\n";
echo "To customize test credentials, use environment variables:\n";
echo "  FUNAPI_UID, FUNAPI_TOKEN, FUNAPI_BASE_URL, FUNAPI_CLIENT_TOKEN\n";
echo "  ZAPI_UID, ZAPI_TOKEN, ZAPI_CLIENT_TOKEN\n";
echo "  UAZAPI_UID, UAZAPI_TOKEN, UAZAPI_BASE_URL\n";
echo "  META_UID, META_TOKEN\n";

echo "\n";
