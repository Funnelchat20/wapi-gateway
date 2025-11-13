<?php

namespace Funnelchat\WapiGateway\Contracts;

interface TemplatesContract
{
    public function listTemplates(string $wabaId, string $token, array $params = []): array;
    public function getTemplate(string $wabaId, string $token, string $name): array;
    public function createTemplate(string $wabaId, string $token, array $data): array;
    public function updateTemplate(string $templateUid, string $token, array $data): array;
    public function deleteTemplate(string $wabaId, string $token, string $name, string $uid): array;
    public function uploadHeaderHandle(string $token, string $fileKey): array;
}

