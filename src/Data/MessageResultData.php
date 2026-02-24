<?php

namespace Funnelchat\WapiGateway\Data;

class MessageResultData
{
    public function __construct(
        public readonly bool $sent,
        public readonly string $id = '',
        public readonly string $message = '',
        public readonly string $queueNumber = ''
    ) {
    }

    public static function fromZapi(array $payload): self
    {
        return new self(isset($payload['messageId']), $payload['messageId'] ?? '', '', '');
    }

    public static function fromFunapi(array $payload): self
    {
        return self::fromZapi($payload);
    }

    public static function fromMeta(array $payload): self
    {
        $id = $payload['messages'][0]['id'] ?? '';
        return new self(!empty($id), $id, '', '');
    }

    public static function fromUazapi(array $payload): self
    {
        $id = $payload['id'] ?? $payload['messageId'] ?? '';
        return new self(!empty($id), $id, '', '');
    }

    public function toArray(): array
    {
        return [
            'sent' => $this->sent,
            'id' => $this->id,
            'message' => $this->message,
            'queueNumber' => $this->queueNumber,
        ];
    }
}

