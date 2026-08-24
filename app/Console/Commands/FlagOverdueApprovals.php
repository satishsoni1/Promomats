<?php

namespace App\Console\Commands;

use App\Events\SLABreached;
use App\Models\DocumentStageAssignee;
use App\Models\User;
use App\Notifications\DocumentActionNotification;
use Illuminate\Console\Command;

/**
 * The "red flag" job: any pending approval that's been sitting longer than its
 * stage's SLA (or the 48-hour default) gets a one-time reminder sent to the
 * approver, plus an FYI to the document owner - then is marked so it isn't
 * re-notified every run. isOverdue()/the dashboard's Overdue Tasks widget both
 * read the same threshold live, so the red flag shows up immediately even before
 * this command next runs; this command is what turns that into an actual nudge.
 */
class FlagOverdueApprovals extends Command
{
    protected $signature = 'documents:flag-overdue-approvals';
    protected $description = 'Sends a one-time reminder notification for pending approvals that have exceeded their SLA (default 48 hours).';

    public function handle(): int
    {
        $overdue = DocumentStageAssignee::where('status', 'pending')
            ->whereNull('overdue_notified_at')
            ->with(['user', 'stage', 'instance.document.owner'])
            ->get()
            ->filter(fn ($assignee) => $assignee->isOverdue());

        $reminded = 0;

        foreach ($overdue as $assignee) {
            $document = $assignee->instance->document;
            $stage = $assignee->stage;

            if ($assignee->user && $assignee->user->is_active) {
                $assignee->user->notify(new DocumentActionNotification(
                    document: $document,
                    event: 'overdue_reminder',
                    stage: $stage,
                    comments: "Waiting {$assignee->hoursWaiting()}h (SLA {$assignee->slaHours()}h).",
                ));
            }

            $owner = $document->owner;
            if ($owner && $owner->is_active && $owner->id !== $assignee->user_id) {
                $owner->notify(new DocumentActionNotification(
                    document: $document,
                    event: 'overdue_reminder',
                    stage: $stage,
                    comments: "Still waiting on {$assignee->user?->name} after {$assignee->hoursWaiting()}h (SLA {$assignee->slaHours()}h).",
                ));
            }

            $assignee->update(['overdue_notified_at' => now()]);
            SLABreached::dispatch($assignee);
            $reminded++;
        }

        $this->info("Overdue-approval scan complete. Reminders sent: {$reminded}.");
        return self::SUCCESS;
    }
}
