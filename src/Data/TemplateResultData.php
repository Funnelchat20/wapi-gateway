<?php

namespace Funnelchat\WapiGateway\Data;

class TemplateResultData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $language,
        public string $category,
        public string $status
    ) {
    }

    public static function fromMeta(array $payload): self
    {
        return new self(
            $payload['id'] ?? '',
            $payload['name'] ?? '',
            $payload['language'] ?? '',
            $payload['category'] ?? '',
            $payload['status'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'status' => $this->status,
        ];
    }
}

