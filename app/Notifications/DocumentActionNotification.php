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
                               // retrieval_requested | retrieval_completed | retrieval_failed | content_edited
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
                fn ($mail) => $mail->line('The document owner needs to revise the content and upload a new version - the workflow will resume automatically once it\'s resubmitted.')
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
