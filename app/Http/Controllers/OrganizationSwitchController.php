<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $organization = Organization::findOrFail($validated['organization_id']);

        abort_unless(
            $user->isSuperAdmin() || $user->organizations()->whereKey($organization->id)->exists(),
            403
        );

        $request->session()->put('active_organization_id', $organization->id);

        // Land on the org's dashboard rather than back(): the previous page may
        // not depend on the active org (e.g. /organizations, where nothing would
        // visibly change) or may 403 under the new role (e.g. /users after
        // switching to an org where the user is only a member).
        return redirect()->route('monitors.index');
    }
}
