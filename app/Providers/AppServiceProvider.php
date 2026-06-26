<?php

namespace App\Providers;

use App\Listeners\LogCertificateCheckFailed;
use App\Listeners\LogCertificateCheckSucceeded;
use App\Models\Group;
use App\Models\Monitor;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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
        $this->app->singleton(CurrentOrganization::class);
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

        // {monitor} and {group} are ALWAYS behind the active.organization group,
        // so a missing org here is a bug -> 404. We resolve the org INSIDE the
        // binding (not relying on a bound CurrentOrganization) because the
        // SubstituteBindings middleware runs before SetActiveOrganization.
        $resolveOrganizationId = function (): int {
            $user = request()->user();
            abort_if($user === null, 404);

            $current = app(CurrentOrganization::class);
            $organization = $current->get()
                ?? $current->resolveFor($user, session('active_organization_id'));
            abort_if($organization === null, 404);

            return $organization->id;
        };

        Route::bind('monitor', fn ($value) => Monitor::forOrganization($resolveOrganizationId())->findOrFail($value));
        Route::bind('group', fn ($value) => Group::forOrganization($resolveOrganizationId())->findOrFail($value));

        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        Gate::define('manage-organizations', fn (User $user) => false);

        Gate::define('manage-org-users', function (User $user) {
            $organization = app(CurrentOrganization::class)->get();

            return $organization !== null && $user->isAdminOf($organization);
        });
    }
}
