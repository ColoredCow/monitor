<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

Route::permanentRedirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('monitors', 'MonitorsController');
    Route::resource('groups', 'GroupsController');
});

require __DIR__ . '/auth.php';
