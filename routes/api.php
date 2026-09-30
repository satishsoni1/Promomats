<?php

use App\Http\Controllers\Api\MobileController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
| API for the VODO mobile app (mobile/, Flutter). Bearer-token auth - see
| App\Models\ApiToken and App\Http\Middleware\AuthenticateApiToken.
*/
Route::prefix('v1')->group(function () {
    Route::post('/login', [MobileController::class, 'login'])->middleware('throttle:20,1');

    // Signed, short-lived file links handed out by /documents/{id}.
    Route::get('/files/{version}', [MobileController::class, 'file'])->middleware('signed')->name('api.files.show');

    Route::middleware(AuthenticateApiToken::class)->group(function () {
        Route::post('/logout', [MobileController::class, 'logout']);
        Route::get('/me', [MobileController::class, 'me']);
        Route::get('/tasks', [MobileController::class, 'tasks']);
        Route::get('/documents/{document}', [MobileController::class, 'show']);
        Route::post('/documents/{document}/decision', [MobileController::class, 'decide']);
        Route::post('/documents/{document}/comments', [MobileController::class, 'comment']);
    });
});
