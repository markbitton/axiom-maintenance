<?php

namespace App\Services\Sms;

interface SmsGateway
{
    /** Send a text from the Axiom number. Returns the provider's message id, if any. */
    public function send(string $to, string $body): ?string;

    /** Forward an incoming call to another number while showing the Axiom number. Returns TwiML-like XML. */
    public function forwardCallXml(string $to, ?string $whisper = null): string;
}
