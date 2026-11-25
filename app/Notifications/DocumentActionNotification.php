<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Document $document,
        public string $event, // stage_assigned | action_taken | workflow_completed | comment_added |
                               // version_uploaded | claim_inserted | lifecycle_changed | overdue_reminder |
                               // retrieval_requested | retrieval_completed | retrieval_failed | content_edited |
                               // due_soon_reminder | task_reassigned | work_task_created | work_task_assigned |
                               // work_task_completed | revision_needed | draft_aligned |
                               // due_date_changed | closure_pending
        public ?WorkflowStage $stage = null,
        public ?User $actor = null,
        public ?string $decision = null,
        public ?string $comments = null,
    ) {}

    public function via($notifiable): array
    {
        // database powers the in-app notification bell; mail sends real email for every action.
        // Swap/add 'slack', 'broadcast', etc. here as needed.
        return ['database', 'mail'];
    }

    public function toArray($notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'document_title' => $this->document->title,
            'reference_no' => $this->document->reference_no,
            'event' => $this->event,
            'stage' => $this->stage?->name,
            'actor' => $this->actor?->name,
            'decision' => $this->decision,
            'comments' => $this->comments,
            'message' => $this->buildMessage(),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->document->reference_no}] {$this->buildSubject()}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->buildMessage())
            ->when($this->comments, fn ($mail) => $mail->line("Comments: {$this->comments}"))
            ->when(
                $this->event === 'action_taken' && $this->decision === 'approved_with_changes',
                fn ($mail) => $mail->line('It has moved on to the next stage. The Brand Manager can see the requested changes and upload a revised version at any point.')
            )
            ->when(
                $this->event === 'revision_needed',
                fn ($mail) => $mail->line('Open the job to upload the revised file yourself, or assign it to the Content Agency or the Design Team. Once you resubmit, it goes back to the stage that sent it back - or, if it was rejected after TM/AGM, back to TM/AGM and every stage after it again.')
            )
            ->when(
                $this->event === 'draft_aligned',
                fn ($mail) => $mail->line('Assign the creative to the Content Agency or the Design Team (or upload it yourself), then start the review from the job page. Nothing moves on until you do.')
            )
            ->when(
                $this->event === 'stage_assigned' && $this->stage?->parallel_group,
                fn ($mail) => $mail->line("This is part of a parallel review - other reviewers ({$this->parallelTrackNames()}) are looking at it at the same time; you don't need to wait for them.")
            )
            ->action('View Document', url("/documents/{$this->document->id}"))
            ->line('This is an automated notification from the Document Approval System.');
    }

    /**
     * Names of the other stages sharing this stage's parallel_group, for the
     * "you're not the only one reviewing this right now" mail line above.
     */
    protected function parallelTrackNames(): string
    {
        return $this->stage->groupSiblings()
            ->reject(fn ($s) => $s->id === $this->stage->id)
            ->pluck('name')
            ->implode(', ');
    }

    /** Team named in work-task mails: the stage field carries no team, so use the open task's. */
    protected function teamLabel(): string
    {
        return $this->document->workTasks()->latest('id')->first()?->teamLabel() ?? 'Design Team';
    }

    protected function buildSubject(): string
    {
        return match ($this->event) {
            'stage_assigned' => "Action required: {$this->document->title}",
            'action_taken' => "Update: {$this->document->title}",
            'workflow_completed' => "Completed: {$this->document->title}",
            'comment_added' => "New comment on: {$this->document->title}",
            'version_uploaded' => "New version uploaded: {$this->document->title}",
            'claim_inserted' => "Claim inserted: {$this->document->title}",
            'lifecycle_changed' => "Status changed: {$this->document->title}",
            'overdue_reminder' => "⚠ Overdue: {$this->document->title} is still waiting on you",
            'retrieval_requested' => "Cold storage retrieval requested: {$this->document->title}",
            'retrieval_completed' => "Ready: {$this->document->title} has been restored",
            'retrieval_failed' => "⚠ Retrieval failed: {$this->document->title}",
            'content_edited' => "Content edited: {$this->document->title}",
            'due_soon_reminder' => "Due soon: {$this->document->title} needs your review",
            'task_reassigned' => "Action required (reassigned to you): {$this->document->title}",
            'work_task_created' => "{$this->teamLabel()}: new work for {$this->document->title}",
            'work_task_assigned' => "Assigned to you: work on {$this->document->title}",
            'work_task_completed' => "File uploaded - please confirm: {$this->document->title}",
            'revision_needed' => "Action required: revise {$this->document->title}",
            'draft_aligned' => "Draft aligned - assign the creative: {$this->document->title}",
            'due_date_changed' => "Due date changed: {$this->document->title}",
            'closure_pending' => "Final approval & closure needed: {$this->document->title}",
            default => $this->document->title,
        };
    }

    protected function buildMessage(): string
    {
        return match ($this->event) {
            'stage_assigned' => "'{$this->document->title}' is now pending your review at stage '{$this->stage?->name}'.",
            'action_taken' => "{$this->actorLabel()} marked '{$this->document->title}' as " . $this->decisionLabel() . " at stage '{$this->stage?->name}'.",
            'workflow_completed' => "The approval workflow for '{$this->document->title}' has finished with status: {$this->document->status}.",
            'comment_added' => "{$this->actorLabel()} commented on '{$this->document->title}'.",
            'version_uploaded' => "{$this->actorLabel()} uploaded a new version of '{$this->document->title}'.",
            'claim_inserted' => "{$this->actorLabel()} inserted a claim into '{$this->document->title}'.",
            'lifecycle_changed' => "{$this->actorLabel()} changed the status of '{$this->document->title}' to " . $this->document->statusLabel() . '.',
            'overdue_reminder' => "'{$this->document->title}' has been waiting on your review at stage '{$this->stage?->name}' longer than expected.",
            'retrieval_requested' => "{$this->actorLabel()} requested cold storage retrieval for '{$this->document->title}'.",
            'retrieval_completed' => "'{$this->document->title}' has been restored from cold storage and is available again.",
            'retrieval_failed' => "The cold storage retrieval for '{$this->document->title}' failed and needs attention.",
            'content_edited' => "{$this->actorLabel()} edited the content of '{$this->document->title}' directly in the PDF.",
            'due_soon_reminder' => "'{$this->document->title}' is due soon at stage '{$this->stage?->name}'.",
            'task_reassigned' => "{$this->actorLabel()} reassigned stage '{$this->stage?->name}' of '{$this->document->title}' to you.",
            'work_task_created' => "{$this->actorLabel()} sent '{$this->document->title}' to the {$this->teamLabel()}. Anyone in the team can pick it up or assign it to a colleague.",
            'work_task_assigned' => "{$this->actorLabel()} assigned the work on '{$this->document->title}' to you.",
            'work_task_completed' => "{$this->actorLabel()} uploaded a file for '{$this->document->title}'.",
            'draft_aligned' => "{$this->actorLabel()} aligned the draft of '{$this->document->title}' at stage '{$this->stage?->name}'. It is back with you to commission the creative and start the review.",
            'revision_needed' => "'{$this->document->title}' was {$this->decisionLabel()} at stage '{$this->stage?->name}' by {$this->actorLabel()} and is waiting on you to upload the revision.",
            'due_date_changed' => "{$this->actorLabel()} changed the due date of stage '{$this->stage?->name}' on '{$this->document->title}'.",
            'closure_pending' => "The Chairperson has {$this->decisionLabel()} '{$this->document->title}'. It is waiting on your final approval and closure" . ($this->decision === 'approved_with_changes' ? ' - you can upload a new version first.' : '.'),
            default => "There is an update on '{$this->document->title}'.",
        };
    }

    /**
     * Falls back to "System" for automated events (e.g. the archiving policy) that have
     * no human actor, rather than leaving a blank in the sentence.
     */
    protected function actorLabel(): string
    {
        return $this->actor?->name ?? 'System';
    }

    protected function decisionLabel(): string
    {
        return match ($this->decision) {
            'approved' => 'Approved',
            'approved_with_changes' => 'Approved with Changes',
            'not_approved' => 'Not Approved',
            default => 'updated',
        };
    }
}
