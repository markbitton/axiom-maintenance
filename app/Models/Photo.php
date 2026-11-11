<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    protected $fillable = [
        'work_order_id', 'object_key', 'kind', 'content_type', 'size_bytes', 'uploaded_by_user_id',
        'rm_attachment_id', 'archived_to_rm_at', 'purged_at',
    ];

    protected function casts(): array
    {
        return ['archived_to_rm_at' => 'datetime', 'purged_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
