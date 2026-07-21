<?php

namespace App\Services\Sms;

interface SmsProvider
{
    public function send(array $configuration, string $phone, array $namedParameters, array $orderedParameters): array;
}
