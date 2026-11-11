<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\Photos\R2Storage;

/** Public, no-login status page for the tenant (link sent by text). */
class StatusController extends Controller
{
    public function show(string $token, R2Storage $r2)
    {
        $wo = WorkOrder::with('technician.user', 'location')->where('status_token', $token)->firstOrFail();
        $photos = $r2->isConfigured()
            ? $wo->photos()->whereNull('purged_at')->get()->map(fn ($p) => $r2->viewUrl($p->object_key, 900))
            : collect();

        return view('status', ['wo' => $wo, 'photos' => $photos]);
    }
}
