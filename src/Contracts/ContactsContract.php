<?php

namespace Funnelchat\WapiGateway\Contracts;

interface ContactsContract
{
    public function contact(string $uid, string $token, string $phone): array;
    public function contacts(string $uid, string $token, array $options = []): array;
    public function sendContact(string $uid, string $token, string $to, string $contactName, string $contactPhone, array $options = []): array;
    public function addContacts(string $uid, string $token, array $contacts): array;
}

