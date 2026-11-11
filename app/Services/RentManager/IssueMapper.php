<?php

namespace App\Services\RentManager;

use App\Models\Location;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\WorkOrder;
use App\Support\Phone;
use Illuminate\Support\Arr;

/**
 * Maps Rent Manager service issue JSON to local records and back.
 * Field names follow RM12's ServiceManagerIssue entity; confirm against /Help when the login is available.
 */
class IssueMapper
{
    public function upsertFromRentManager(Location $location, array $issue): ?WorkOrder
    {
        $issueId = Arr::get($issue, 'ServiceManagerIssueID');
        if (! $issueId) {
            return null;
        }

        $property = $this->upsertProperty($location, Arr::get($issue, 'Property', []));
        $unit = $property ? $this->upsertUnit($property, Arr::get($issue, 'Unit', [])) : null;
        $tenant = $this->upsertTenant($location, $unit, Arr::get($issue, 'Tenant', []));

        $existing = WorkOrder::where('location_id', $location->id)->where('rm_service_issue_id', $issueId)->first();
        $isClosed = (bool) Arr::get($issue, 'IsClosed', false);

        if ($existing) {
            if ($isClosed && in_array($existing->status, WorkOrder::OPEN_STATUSES, true)) {
                $existing->update(['status' => WorkOrder::STATUS_CLOSED, 'closed_at' => now()]);
                $existing->log('closed_in_rent_manager');
            }

            return $existing;
        }

        if ($isClosed) {
            return null; // never import already-closed history as new work
        }

        $description = trim(Arr::get($issue, 'Title', '')."\n".Arr::get($issue, 'Description', ''));

        $wo = WorkOrder::create([
            'location_id' => $location->id,
            'rm_service_issue_id' => $issueId,
            'property_id' => $property?->id,
            'unit_id' => $unit?->id,
            'tenant_id' => $tenant?->id,
            'source' => 'rent_manager',
            'category' => Arr::get($issue, 'Category.Name'),
            'description' => $description !== '' ? $description : 'No description provided',
            'permission_to_enter' => (bool) Arr::get($issue, 'PermissionToEnter', false),
            'status' => WorkOrder::STATUS_NEW,
        ]);
        $wo->log('imported_from_rent_manager', ['rm_service_issue_id' => $issueId]);

        return $wo;
    }

    public function toRentManager(WorkOrder $wo): array
    {
        $payload = [
            'Title' => str($wo->description)->limit(60)->toString(),
            'Description' => $wo->description,
            'PropertyID' => $wo->property?->rm_property_id,
            'UnitID' => $wo->unit?->rm_unit_id,
            'TenantID' => $wo->tenant?->rm_tenant_id,
        ];
        if ($wo->rm_service_issue_id) {
            $payload['ServiceManagerIssueID'] = $wo->rm_service_issue_id;
        }
        if ($wo->scheduled_start) {
            $payload['ScheduledDate'] = $wo->scheduled_start->toIso8601String();
        }
        if ($wo->technician) {
            $payload['AssignedToUserName'] = $wo->technician->name;
        }
        if ($wo->completion_notes) {
            $payload['Resolution'] = $wo->completion_notes;
        }
        if ($wo->status === WorkOrder::STATUS_CLOSED || $wo->status === WorkOrder::STATUS_COMPLETED) {
            $payload['IsClosed'] = $wo->status === WorkOrder::STATUS_CLOSED;
            $payload['CompletedDate'] = optional($wo->completed_at)->toIso8601String();
        }

        return array_filter($payload, fn ($v) => $v !== null);
    }

    private function upsertProperty(Location $location, array $p): ?Property
    {
        if (! ($id = Arr::get($p, 'PropertyID'))) {
            return null;
        }
        $addr = Arr::get($p, 'Addresses.0', []);

        return Property::updateOrCreate(
            ['location_id' => $location->id, 'rm_property_id' => $id],
            array_filter([
                'name' => Arr::get($p, 'Name', 'Property '.$id),
                'address' => Arr::get($addr, 'Street'),
                'city' => Arr::get($addr, 'City'),
                'state' => Arr::get($addr, 'State'),
                'zip' => Arr::get($addr, 'PostalCode'),
            ], fn ($v) => $v !== null)
        );
    }

    private function upsertUnit(Property $property, array $u): ?Unit
    {
        if (! ($id = Arr::get($u, 'UnitID'))) {
            return null;
        }

        return Unit::updateOrCreate(
            ['property_id' => $property->id, 'rm_unit_id' => $id],
            ['name' => Arr::get($u, 'Name', (string) $id)]
        );
    }

    private function upsertTenant(Location $location, ?Unit $unit, array $t): ?Tenant
    {
        if (! ($id = Arr::get($t, 'TenantID'))) {
            return null;
        }
        $phone = null;
        foreach (Arr::get($t, 'Contacts.0.PhoneNumbers', []) as $number) {
            if (stripos((string) Arr::get($number, 'PhoneNumberType.Name', ''), 'cell') !== false || ! $phone) {
                $phone = Arr::get($number, 'PhoneNumber');
            }
        }

        return Tenant::updateOrCreate(
            ['location_id' => $location->id, 'rm_tenant_id' => $id],
            array_filter([
                'unit_id' => $unit?->id,
                'name' => Arr::get($t, 'Name', trim(Arr::get($t, 'FirstName', '').' '.Arr::get($t, 'LastName', ''))),
                'phone' => Phone::normalize($phone),
                'email' => Arr::get($t, 'Contacts.0.Email'),
            ], fn ($v) => $v !== null && $v !== '')
        );
    }
}
