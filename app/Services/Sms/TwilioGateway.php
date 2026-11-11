<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Twilio over its REST API (no SDK needed on shared hosting).
 * Every message goes out from the one Axiom number, so tenants never see a tech's personal number.
 */
class TwilioGateway implements SmsGateway
{
    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('axiom.sms.twilio') + ['from' => config('axiom.sms.from')]);
    }

    public function send(string $to, string $body): ?string
    {
        $sid = $this->config['account_sid'] ?? null;
        if (! $sid || empty($this->config['auth_token']) || empty($this->config['from'])) {
            throw new RuntimeException('Twilio is not configured (TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, SMS_FROM_NUMBER).');
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $this->config['auth_token'])
            ->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $to,
                'From' => $this->config['from'],
                'Body' => $body,
                'StatusCallback' => url('/webhooks/sms/status'),
            ])
            ->throw();

        return $response->json('sid');
    }

    public function forwardCallXml(string $to, ?string $whisper = null): string
    {
        $say = $whisper ? '<Say>'.htmlspecialchars($whisper, ENT_XML1).'</Say>' : '';
        $callerId = htmlspecialchars((string) ($this->config['from'] ?? config('axiom.sms.from')), ENT_XML1);

        return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$say
            .'<Dial callerId="'.$callerId.'" timeout="25"><Number>'.htmlspecialchars($to, ENT_XML1).'</Number></Dial></Response>';
    }

    /** Verify X-Twilio-Signature (HMAC-SHA1 of URL + sorted POST params). */
    public static function validSignature(string $authToken, string $url, array $params, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $authToken, true)), $signature);
    }
}
