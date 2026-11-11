<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Used in development and tests: writes texts to the log instead of sending them. */
class LogGateway implements SmsGateway
{
    /** @var array<int, array{to: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $body): ?string
    {
        $this->sent[] = ['to' => $to, 'body' => $body];
        Log::info('SMS (not sent, log driver)', ['to' => $to, 'body' => $body]);

        return 'log-'.Str::random(10);
    }

    public function forwardCallXml(string $to, ?string $whisper = null): string
    {
        return (new TwilioGateway([]))->forwardCallXml($to, $whisper);
    }
}
