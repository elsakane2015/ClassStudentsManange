<?php

namespace Tests\Unit;

use App\Services\MobilePushService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MobilePushServiceTest extends TestCase
{
    public function test_der_to_jose_es256_converts_valid_der_signature_to_64_bytes(): void
    {
        $service = new MobilePushService();
        $method = new ReflectionMethod(MobilePushService::class, 'derToJoseEs256');

        // Construct a synthetic ASN.1 DER ECDSA signature with 33-byte r (leading 0x00) and 31-byte s
        $r = "\x00" . str_repeat("\x11", 32);
        $s = str_repeat("\x22", 31);
        $body = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
        $der = "\x30" . chr(strlen($body)) . $body;

        $raw = $method->invoke($service, $der);

        $this->assertIsString($raw);
        $this->assertSame(64, strlen($raw));
        $this->assertSame(str_repeat("\x11", 32), substr($raw, 0, 32));
        $this->assertSame("\x00" . str_repeat("\x22", 31), substr($raw, 32, 32));
    }

    public function test_der_to_jose_es256_rejects_malformed_input(): void
    {
        $service = new MobilePushService();
        $method = new ReflectionMethod(MobilePushService::class, 'derToJoseEs256');

        $this->assertNull($method->invoke($service, 'not-a-der-signature'));
    }
}
