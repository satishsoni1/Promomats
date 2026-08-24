<?php

namespace App\Listeners\Workflow;

use App\Events\ApprovalAssigned;
use App\Events\ApprovalCompleted;
use App\Events\RevisionRequested;
use App\Events\WorkflowCompleted;
use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use App\Notifications\DocumentActionNotification;
use Illuminate\Events\Dispatcher;

/**
 * The notification side effect of the workflow events - moved out of
 * WorkflowEngine's old private notify()/stakeholderIds() methods so
 * notifications are a listener's job, not the engine's (spec REQ-55).
 * Deliberately not queued: these were synchronous before this refactor
 * (DocumentActionNotification itself is what implements ShouldQueue), so
 * queuing this listener too would change delivery ordering, not just move it.
 */
class SendWorkflowNotifications
{
    public function handleApprovalAssigned(ApprovalAssigned $event): void
    {
        $this->notifyUsers($event->instance, [$event->user->id], 'stage_assigned', $event->stage);
    }

    public function handleApprovalCompleted(ApprovalCompleted $event): void
    {
        $this->notifyUsers(
            $event->instance, $event->instance->stakeholderIds(), 'action_taken',
            $event->stage, $event->actor, $event->decision, $event->comments
        );
    }

    public function handleRevisionRequested(RevisionRequested $event): void
    {
        $this->notifyUsers(
            $event->instance, $event->instance->stakeholderIds(), 'action_taken',
            $event->stage, $event->actor, 'approved_with_changes', $event->comments
        );
    }

    public function handleWorkflowCompleted(WorkflowCompleted $event): void
    {
        $this->notifyUsers($event->instance, $event->instance->stakeholderIds(), 'workflow_completed');
    }

    protected function notifyUsers(
        DocumentWorkflowInstance $instance,
        array $userIds,
        string $notificationEvent,
        $stage = null,
        ?User $actor = null,
        ?string $decision = null,
        ?string $comments = null
    ): void {
        if (empty($userIds)) {
            return;
        }

        $users = User::whereIn('id', $userIds)->where('is_active', true)->get();

        foreach ($users as $user) {
            $user->notify(new DocumentActionNotification(
                document: $instance->document,
                event: $notificationEvent,
                stage: $stage,
                actor: $actor,
                decision: $decision,
                comments: $comments,
            ));
        }
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ApprovalAssigned::class, [self::class, 'handleApprovalAssigned']);
        $events->listen(ApprovalCompleted::class, [self::class, 'handleApprovalCompleted']);
        $events->listen(RevisionRequested::class, [self::class, 'handleRevisionRequested']);
        $events->listen(WorkflowCompleted::class, [self::class, 'handleWorkflowCompleted']);
    }
}
