<?php

namespace App\Services\Sms;

use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;
use TencentCloud\Sms\V20210111\SmsClient;

class TencentSmsProvider implements SmsProvider
{
    public function send(array $configuration, string $phone, array $namedParameters, array $orderedParameters): array
    {
        try {
            $credential = new Credential($configuration['secret_id'], $configuration['secret_key']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint('sms.tencentcloudapi.com');
            $httpProfile->setReqTimeout(10);
            $clientProfile = new ClientProfile(ClientProfile::$SIGN_TC3_SHA256, $httpProfile);
            $client = new SmsClient($credential, $configuration['region'], $clientProfile);

            $request = new SendSmsRequest();
            $request->deserialize($this->buildRequestData($configuration, $phone, $orderedParameters));
            $response = $client->SendSms($request);
            $status = $response->SendStatusSet[0] ?? null;

            if ($status?->Code === 'Ok') {
                return [
                    'success' => true,
                    'id' => $status->SerialNo ?: $response->RequestId,
                    'provider' => 'tencent',
                ];
            }

            return [
                'success' => false,
                'error' => trim(($status?->Code ?: 'TencentCloudError').': '.($status?->Message ?: '短信发送失败')),
                'provider' => 'tencent',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'provider' => 'tencent'];
        }
    }

    public function buildRequestData(array $configuration, string $phone, array $orderedParameters): array
    {
        return [
            'PhoneNumberSet' => [$phone],
            'SmsSdkAppId' => $configuration['sdk_app_id'],
            'SignName' => $configuration['sign_name'],
            'TemplateId' => $configuration['template_id'],
            'TemplateParamSet' => array_values($orderedParameters),
        ];
    }
}
