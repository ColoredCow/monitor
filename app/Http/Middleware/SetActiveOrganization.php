<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetActiveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $current = app(CurrentOrganization::class);

        $organization = $current->resolveFor($user, $request->session()->get('active_organization_id'));

        if (! $organization) {
            return $user->isSuperAdmin()
                ? redirect()->route('organizations.index')
                : redirect()->route('no-organization');
        }

        // Persist the resolved org so it survives the request and corrects any stale value.
        $request->session()->put('active_organization_id', $organization->id);
        $current->set($organization);

        return $next($request);
    }
}
