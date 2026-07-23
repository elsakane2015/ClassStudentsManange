<?php

namespace App\Support;

class ParentEmailList
{
    public const MAX_RECIPIENTS = 10;

    public static function parse(?string $value): array
    {
        $parts = preg_split('/[,，]+/u', (string) $value) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $email = trim($part);
            if ($email === '') {
                continue;
            }

            $key = mb_strtolower($email);
            $emails[$key] ??= $email;
        }

        return array_values($emails);
    }

    public static function normalize(?string $value): ?string
    {
        $emails = self::parse($value);

        return $emails ? implode(', ', $emails) : null;
    }

    public static function rules(): array
    {
        return [
            'nullable',
            'string',
            'max:3000',
            function (string $attribute, mixed $value, \Closure $fail): void {
                $emails = self::parse((string) $value);
                if (count($emails) > self::MAX_RECIPIENTS) {
                    $fail('家长邮箱最多填写 '.self::MAX_RECIPIENTS.' 个。');

                    return;
                }

                foreach ($emails as $email) {
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $fail("家长邮箱“{$email}”格式不正确，多个邮箱请使用逗号分隔。");

                        return;
                    }
                }
            },
        ];
    }
}
