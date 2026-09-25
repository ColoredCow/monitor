<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\CreditRunwayService;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     *
     * @return string|null
     */
    public function version(Request $request)
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array
     */
    public function share(Request $request)
    {
        // `auth` is a CLOSURE so Inertia resolves it at response-render time —
        // i.e. AFTER SetActiveOrganization has run and bound CurrentOrganization.
        // (share() itself runs early in the middleware pipeline, before route
        // middleware, so reading the resolved org eagerly here would be null.)
        return array_merge(parent::share($request), [
            'auth' => function () use ($request) {
                $user = $request->user();
                $current = app(CurrentOrganization::class);
                $active = $current->get();

                // Pages outside the active.organization middleware (e.g. the
                // organizations screens) never bind CurrentOrganization, so the
                // switcher label would always read "Select organization" there.
                // Resolve from the session so the active org shows everywhere.
                if (! $active && $user && ($sessionOrgId = $request->session()->get('active_organization_id'))) {
                    $active = $current->resolveFor($user, $sessionOrgId);
                }

                return [
                    'user' => $user,
                    'isSuperAdmin' => (bool) $user?->isSuperAdmin(),
                    // UI gate for admin-only affordances (create/edit/delete,
                    // Users tab). The server still enforces via policies/gates;
                    // this only controls what the frontend renders.
                    'isOrgAdmin' => (bool) ($user && ($user->isSuperAdmin()
                        || ($active && $user->isAdminOf($active)))),
                    // Super-admins may switch to ANY org (the switch endpoint and
                    // resolveFor both allow it), so their switcher lists all orgs,
                    // not just memberships.
                    'organizations' => $user
                        ? ($user->isSuperAdmin()
                            ? Organization::orderBy('name')->get()
                                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'role' => null])
                                ->values()
                            : $user->organizations()->orderBy('name')->get()
                                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'role' => $o->pivot->role])
                                ->values())
                        : [],
                    'activeOrganization' => $active
                        ? ['id' => $active->id, 'name' => $active->name]
                        : null,
                    // One indexed 4-column query per request; the runway is
                    // config-derived and computed on read, so monitor edits
                    // are reflected on the very next Inertia response.
                    'credits' => $active ? [
                        'balance' => $active->credit_balance,
                        'dailyBurn' => app(CreditRunwayService::class)->dailyBurnFor($active),
                        'warningLevel' => $active->credit_warning_level,
                    ] : null,
                ];
            },
            'features' => [
                'monitorHistory' => config('monitor-history.enabled'),
            ],
        ]);
    }
}
