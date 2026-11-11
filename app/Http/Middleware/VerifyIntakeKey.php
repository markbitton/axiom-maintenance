<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyIntakeKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('axiom.intake_key');
        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Axiom-Key'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
