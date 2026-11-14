<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WorkOrder extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_OFFERED = 'offered';          // slot options texted to tenant

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_EN_ROUTE = 'en_route';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';      // tech finished; awaiting sync/close

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [
        self::STATUS_NEW, self::STATUS_TRIAGED, self::STATUS_OFFERED, self::STATUS_SCHEDULED,
        self::STATUS_EN_ROUTE, self::STATUS_IN_PROGRESS,
    ];

    protected $attributes = ['status' => self::STATUS_NEW, 'urgency' => 'routine', 'source' => 'manual', 'permission_to_enter' => false];

    protected $fillable = [
        'location_id', 'rm_service_issue_id', 'property_id', 'unit_id', 'tenant_id', 'source', 'category', 'trade',
        'urgency', 'description', 'permission_to_enter', 'pets', 'best_times', 'contact_name', 'contact_phone',
        'address_text', 'unit_text', 'status', 'triage', 'triage_confidence', 'estimated_minutes', 'technician_id',
        'scheduled_start', 'scheduled_end', 'route_position', 'review_reason', 'needs_review_at', 'en_route_at',
        'started_at', 'completed_at', 'closed_at', 'completion_notes', 'labor_minutes', 'tenant_rating', 'rm_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'permission_to_enter' => 'boolean',
            'triage' => 'array',
            'triage_confidence' => 'float',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'needs_review_at' => 'datetime',
            'en_route_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'proposed_start' => 'datetime',
            'closed_at' => 'datetime',
            'rm_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkOrder $wo) {
            $wo->reference ??= static::newReference();
            $wo->status_token ??= Str::random(40);
        });
    }

    public static function newReference(): string
    {
        do {
            $ref = 'AX-'.strtoupper(Str::random(6));
        } while (static::where('reference', $ref)->exists());

        return $ref;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function receipts(): BelongsToMany
    {
        return $this->belongsToMany(Receipt::class);
    }

    public function proposedTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'proposed_technician_id');
    }

    /** Forget any suggested tech/time waiting for approval. */
    public function clearProposal(): void
    {
        $this->forceFill(['proposed_technician_id' => null, 'proposed_start' => null, 'proposed_kind' => null, 'proposed_reason' => null])->save();
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(SlotOffer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function activity(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeNeedsReview($query)
    {
        return $query->whereNotNull('needs_review_at')->whereIn('status', self::OPEN_STATUSES);
    }

    public function isEmergency(): bool
    {
        return $this->urgency === 'emergency';
    }

    public function flagForReview(string $reason): void
    {
        $this->forceFill(['review_reason' => $reason, 'needs_review_at' => now()])->save();
        $this->log('flagged_for_review', ['reason' => $reason]);
    }

    public function clearReview(): void
    {
        $this->forceFill(['review_reason' => null, 'needs_review_at' => null])->save();
    }

    public function log(string $event, array $data = [], ?int $userId = null): void
    {
        $this->activity()->create(['event' => $event, 'data' => $data, 'user_id' => $userId]);
    }

    /** Best known coordinates for routing. */
    public function coordinates(): ?array
    {
        $p = $this->property;

        return ($p && $p->lat !== null && $p->lng !== null) ? [$p->lat, $p->lng] : null;
    }

    public function displayAddress(): string
    {
        $base = $this->property?->fullAddress() ?: (string) $this->address_text;
        $unit = $this->unit?->name ?: $this->unit_text;

        return trim($base.($unit ? ' #'.$unit : ''));
    }

    public function tradeLabel(): string
    {
        return match ($this->trade) {
            null, '' => 'Unsorted',
            'hvac' => 'HVAC',
            default => ucfirst($this->trade),
        };
    }

    public function tenantPhone(): ?string
    {
        return $this->tenant?->phone ?: $this->contact_phone;
    }

    public function statusUrl(): string
    {
        return url('/s/'.$this->status_token);
    }
}
