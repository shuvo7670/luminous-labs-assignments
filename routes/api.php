<?php

use App\Http\Controllers\UpcomingEventController;
use Illuminate\Support\Facades\Route;

/*
 * Intentionally unauthenticated: the application has no auth system and the
 * ticket does not define one. Marlow must confirm public access before release.
 */
Route::get('/events/upcoming', UpcomingEventController::class)->name('events.upcoming');
