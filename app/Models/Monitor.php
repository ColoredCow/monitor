<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\UptimeMonitor\Models\Monitor as SpatieMonitor;

class Monitor extends SpatieMonitor
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
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
}
