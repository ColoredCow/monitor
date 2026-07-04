<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const TYPE_GRANT = 'grant';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_USAGE_DEBIT = 'usage_debit';

    protected $fillable = [
        'organization_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
