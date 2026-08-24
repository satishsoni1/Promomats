<?php

namespace App\Providers;

use App\Events\DocumentFinalApproved;
use App\Listeners\Workflow\CreatePendingDistributionRecord;
use App\Listeners\Workflow\LogWorkflowAuditTrail;
use App\Listeners\Workflow\SendWorkflowNotifications;
use App\Models\ClaimCandidate;
use App\Models\RetrievalRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicit rather than relying on event auto-discovery (spec REQ-55):
        // deterministic, and doesn't depend on the discovery cache being fresh.
        // LogWorkflowAuditTrail and SendWorkflowNotifications are Event
        // Subscribers (one class, several handle* methods) since they each
        // react to most of the same events - see their subscribe() methods.
        Event::subscribe(LogWorkflowAuditTrail::class);
        Event::subscribe(SendWorkflowNotifications::class);
        Event::listen(DocumentFinalApproved::class, CreatePendingDistributionRecord::class);

        View::composer('admin._nav', function ($view) {
            // Guarded with Schema::hasTable() so this composer never breaks a fresh
            // install before migrations have run.
            $view->with(
                'pendingClaimCandidates',
                Schema::hasTable('claim_candidates') ? ClaimCandidate::where('status', 'pending')->count() : 0
            );
            $view->with(
                'pendingRetrievalRequests',
                Schema::hasTable('retrieval_requests') ? RetrievalRequest::whereIn('status', ['pending', 'in_progress'])->count() : 0
            );
        });
    }
}
