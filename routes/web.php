<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

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
use App\Http\Controllers\UsersController;

Route::permanentRedirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('monitors', MonitorsController::class);
    Route::resource('groups', GroupsController::class);
    Route::resource('users', UsersController::class);
});

require __DIR__.'/auth.php';
