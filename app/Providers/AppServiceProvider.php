<?php

namespace App\Providers;

use App\Listeners\LogCertificateCheckFailed;
use App\Listeners\LogCertificateCheckSucceeded;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\UptimeMonitor\Events\CertificateCheckFailed;
use Spatie\UptimeMonitor\Events\CertificateCheckSucceeded;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Event::listen(Registered::class, SendEmailVerificationNotification::class);
        Event::listen(CertificateCheckSucceeded::class, LogCertificateCheckSucceeded::class);
        Event::listen(CertificateCheckFailed::class, LogCertificateCheckFailed::class);
    }
}
