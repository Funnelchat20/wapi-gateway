<?php
require __DIR__ . '/vendor/autoload.php';

use Funnelchat\WapiGateway\Clients\UazapiClient;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;

$configRepository = new Repository([
    'uazapi' => [
        'base_url' => 'https://funnelchat.uazapi.com',
        'timeout' => 120,
        'endpoints' => [
            'send_message' => '/send/text',
        ],
    ],
]);

$app = new Illuminate\Container\Container();
$app->instance('config', $configRepository);
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
        return app('config')->get($key, $default);
    }
}
if (!function_exists('app')) {
    function app($abstract = null) {
        return Illuminate\Container\Container::getInstance()->make($abstract);
    }
}

$client = new UazapiClient();
$result = $client->sendText(
    'dummy-uid',
    '03ee4f7a-07e6-4da8-ba23-b92544670c07',
    '5493764734151',
    'Test from PHP script'
);

echo "Result:\n";
print_r($result);
