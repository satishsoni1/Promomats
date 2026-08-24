<?php

namespace App\Listeners\Workflow;

use App\Events\ApprovalAssigned;
use App\Events\ApprovalCompleted;
use App\Events\DocumentDistributed;
use App\Events\DocumentResubmitted;
use App\Events\DocumentSubmitted;
use App\Events\RevisionRequested;
use App\Events\SLABreached;
use App\Events\WorkflowCompleted;
use App\Services\Audit\AuditLogger;
use Illuminate\Events\Dispatcher;

/**
 * The audit-log side effect of every workflow event, in one place (an Event
 * Subscriber rather than nine one-method listener classes - see
 * AppServiceProvider::boot() for the registration). Each handle* method here
 * used to be an inline AuditLogger::record() call inside WorkflowEngine
 * itself; moving it here is what makes those calls "Listeners", per spec
 * REQ-55, rather than logic baked into the service.
 */
class LogWorkflowAuditTrail
{
    public function __construct(protected AuditLogger $audit) {}

    public function handleDocumentSubmitted(DocumentSubmitted $event): void
    {
        $this->audit->record(
            action: 'SUBMITTED',
            document: $event->document,
            version: $event->version,
            instance: $event->instance,
            actor: $event->initiator,
            oldStatus: 'draft',
            newStatus: 'in_review',
        );
    }

    public function handleApprovalAssigned(ApprovalAssigned $event): void
    {
        $this->audit->record(
            action: 'ASSIGNED',
            document: $event->instance->document,
            instance: $event->instance,
            stage: $event->stage,
            actor: $event->user,
            description: "Assigned as approver for stage \"{$event->stage->name}\".",
        );
    }

    public function handleApprovalCompleted(ApprovalCompleted $event): void
    {
        $this->audit->record(
            action: $event->decision === 'approved' ? 'APPROVED' : 'REJECTED',
            document: $event->instance->document,
            version: $event->instance->version,
            instance: $event->instance,
            stage: $event->stage,
            actor: $event->actor,
            description: $event->comments,
        );
    }

    public function handleRevisionRequested(RevisionRequested $event): void
    {
        $this->audit->record(
            action: 'REVISION_REQUESTED',
            document: $event->instance->document,
            version: $event->instance->version,
            instance: $event->instance,
            stage: $event->stage,
            actor: $event->actor,
            description: $event->comments,
        );
    }

    public function handleDocumentResubmitted(DocumentResubmitted $event): void
    {
        $resumeStage = $event->instance->resumeAtStage ?? $event->instance->currentStage;

        $this->audit->record(
            action: 'RESUBMITTED',
            document: $event->instance->document,
            version: $event->newVersion,
            instance: $event->instance,
            actor: $event->actor,
            oldStatus: 'approved_with_changes_pending',
            newStatus: 'in_review',
            description: $resumeStage ? "Resumed at stage \"{$resumeStage->name}\" after revision." : null,
        );
    }

    public function handleWorkflowCompleted(WorkflowCompleted $event): void
    {
        $documentStatus = $event->instance->document->status;

        $this->audit->record(
            action: match ($documentStatus) {
                'approved_for_distribution' => 'DISTRIBUTED',
                'rejected' => 'REJECTED',
                default => 'FINAL_APPROVED',
            },
            document: $event->instance->document,
            version: $event->instance->version,
            instance: $event->instance,
            actor: $event->actor,
            oldStatus: $event->previousDocumentStatus,
            newStatus: $documentStatus,
            description: 'Workflow completed.',
        );
    }

    public function handleDocumentDistributed(DocumentDistributed $event): void
    {
        $this->audit->record(
            action: 'DISTRIBUTION_CONFIRMED',
            document: $event->record->document,
            version: $event->record->version,
            actor: $event->actor,
            description: "Distributed via {$event->record->distribution_channel} on {$event->record->distribution_date->toDateString()}.",
        );
    }

    public function handleSLABreached(SLABreached $event): void
    {
        $this->audit->record(
            action: 'SLA_BREACHED',
            document: $event->task->instance->document,
            instance: $event->task->instance,
            stage: $event->task->stage,
            actor: $event->task->user,
            description: "Waiting {$event->task->hoursWaiting()}h against a {$event->task->slaHours()}h SLA.",
        );
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(DocumentSubmitted::class, [self::class, 'handleDocumentSubmitted']);
        $events->listen(ApprovalAssigned::class, [self::class, 'handleApprovalAssigned']);
        $events->listen(ApprovalCompleted::class, [self::class, 'handleApprovalCompleted']);
        $events->listen(RevisionRequested::class, [self::class, 'handleRevisionRequested']);
        $events->listen(DocumentResubmitted::class, [self::class, 'handleDocumentResubmitted']);
        $events->listen(WorkflowCompleted::class, [self::class, 'handleWorkflowCompleted']);
        $events->listen(DocumentDistributed::class, [self::class, 'handleDocumentDistributed']);
        $events->listen(SLABreached::class, [self::class, 'handleSLABreached']);
    }
}
