<?php

namespace App\Services\Sms;

use AlibabaCloud\Dara\Models\RuntimeOptions;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use Darabonba\OpenApi\Models\Config;

class AlibabaSmsProvider implements SmsProvider
{
    public function send(array $configuration, string $phone, array $namedParameters, array $orderedParameters): array
    {
        try {
            $clientConfig = new Config([
                'accessKeyId' => $configuration['access_key_id'],
                'accessKeySecret' => $configuration['access_key_secret'],
            ]);
            $clientConfig->endpoint = 'dysmsapi.aliyuncs.com';

            $client = new Dysmsapi($clientConfig);
            $request = new SendSmsRequest($this->buildRequestData($configuration, $phone, $namedParameters));
            $runtime = new RuntimeOptions([
                'connectTimeout' => 5000,
                'readTimeout' => 10000,
                'autoretry' => false,
            ]);
            $response = $client->sendSmsWithOptions($request, $runtime);
            $body = $response->body;

            if ($body?->code === 'OK') {
                return [
                    'success' => true,
                    'id' => $body->bizId ?: $body->requestId,
                    'provider' => 'aliyun',
                ];
            }

            return [
                'success' => false,
                'error' => trim(($body?->code ?: 'AlibabaCloudError').': '.($body?->message ?: '短信发送失败')),
                'provider' => 'aliyun',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'provider' => 'aliyun'];
        }
    }

    public function buildRequestData(array $configuration, string $phone, array $namedParameters): array
    {
        return [
            'phoneNumbers' => $phone,
            'signName' => $configuration['sign_name'],
            'templateCode' => $configuration['template_code'],
            'templateParam' => json_encode($namedParameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }
}
