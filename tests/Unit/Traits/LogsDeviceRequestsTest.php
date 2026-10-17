<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Traits;

use Funnelchat\WapiGateway\Jobs\StoreDeviceLogJob;
use Funnelchat\WapiGateway\Providers\WapiServiceProvider;
use Funnelchat\WapiGateway\Traits\LogsDeviceRequests;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What logRequest() keeps of a URL. Signed URLs are bearer credentials while
 * they live, so neither the log line nor the device_logs row may hold their
 * query string; the existing key redaction and token masking stay as they are.
 */
class LogsDeviceRequestsTest extends TestCase
{
    private const SIGNED = 'https://b.s3.amazonaws.com/a/photo.jpg?X-Amz-Expires=300&X-Amz-Signature=abc';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function getPackageProviders($app): array
    {
        return [WapiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['wapi-gateway.logging_enabled' => true]);
        Queue::fake();
        config(['logging.default' => 'null']);
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    private function logger(): object
    {
        return new class {
            use LogsDeviceRequests;

            protected function getProviderName(): string
            {
                return 'test';
            }

            public function log(array $context, string $url, array $payload): void
            {
                $this->logRequest('sendFile', 'UID', $context, microtime(true), null, $url, $payload);
            }

            public function strip(string $value): string
            {
                return $this->stripUrlSecrets($value);
            }
        };
    }

    private function prop(StoreDeviceLogJob $job, string $name): mixed
    {
        return (new \ReflectionProperty($job, $name))->getValue($job);
    }

    private function pushedJob(): StoreDeviceLogJob
    {
        $jobs = Queue::pushed(StoreDeviceLogJob::class);
        $this->assertCount(1, $jobs);

        return $jobs->first();
    }

    public static function stripProvider(): array
    {
        return [
            'S3 signature' => [self::SIGNED, 'https://b.s3.amazonaws.com/a/photo.jpg'],
            'GCS signature' => ['https://storage.googleapis.com/b/doc.pdf?X-Goog-Algorithm=GOOG4&X-Goog-Signature=f00', 'https://storage.googleapis.com/b/doc.pdf'],
            'sig param' => ['https://cdn.example.com/v.mp4?sig=abc&se=2026', 'https://cdn.example.com/v.mp4'],
            'token param' => ['https://cdn.example.com/media/8f3a2b?token=abc', 'https://cdn.example.com/media/8f3a2b'],
            'fragment' => ['https://cdn.example.com/a.mp4#t=10', 'https://cdn.example.com/a.mp4'],
            'user info' => ['https://user:pass@cdn.example.com/a.jpg', 'https://cdn.example.com/a.jpg'],
            'port kept' => ['http://cdn.example.com:8080/a.jpg?k=v', 'http://cdn.example.com:8080/a.jpg'],
            'host only' => ['https://cdn.example.com?k=v', 'https://cdn.example.com'],
            'uppercase scheme' => ['HTTPS://cdn.example.com/a.jpg?k=v', 'HTTPS://cdn.example.com/a.jpg'],
            'nothing to drop' => ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/a.jpg'],
            'not a URL' => ['5491100000000', '5491100000000'],
            'other scheme' => ['ftp://cdn.example.com/a.jpg?k=v', 'ftp://cdn.example.com/a.jpg?k=v'],
            // Only a value that is a URL as a whole: message text is left alone.
            'URL inside text' => ['Mirá https://cdn.example.com/a.jpg?k=v', 'Mirá https://cdn.example.com/a.jpg?k=v'],
        ];
    }

    #[DataProvider('stripProvider')]
    public function test_strips_query_fragment_and_user_info_from_a_url(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->logger()->strip($value));
    }

    public function test_the_queued_payload_keeps_no_url_query_at_any_depth(): void
    {
        $this->logger()->log([], 'https://graph.facebook.com/v26.0/123/messages', [
            'type' => 'image',
            'image' => ['link' => self::SIGNED, 'caption' => 'Hola'],
            'list' => [['url' => 'https://cdn.example.com/x.pdf?sig=1']],
        ]);

        $this->assertSame([
            'type' => 'image',
            'image' => ['link' => 'https://b.s3.amazonaws.com/a/photo.jpg', 'caption' => 'Hola'],
            'list' => [['url' => 'https://cdn.example.com/x.pdf']],
        ], $this->prop($this->pushedJob(), 'requestPayload'));
    }

    public function test_sensitive_keys_are_still_redacted(): void
    {
        $this->logger()->log([], 'https://uazapi.example.com/send/media', [
            'token' => 'SECRET',
            'nested' => ['client_token' => 'SECRET', 'password' => 'SECRET'],
            'file' => self::SIGNED,
        ]);

        $this->assertSame([
            'token' => '***',
            'nested' => ['client_token' => '***', 'password' => '***'],
            'file' => 'https://b.s3.amazonaws.com/a/photo.jpg',
        ], $this->prop($this->pushedJob(), 'requestPayload'));
    }

    /** Our own endpoint keeps its query (messageId, phone); the token stays masked. */
    public function test_a_provider_endpoint_with_our_token_keeps_its_query_and_masking(): void
    {
        $this->logger()->log([], 'https://api.z-api.io/instances/UID/token/SECRET/messages?messageId=M1&phone=549', []);

        $this->assertSame(
            'https://api.z-api.io/instances/UID/token/***/messages?messageId=M1&phone=549',
            $this->prop($this->pushedJob(), 'url'),
        );
    }

    public function test_any_other_logged_url_loses_its_query(): void
    {
        $this->logger()->log([], 'https://graph.facebook.com/v26.0/WABA/message_templates?limit=10&after=CURSOR', []);

        $this->assertSame('https://graph.facebook.com/v26.0/WABA/message_templates', $this->prop($this->pushedJob(), 'url'));
    }

    public function test_the_log_line_context_keeps_no_url_query(): void
    {
        $this->logger()->log(['phone' => '549', 'file' => self::SIGNED, 'error' => self::SIGNED], 'https://uazapi.example.com/send/media', []);

        $this->assertCount(1, $this->logged);
        $this->assertSame('error', $this->logged[0]->level);
        $this->assertSame('wapi-gateway.test.sendFile.error', $this->logged[0]->message);
        $this->assertSame('https://b.s3.amazonaws.com/a/photo.jpg', $this->logged[0]->context['file']);
        $this->assertSame('https://b.s3.amazonaws.com/a/photo.jpg', $this->logged[0]->context['error']);
        $this->assertSame('549', $this->logged[0]->context['phone']);
    }
}
