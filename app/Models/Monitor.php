<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
}
