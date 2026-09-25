<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUsageDaily extends Model
{
    protected $table = 'credit_usage_daily';

    protected $fillable = [
        'organization_id',
        'monitor_id',
        'check_type',
        'date',
        'credits',
    ];

    protected $casts = [
        'date' => 'date',
        'credits' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
