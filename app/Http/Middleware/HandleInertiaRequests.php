<?php

namespace App\Http\Middleware;

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
                $active = app(CurrentOrganization::class)->get();

                return [
                    'user' => $user,
                    'isSuperAdmin' => (bool) $user?->isSuperAdmin(),
                    'organizations' => $user
                        ? $user->organizations()->orderBy('name')->get()
                            ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'role' => $o->pivot->role])
                            ->values()
                        : [],
                    'activeOrganization' => $active
                        ? ['id' => $active->id, 'name' => $active->name]
                        : null,
                ];
            },
            'features' => [
                'monitorHistory' => config('monitor-history.enabled'),
            ],
        ]);
    }
}
