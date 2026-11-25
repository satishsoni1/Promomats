<?php

use App\Http\Controllers\Api\MobileCatalogController;
use App\Http\Controllers\Api\MobileController;
use App\Http\Controllers\Api\MobileWorkflowController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\UseArraySession;
use Illuminate\Support\Facades\Route;

/*
| API for the VODO mobile app (mobile/, Flutter). Bearer-token auth - see
| App\Models\ApiToken and App\Http\Middleware\AuthenticateApiToken. Actions run the
| web controllers themselves (MobileWorkflowController), so they need a throwaway
| session to flash their result into (UseArraySession).
*/
Route::prefix('v1')->group(function () {
    Route::post('/login', [MobileController::class, 'login'])->middleware('throttle:20,1');

    // Signed, short-lived file links handed out by /documents/{id}.
    Route::get('/files/{version}', [MobileController::class, 'file'])->middleware('signed')->name('api.files.show');
    Route::get('/references/{reference}', [MobileCatalogController::class, 'referenceFile'])->middleware('signed')->name('api.references.show');

    Route::middleware(AuthenticateApiToken::class)->group(function () {
        Route::post('/logout', [MobileController::class, 'logout']);
        Route::get('/me', [MobileController::class, 'me']);
        Route::get('/tasks', [MobileController::class, 'tasks']);
        Route::get('/documents/{document}', [MobileController::class, 'show']);

        Route::get('/create-options', [MobileCatalogController::class, 'createOptions']);
        Route::get('/library', [MobileCatalogController::class, 'library']);
        Route::get('/notifications', [MobileCatalogController::class, 'notifications']);
        Route::get('/helpdesk', [MobileCatalogController::class, 'helpdesk']);

        Route::middleware(UseArraySession::class)->group(function () {
            Route::post('/documents', [MobileWorkflowController::class, 'store']);
            Route::post('/documents/{document}/versions', [MobileWorkflowController::class, 'uploadVersion']);
            Route::post('/documents/{document}/submit', [MobileWorkflowController::class, 'submit']);
            Route::post('/documents/{document}/status', [MobileWorkflowController::class, 'updateStatus']);
            Route::post('/documents/{document}/references', [MobileWorkflowController::class, 'storeReference']);

            Route::post('/documents/{document}/decision', [MobileWorkflowController::class, 'decide']);
            Route::post('/documents/{document}/comments', [MobileWorkflowController::class, 'comment']);
            Route::patch('/documents/{document}/comments/{comment}', [MobileWorkflowController::class, 'updateComment']);
            Route::patch('/documents/{document}/comments/{comment}/resolve', [MobileWorkflowController::class, 'resolveComment']);

            Route::post('/documents/{document}/tasks/{task}/reassign', [MobileWorkflowController::class, 'reassign']);
            Route::post('/documents/{document}/tasks/{task}/due', [MobileWorkflowController::class, 'updateDue']);
            Route::post('/documents/{document}/stages/{stage}/due', [MobileWorkflowController::class, 'updateStageDue']);
            Route::post('/documents/{document}/close', [MobileWorkflowController::class, 'close']);

            Route::post('/documents/{document}/work-tasks', [MobileWorkflowController::class, 'requestWork']);
            Route::post('/documents/{document}/placeholder-assign', [MobileWorkflowController::class, 'assignPlaceholder']);
            Route::post('/work-tasks/{task}/take', [MobileWorkflowController::class, 'takeWork']);
            Route::post('/work-tasks/{task}/assign', [MobileWorkflowController::class, 'assignWork']);
            Route::post('/work-tasks/{task}/complete', [MobileWorkflowController::class, 'completeWork']);
            Route::delete('/work-tasks/{task}', [MobileWorkflowController::class, 'cancelWork']);

            Route::post('/helpdesk', [MobileWorkflowController::class, 'raiseTicket']);
            Route::post('/notifications/read-all', [MobileWorkflowController::class, 'readAllNotifications']);
            Route::post('/notifications/{id}/read', [MobileWorkflowController::class, 'readNotification']);
        });
    });
});
