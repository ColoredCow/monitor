<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\UptimeMonitor\Models\Monitor as SpatieMonitor;

class Monitor extends SpatieMonitor
{
    public function __construct()
    {
        parent::__construct();
        $this->casts = array_merge($this->casts, [
            'domain_expires_at' => 'datetime',
        ]);
    }

    public function scopeDomainCheckEnabled(Builder $query): Collection
    {
        return $query
            ->where('domain_check_enabled', true)
            ->get();
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function checkLogs(): HasMany
    {
        return $this->hasMany(MonitorCheckLog::class);
    }

    public function dailyCheckMetrics(): HasMany
    {
        return $this->hasMany(MonitorDailyCheckMetric::class);
    }
}
