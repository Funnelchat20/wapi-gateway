<?php
require __DIR__ . '/vendor/autoload.php';

use Funnelchat\WapiGateway\Clients\ZApiClient;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;

$configRepository = new Repository([
    'zapi' => [
        'client_token' => 'F6a40d22236054786ad4e761cb766c5d7S',
        'timeout' => 29,
        'max_attempts' => 2,
        'retry_delay' => 500,
    ],
]);

$app = new Illuminate\Container\Container();
$app->instance('config', $configRepository);
Illuminate\Container\Container::setInstance($app);
Facade::setFacadeApplication($app);

if (!function_exists('config')) {
    function config($key = null, $default = null) {
        return app('config')->get($key, $default);
    }
}
if (!function_exists('app')) {
    function app($abstract = null) {
        return Illuminate\Container\Container::getInstance()->make($abstract);
    }
}
if (!function_exists('logger')) {
    function logger() {
        return new class { 
            public function info($m, $c = []) {} 
            public function error($m, $c = []) { echo "[ERROR] $m\n"; }
        };
    }
}

$client = new ZApiClient();
$result = $client->sendText(
    '3EBCAF7A99F7302BD20EDAD09DD89927',
    '82FE6A7C7A9543D2AEF2E0EE8A20BD17',
    '5493764734151',
    'Test message from ZApi'
);

echo "Result:\n";
print_r($result);
