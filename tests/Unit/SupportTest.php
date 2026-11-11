<?php

namespace Tests\Unit;

use App\Services\Photos\R2Storage;
use App\Services\Sms\TwilioGateway;
use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_phone_normalization(): void
    {
        $this->assertSame('+12085551234', Phone::normalize('(208) 555-1234'));
        $this->assertSame('+12085551234', Phone::normalize('1-208-555-1234'));
        $this->assertSame('+12085551234', Phone::normalize('+1 208 555 1234'));
        $this->assertNull(Phone::normalize('555-1234'));
        $this->assertNull(Phone::normalize(null));
    }

    public function test_r2_presigned_url_shape(): void
    {
        $r2 = new R2Storage(['account_id' => 'acct', 'access_key' => 'AKID', 'secret_key' => 'secret', 'bucket' => 'photos']);
        $url = $r2->presign('PUT', 'jobs/AX-1/a b.jpg', 900, new \DateTimeImmutable('2026-11-11 00:00:00', new \DateTimeZone('UTC')));

        $this->assertStringStartsWith('https://acct.r2.cloudflarestorage.com/photos/jobs/AX-1/a%20b.jpg?', $url);
        $this->assertStringContainsString('X-Amz-Credential=AKID%2F20261111%2Fauto%2Fs3%2Faws4_request', $url);
        $this->assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}$/', $url);
        // deterministic for the same inputs
        $this->assertSame($url, $r2->presign('PUT', 'jobs/AX-1/a b.jpg', 900, new \DateTimeImmutable('2026-11-11 00:00:00', new \DateTimeZone('UTC'))));
    }

    public function test_twilio_signature_check(): void
    {
        $params = ['From' => '+12085551234', 'Body' => 'hi'];
        $sig = base64_encode(hash_hmac('sha1', 'https://x.test/webhooks/sms'.'Bodyhi'.'From+12085551234', 'tok', true));
        $this->assertTrue(TwilioGateway::validSignature('tok', 'https://x.test/webhooks/sms', $params, $sig));
        $this->assertFalse(TwilioGateway::validSignature('tok', 'https://x.test/webhooks/sms', $params, 'bad'));
        $this->assertStringContainsString('<Dial callerId="+1208', (new TwilioGateway(['from' => '+12085550000']))->forwardCallXml('+12085559999'));
    }
}
