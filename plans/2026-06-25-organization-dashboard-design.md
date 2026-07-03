# Organization Dashboard — Design Spec

**Issue:** [#23 — Organization dashboard](https://github.com/ColoredCow/monitor/issues/23)
**Date:** 2026-06-25
**Status:** Design approved → ready for implementation plan

## 1. Problem & goal

Today the app is single-tenant-but-shared: any authenticated user sees **every**
monitor and group. `MonitorsController::index()` loads all groups and all ungrouped
monitors globally; `User` has no relationship to anything.

Issue #23 asks for:
- Onboarding organizations, each with its own separate dashboard.
- Each organization having one or more users.

This spec introduces an **`Organization`** tenant boundary, scopes the dashboard and
all monitor/group data to it, and adds a role-based access model — **without breaking
the background uptime/certificate/domain checks**, which run in a console context.

## 2. Decisions (from brainstorming)

| Topic | Decision |
|---|---|
| Operating model | **Hybrid** — platform super-admins onboard orgs and seed an org-admin; the org then self-manages. |
| User ↔ org | **Many-to-many** via an `organization_user` membership pivot, with a per-membership role; the "active" org lives in the session and is changed via a switcher. |
| Roles | **org-admin** (manage monitors, groups, users) and **member** (view-only). A platform **super-admin** sits above all orgs. |
| Org ownership | **Direct `organization_id`** on both `monitors` and `groups`. Group stays an *optional* sub-grouping within an org. |
| Dashboard routing | One set of routes scoped to a **session-held active org** + nav switcher (no slug/subdomain). |
| User onboarding | Admins **create users directly** (or attach an existing account). Open `/register` is **disabled**. |
| Existing data | **Migrate into a default "ColoredCow" org**; make `organization_id` required after backfill. |
| Scoping enforcement | **Explicit `forOrganization()` scope + middleware-resolved current org + scoped route-model binding + policies.** **No global scope** (keeps console paths unscoped). |
| Backfill roles | Existing users all become **admins** of the default org (preserve current abilities). |
| Remove user | **Detaches** the org membership only; never hard-deletes the account. |
| Org settings | Org-admin **can rename their own org**; super-admin does onboarding/create and sees all orgs. |
| Org deletion | **Out of scope for v1.** |

## 3. The critical constraint

Spatie's scheduled uptime/certificate checks **and** our own console commands
(`CheckDomainExpiration`, `BackfillMonitorCheckHistory`, `PruneMonitorCheckHistory`,
`AggregateMonitorCheckMetrics`) enumerate monitors through
`MonitorRepository::query()` in a **console context with no HTTP session**.

> A tenant scope keyed on `session('active_organization_id')` would silently filter
> every background check down to **zero monitors** — monitoring would quietly stop.

**Therefore: no global scope on `Monitor`/`Group`.** Scoping is applied *explicitly* on
web read/write paths only. Console paths query the models unscoped and naturally see
all monitors across all orgs. The background commands and services
(`MonitorCheckLogService`, `DomainService`, the event listeners, notifications) need
**no changes**.

## 4. Data model

```
organizations
  id, name, slug (unique), timestamps

organization_user                      -- membership pivot (the many-to-many)
  id,
  organization_id  -> organizations (cascade on delete)
  user_id          -> users (cascade on delete)
  role             enum('admin','member')
  timestamps
  UNIQUE(organization_id, user_id)

users
  + is_super_admin  boolean  default false      -- platform tier

monitors
  + organization_id -> organizations  (indexed; NOT NULL after backfill)
  group_id  stays nullable (ungrouped monitors still allowed)

groups
  + organization_id -> organizations  (indexed; NOT NULL after backfill)
```

- **Check logs & daily metrics get no new column.** They are only ever reached through
  a monitor (`$monitor->checkLogs()`, `$monitor->dailyCheckMetrics()`), so they inherit
  tenancy from their monitor. Aggregation/pruning commands keep running cross-org.
- **Same-org group constraint:** a monitor's `group_id` must reference a group in the
  *same* organization. Enforced in `MonitorRequest` via an org-scoped `Rule::exists`.

## 5. Models & scoping primitive

- **`Organization`** — `hasMany(Monitor)`, `hasMany(Group)`,
  `belongsToMany(User)->withPivot('role')`; helpers `admins()`, `members()`.
- **`BelongsToOrganization` trait** (used by `Monitor` and `Group`):
  - `organization()` — `belongsTo(Organization)`.
  - `scopeForOrganization($query, $organizationId)`.
  - a `creating` model hook that auto-fills `organization_id` from the bound
    **current organization** when it isn't explicitly set.
- **`User`** — `belongsToMany(Organization)->withPivot('role')`, `isSuperAdmin()`,
  `hasRoleInOrganization($org, $role)`.
- **`CurrentOrganization`** — a request-scoped container binding holding the resolved
  active organization. Controllers, the create-hook, and route-model binding read from
  it. In console nothing is bound → nothing scopes → background checks see all monitors.

`Monitor` already extends `Spatie\UptimeMonitor\Models\Monitor`; the trait composes
cleanly with its existing constructor/casts.

## 6. Active-org resolution & switching

- **Middleware `SetActiveOrganization`** (web group, after `auth`):
  1. Read `session('active_organization_id')`.
  2. Validate the user is still a member (or is super-admin); if stale, fall back to the
     user's first membership; if none, route to the no-org state (§9).
  3. Bind the resolved org into the container as `CurrentOrganization`.
  4. Make it available to Inertia shared props.
- **On login** (`AuthenticatedSessionController::store`, after `session()->regenerate()`):
  set `active_organization_id` to the user's first membership.
- **Switcher endpoint:** `POST /switch-organization` — validates membership (403 if not a
  member and not super-admin) → updates the session → redirects back.
- **Switcher UI:** reuse the existing `Dropdown` in
  `resources/js/Layouts/Authenticated.jsx`; render only when the user belongs to >1 org
  or is a super-admin. Add to mobile nav for parity.
- **Shared Inertia props** (`HandleInertiaRequests::share()`): add
  `auth.organizations` (id, name, role for current user), `auth.activeOrganization`
  (id, name), and `auth.isSuperAdmin`.

## 7. Authorization

- **`Gate::before`** → super-admins pass all checks.
- **`MonitorPolicy` / `GroupPolicy`:** `viewAny` / `view` for any member of the active
  org; `create` / `update` / `delete` for **admin only** (members are read-only). `view`
  additionally requires the model to belong to the active org.
- **`UserPolicy`** (org user management): admin only.
- **`OrganizationPolicy`:** `create` (onboarding) super-admin only; `update` (rename)
  allowed for an admin of that org (and super-admin).
- **Scoped route-model binding** for `monitor` and `group` in
  `AppServiceProvider::boot()` → resolve via `forOrganization(currentOrgId)->findOrFail()`
  so a cross-org URL returns **404**.
- Controllers' `index`/`create`/`edit` call `forOrganization()` explicitly (no reliance
  on implicit scoping).

