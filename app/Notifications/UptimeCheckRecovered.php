<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsChatAlerts;
use Illuminate\Bus\Queueable;
use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckRecovered as SpatieUptimeCheckRecovered;

class UptimeCheckRecovered extends SpatieUptimeCheckRecovered
{
    use FormatsChatAlerts;
    use Queueable;
}
