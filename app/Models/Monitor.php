<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\UptimeMonitor\Models\Monitor as SpatieMonitor;

class Monitor extends SpatieMonitor
{
    public function scopeDomainEnabled($query)
    {
        return $query
            ->where('domain_check_enabled', true)
            ->get();
    }
}