## 8. Onboarding & user management

- **Super-admin onboarding** — new `OrganizationsController` (super-admin gated):
  - `index` — list all organizations.
  - onboarding screen — create an organization **and** its first admin user
    (name / email / password) in one step.
- **Org-admin user management** — existing `UsersController` becomes org-scoped:
  - `index` — list the active org's members (via the pivot).
  - "add user" — **find-or-create by email, then attach a membership with a role.** An
    email that already exists in another org is *linked* (membership added), not rejected
    by the unique constraint.
  - `update` — edit users within the active org.
  - `destroy` — **detach** the org membership only; the account persists (it may belong to
    other orgs).
- **Org settings** — org-admins can rename their own org (name/slug).
- **Registration disabled** — remove `GET`/`POST /register` routes and the UI link; entry
  is only via super-admin onboarding or an admin adding a user.

## 9. Edge cases & error handling

- **No-org user** (non-super-admin with zero memberships): friendly "You're not in any
  organization — contact your administrator" page; app routes blocked by middleware.
- **Stale active org** (user removed from the org): middleware re-validates every request
  and falls back to another membership or the no-org page.
- **Switch to a non-member org:** 403.
- **Cross-org group on a monitor:** validation error (org-scoped `exists` rule).
- **Super-admin with no active org selected:** may browse all orgs (onboarding/list); to
  use monitor/group screens they pick an org via the switcher, which then scopes them like
  a member of it.

## 10. Migration & backfill (ordered)

1. **Schema migration A:** create `organizations` and `organization_user`; add
   `is_super_admin` to `users`; add **nullable** `organization_id` to `monitors` and
   `groups` (with index + FK).
2. **Data migration B:** create the default org **"ColoredCow"** (slug `coloredcow`); set
   `organization_id` on every existing monitor and group to it; attach **all** existing
   users as **admins** of it; mark the configured default user
   (`config('constants.default.user.email')`) as `is_super_admin`.
3. **Schema migration C:** make `monitors.organization_id` and `groups.organization_id`
   **NOT NULL**.

(Ordering matters: B must run between A and C so the NOT NULL constraint never sees null
rows.)

## 11. Testing (TDD)

- **Factories:** `OrganizationFactory`; `Monitor` and `Group` factories gain a
  `forOrganization()` state; `User` gets membership helpers / states.
- **`TestCase` helpers:** `actingAsAdmin($org)`, `actingAsMember($org)`,
  `actingAsSuperAdmin()`, `createOrganization()`.
- **Feature tests:**
  - **Tenant isolation:** a member of org A cannot see org B's monitors/groups in `index`,
    and opening B's monitor/group URL returns **404**.
  - **Role enforcement:** a member gets **403** on monitor/group/user create/update/delete;
    an admin succeeds.
  - **Switcher:** switching changes the visible data; switching to a non-member org → 403.
  - **Onboarding:** a super-admin creates an org + first admin; a non-super-admin is
    blocked.
  - **User management:** adding a user whose email already exists attaches a membership
    (no unique-email error); removing a user detaches the membership and keeps the account.
  - **Auth:** `/register` returns 404; login sets `active_organization_id`; a no-org user
    hits the no-org page.
- **Critical regression test:** a console command (e.g. the uptime or domain check) run
  with **no session** still enumerates monitors across **all** organizations.
- **Update existing tests:** MonitorHistory feature tests seed their monitors inside a
  default org; `RegistrationTest` is updated/removed (now expects 404).

## 12. Out of scope (v1)

- Organization deletion.
- Email-invite onboarding flow (admins create users directly for now).
- Slug- or subdomain-based dashboard URLs.
- Per-org notification routing (Google Chat / mail) — checks and notifications remain as
  they are today.
- A granular editor/viewer tier beyond admin/member.
