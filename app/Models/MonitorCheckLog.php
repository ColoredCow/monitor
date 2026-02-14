<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorCheckLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitor_id',
        'check_type',
        'status',
        'checked_at',
        'response_time_ms',
        'message',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'response_time_ms' => 'integer',
        'metadata' => 'array',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function scopeForMonitor(Builder $query, int|Monitor $monitor): Builder
    {
        $monitorId = $monitor instanceof Monitor ? $monitor->id : $monitor;

        return $query->where('monitor_id', $monitorId);
    }

    public function scopeOfType(Builder $query, string $checkType): Builder
    {
        return $query->where('check_type', $checkType);
    }

    public function scopeOfStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeBetweenCheckedAt(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('checked_at', [$from, $to]);
    }
}
