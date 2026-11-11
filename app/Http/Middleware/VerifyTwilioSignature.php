<?php

namespace App\Http\Middleware;

use App\Services\Sms\TwilioGateway;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('axiom.sms.driver') === 'twilio' && config('axiom.sms.twilio.validate_signature')) {
            $ok = TwilioGateway::validSignature(
                (string) config('axiom.sms.twilio.auth_token'),
                $request->fullUrl(),
                $request->post(),
                $request->header('X-Twilio-Signature'),
            );
            if (! $ok) {
                abort(403);
            }
        }

        return $next($request);
    }
}
