<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TriageWorkOrder;
use App\Models\Location;
use App\Models\Photo;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Photos\R2Storage;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Receives tenant requests from the axiom-properties.com form (see the handoff spec). */
class IntakeController extends Controller
{
    public const CATEGORIES = ['Plumbing', 'Electrical', 'Heating/Cooling', 'Appliance', 'Doors/Locks', 'Pests', 'Other'];

    public function photoUrl(Request $request, R2Storage $r2)
    {
        $data = $request->validate(['content_type' => 'required|in:image/jpeg,image/png,image/heic,image/webp']);
        if (! $r2->isConfigured()) {
            return response()->json(['error' => 'photo storage not configured'], 503);
        }
        $key = $r2->newKey('requests', $data['content_type']);

        return response()->json(['upload_url' => $r2->uploadUrl($key), 'photo_key' => $key, 'expires_in' => 900], 201);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tenant_name' => 'required|string|max:120',
            'phone' => 'required|string|max:30',
            'sms_consent' => 'required|boolean',
            'email' => 'nullable|email|max:160',
            'property_address' => 'required|string|max:200',
            'unit' => 'nullable|string|max:20',
            'category' => 'required|in:'.implode(',', self::CATEGORIES),
            'description' => 'required|string|min:10|max:4000',
            'is_emergency' => 'nullable|boolean',
            'permission_to_enter' => 'required|boolean',
            'pets' => 'nullable|string|max:200',
            'best_times' => 'nullable|string|max:200',
            'photo_keys' => 'nullable|array|max:'.config('axiom.photos.max_per_request'),
            'photo_keys.*' => 'string|max:200|starts_with:requests/',
            'submitted_at' => 'required|date',
            'location_id' => 'nullable|integer',
        ]);

        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            return response()->json(['errors' => ['phone' => ['Enter a 10-digit US phone number.']]], 422);
        }

        $limiterKey = 'intake:'.$phone;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return response()->json(['error' => 'too many requests from this phone number; please call the office'], 429);
        }
        RateLimiter::hit($limiterKey, 3600);

        $location = isset($data['location_id'])
            ? Location::where('rm_location_id', $data['location_id'])->where('enabled', true)->first()
            : Location::enabled()->orderBy('rm_location_id')->first();
        if (! $location) {
            return response()->json(['error' => 'no active service location'], 422);
        }

        $tenant = Tenant::where('location_id', $location->id)->where('phone', $phone)->first();
        if ($tenant) {
            $tenant->update(['sms_consent' => $data['sms_consent'] || $tenant->sms_consent]);
        }
        $property = $tenant?->unit?->property ?? $this->matchProperty($location, $data['property_address']);

        $description = $data['description'];
        if (! empty($data['is_emergency'])) {
            $description = "[Tenant marked this as an emergency]\n".$description;
        }

        $wo = WorkOrder::create([
            'location_id' => $location->id,
            'property_id' => $property?->id,
            'unit_id' => $tenant?->unit_id,
            'tenant_id' => $tenant?->id,
            'source' => 'website',
            'category' => $data['category'],
            'description' => $description,
            'permission_to_enter' => $data['permission_to_enter'],
            'pets' => $data['pets'] ?? null,
            'best_times' => $data['best_times'] ?? null,
            'contact_name' => $data['tenant_name'],
            'contact_phone' => $data['sms_consent'] ? $phone : null,
            'address_text' => $data['property_address'],
            'unit_text' => $data['unit'] ?? null,
            'status' => WorkOrder::STATUS_NEW,
        ]);
        $wo->log('received_from_website', ['matched_tenant' => (bool) $tenant, 'matched_property' => (bool) $property]);

        foreach ($data['photo_keys'] ?? [] as $key) {
            Photo::firstOrCreate(['object_key' => $key], ['work_order_id' => $wo->id, 'kind' => 'request']);
        }

        if (! $tenant) {
            $wo->flagForReview('Could not match this tenant in Rent Manager; check the name and unit');
        }

        TriageWorkOrder::dispatch($wo->id);

        return response()->json(['reference' => $wo->reference, 'status_url' => $wo->statusUrl()], 201);
    }

    private function matchProperty(Location $location, string $address): ?Property
    {
        $street = mb_strtolower(trim(preg_replace('/\s+/', ' ', explode(',', $address)[0])));
        if ($street === '') {
            return null;
        }

        return Property::where('location_id', $location->id)
            ->get()
            ->first(fn (Property $p) => $p->address && str_starts_with(mb_strtolower($p->address), $street));
    }
}
