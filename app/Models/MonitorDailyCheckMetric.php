<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorDailyCheckMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitor_id',
        'check_type',
        'date',
        'timezone',
        'total_checks',
        'successful_checks',
        'warning_checks',
        'failed_checks',
        'success_ratio',
        'worst_status',
        'avg_response_time_ms',
        'p95_response_time_ms',
        'computed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'total_checks' => 'integer',
        'successful_checks' => 'integer',
        'warning_checks' => 'integer',
        'failed_checks' => 'integer',
        'success_ratio' => 'decimal:2',
        'avg_response_time_ms' => 'integer',
        'p95_response_time_ms' => 'integer',
        'computed_at' => 'datetime',
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

    public function scopeForTimezone(Builder $query, string $timezone): Builder
    {
        return $query->where('timezone', $timezone);
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }
}
