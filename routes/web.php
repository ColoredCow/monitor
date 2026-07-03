<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\Http\Controllers\GroupsController;
use App\Http\Controllers\MonitorsController;
use App\Http\Controllers\OrganizationsController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\UsersController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::permanentRedirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/no-organization', fn () => Inertia::render('NoOrganization'))
        ->name('no-organization');

    Route::post('/organizations/switch', OrganizationSwitchController::class)
        ->name('organizations.switch');

    Route::resource('organizations', OrganizationsController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::post('/organizations/{organization}/restore', [OrganizationsController::class, 'restore'])
        ->name('organizations.restore')
        ->withTrashed();

    Route::middleware('active.organization')->group(function () {
        Route::resource('monitors', MonitorsController::class);
        Route::resource('groups', GroupsController::class);
        Route::resource('users', UsersController::class);
    });
});

require __DIR__.'/auth.php';
