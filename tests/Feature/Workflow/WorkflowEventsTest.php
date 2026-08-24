<?php

namespace Tests\Feature\Workflow;

use App\Events\ApprovalAssigned;
use App\Events\ApprovalCompleted;
use App\Events\DocumentFinalApproved;
use App\Events\DocumentResubmitted;
use App\Events\DocumentSubmitted;
use App\Events\RevisionRequested;
use App\Events\WorkflowCompleted;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use App\Services\DocumentVersionService;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Confirms WorkflowEngine actually dispatches the spec REQ-55 events at the
 * right moments (Event::fake() intercepts before any listener runs) - the
 * other Workflow tests confirm the *behavior* the listeners produce; this one
 * confirms the *contract* they're wired against actually gets emitted.
 */
class WorkflowEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_two_stage_lifecycle_dispatches_the_expected_events_in_order(): void
    {
        Event::fake([
            DocumentSubmitted::class, ApprovalAssigned::class, ApprovalCompleted::class,
            RevisionRequested::class, DocumentResubmitted::class, WorkflowCompleted::class,
            DocumentFinalApproved::class,
        ]);
        Storage::fake('documents');

        $admin = User::factory()->create();
        $template = WorkflowTemplate::create(['name' => 'Events', 'code' => 'EVT1', 'is_active' => true, 'created_by' => $admin->id]);
        $stage1 = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 1, 'name' => 'Review', 'code' => 'REVIEW']);
        $stage2 = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 2, 'name' => 'Final', 'code' => 'FINAL']);
        WorkflowTransition::create(['workflow_stage_id' => $stage1->id, 'decision' => 'approved', 'outcome_type' => 'next_stage']);
        WorkflowTransition::create(['workflow_stage_id' => $stage1->id, 'decision' => 'approved_with_changes', 'outcome_type' => 'return_to_stage', 'target_stage_id' => $stage1->id]);
        WorkflowTransition::create(['workflow_stage_id' => $stage2->id, 'decision' => 'approved', 'outcome_type' => 'complete_approved']);

        $reviewer = User::factory()->create();
        $finalApprover = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $reviewer->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $stage2->id, 'user_id' => $finalApprover->id]);

        $owner = User::factory()->create();
        $document = Document::create([
            'title' => 'Events Doc', 'reference_no' => 'REF-' . uniqid(), 'owner_id' => $owner->id,
            'workflow_template_id' => $template->id, 'status' => 'draft',
        ]);
        app(DocumentVersionService::class)->storeNewVersion(
            document: $document, file: UploadedFile::fake()->create('d.pdf', 5, 'application/pdf'), uploader: $owner,
        );
        $document = $document->fresh(['currentVersion']);

        $engine = app(WorkflowEngine::class);
        $instance = $engine->start($document, $document->currentVersion, $owner);

        Event::assertDispatched(DocumentSubmitted::class, fn ($e) => $e->document->is($document) && $e->initiator->is($owner));
        Event::assertDispatched(ApprovalAssigned::class, fn ($e) => $e->user->is($reviewer) && $e->stage->is($stage1));

        // Reviewer sends it back for revision - RevisionRequested, not ApprovalCompleted.
        $instance = $engine->recordDecision($instance, $reviewer, 'approved_with_changes', 'Fix page 2.');
        Event::assertDispatched(RevisionRequested::class, fn ($e) => $e->actor->is($reviewer) && $e->comments === 'Fix page 2.');
        Event::assertNotDispatched(ApprovalCompleted::class);

        // Owner resubmits.
        $v2 = app(DocumentVersionService::class)->storeNewVersion(
            document: $document, file: UploadedFile::fake()->create('d2.pdf', 5, 'application/pdf'), uploader: $owner,
        );
        $engine->resumeAfterRevision($instance, $v2);
        Event::assertDispatched(DocumentResubmitted::class, fn ($e) => $e->newVersion->is($v2) && $e->actor->is($owner));

        // Reviewer approves cleanly this time - ApprovalCompleted, not RevisionRequested.
        $instance = $engine->recordDecision($instance->fresh(), $reviewer, 'approved');
        Event::assertDispatched(ApprovalCompleted::class, fn ($e) => $e->decision === 'approved' && $e->actor->is($reviewer));

        // Final approver clears it - workflow completes to approved_for_distribution.
        $engine->recordDecision($instance, $finalApprover, 'approved');
        Event::assertDispatched(WorkflowCompleted::class, fn ($e) => $e->status === 'approved');
        Event::assertDispatched(DocumentFinalApproved::class, fn ($e) => $e->document->is($document) && $e->actor->is($finalApprover));
    }
}
