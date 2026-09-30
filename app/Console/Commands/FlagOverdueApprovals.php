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
    protected $description = 'Emails a one-time "due soon" reminder before each pending approval is due, and a one-time overdue alert once it passes its due date (default 48 hours per stage).';

    public function handle(): int
    {
        $dueSoon = $this->sendDueSoonReminders();

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
                    comments: 'It was due ' . $assignee->dueAt()->format('d M Y, H:i') . " (waiting {$assignee->hoursWaiting()}h).",
                ));
            }

            $owner = $document->owner;
            if ($owner && $owner->is_active && $owner->id !== $assignee->user_id) {
                $owner->notify(new DocumentActionNotification(
                    document: $document,
                    event: 'overdue_reminder',
                    stage: $stage,
                    comments: "Still waiting on {$assignee->user?->name} - it was due " . $assignee->dueAt()->format('d M Y, H:i') . '.',
                ));
            }

            $assignee->update(['overdue_notified_at' => now()]);
            SLABreached::dispatch($assignee);
            $reminded++;
        }

        $this->info("Due-date scan complete. Due-soon reminders: {$dueSoon}. Overdue alerts: {$reminded}.");
        return self::SUCCESS;
    }

    /**
     * One reminder per task, sent once it's within the reminder window
     * (config promomats.due_dates.reminder_hours_before, default 12h) of its due date.
     */
    protected function sendDueSoonReminders(): int
    {
        $tasks = DocumentStageAssignee::where('status', 'pending')
            ->whereNull('due_soon_notified_at')
            ->whereNull('overdue_notified_at')
            ->with(['user', 'stage', 'instance.document'])
            ->get()
            ->filter(fn ($assignee) => $assignee->isDueSoon());

        foreach ($tasks as $assignee) {
            if ($assignee->user && $assignee->user->is_active) {
                $assignee->user->notify(new DocumentActionNotification(
                    document: $assignee->instance->document,
                    event: 'due_soon_reminder',
                    stage: $assignee->stage,
                    comments: 'Due by ' . $assignee->dueAt()->format('d M Y, H:i') . '.',
                ));
            }
            $assignee->update(['due_soon_notified_at' => now()]);
        }

        return $tasks->count();
    }
}
