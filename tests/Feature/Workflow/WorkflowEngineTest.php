<?php

namespace Tests\Feature\Workflow;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use App\Services\DocumentVersionService;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Exercises the generic, database-driven workflow engine directly (bypassing HTTP)
 * against hand-built templates, rather than the seeded Pharma workflows - this pins
 * down the engine's actual contract (sequential vs. any_one vs. all_required,
 * revision loop, double-acting prevention) independent of any one reference
 * workflow's shape.
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    protected WorkflowEngine $engine;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(WorkflowEngine::class);
        $this->admin = User::factory()->create();
        Storage::fake('documents');
    }

    protected function makeTemplate(string $name, string $code): WorkflowTemplate
    {
        return WorkflowTemplate::create([
            'name' => $name,
            'code' => $code,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function makeDocument(WorkflowTemplate $template, ?User $owner = null): Document
    {
        $owner ??= User::factory()->create();

        $document = Document::create([
            'title' => 'Test Document',
            'reference_no' => 'REF-' . uniqid(),
            'owner_id' => $owner->id,
            'workflow_template_id' => $template->id,
            'status' => 'draft',
        ]);

        app(DocumentVersionService::class)->storeNewVersion(
            document: $document,
            file: UploadedFile::fake()->create('artwork.pdf', 10, 'application/pdf'),
            uploader: $owner,
            changeNotes: 'Initial upload',
        );

        return $document->fresh(['currentVersion']);
    }

    protected function stageFor(WorkflowTemplate $template, int $seq, string $name, string $mode = 'any_one'): WorkflowStage
    {
        return WorkflowStage::create([
            'workflow_template_id' => $template->id,
            'sequence_no' => $seq,
            'name' => $name,
            'code' => strtoupper(str_replace(' ', '_', $name)),
            'approval_mode' => $mode,
            'is_final_distribution_stage' => false,
        ]);
    }

    protected function approvedTransition(WorkflowStage $from, ?WorkflowStage $to): void
    {
        WorkflowTransition::create([
            'workflow_stage_id' => $from->id,
            'decision' => 'approved',
            'outcome_type' => $to ? 'next_stage' : 'complete_approved',
            'target_stage_id' => $to?->id,
        ]);
    }

    // --- Sequential, single-approver stages ------------------------------------

    public function test_sequential_two_stage_workflow_completes_to_distribution(): void
    {
        $template = $this->makeTemplate('Seq', 'SEQ');
        $stage1 = $this->stageFor($template, 1, 'Content Manager Review');
        $stage2 = $this->stageFor($template, 2, 'Final Approval');
        $this->approvedTransition($stage1, $stage2);
        $this->approvedTransition($stage2, null); // complete_approved -> approved_for_distribution

        $approver1 = User::factory()->create();
        $approver2 = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $approver1->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $stage2->id, 'user_id' => $approver2->id]);

        $document = $this->makeDocument($template);
        $instance = $this->engine->start($document, $document->currentVersion, $document->owner);

        $this->assertSame($stage1->id, $instance->fresh()->current_stage_id);

        $instance = $this->engine->recordDecision($instance, $approver1, 'approved');
        $this->assertSame($stage2->id, $instance->current_stage_id);
        $this->assertSame('running', $instance->status);

        $instance = $this->engine->recordDecision($instance, $approver2, 'approved');
        $this->assertSame('approved', $instance->status);
        $this->assertSame('approved_for_distribution', $document->fresh()->status);
    }

    // --- ANY_ONE: first decision resolves the stage immediately -----------------

    public function test_any_one_stage_resolves_on_first_decision_even_with_other_approvers_still_pending(): void
    {
        $template = $this->makeTemplate('AnyOne', 'ANY1');
        $stage1 = $this->stageFor($template, 1, 'TM Approval', 'any_one');
        $stage2 = $this->stageFor($template, 2, 'Distribution');
        $this->approvedTransition($stage1, $stage2);
        $this->approvedTransition($stage2, null);

        $tm1 = User::factory()->create();
        $tm2 = User::factory()->create();
        $distributor = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $tm1->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $tm2->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $stage2->id, 'user_id' => $distributor->id]);

        $document = $this->makeDocument($template);
        $instance = $this->engine->start($document, $document->currentVersion, $document->owner);

        $instance = $this->engine->recordDecision($instance, $tm1, 'approved');

        $this->assertSame($stage2->id, $instance->current_stage_id);

        // tm2 never acted - their task is closed out as 'superseded' (not left
        // 'pending' forever) once the stage resolves via tm1, so it's clear who
        // actually decided it and tm2's task drops off their inbox.
        $orphaned = $instance->assignees()->where('user_id', $tm2->id)->first();
        $this->assertSame('superseded', $orphaned->status);
    }

    // --- ALL_REQUIRED: parallel MLR (Medical + Regulatory + Legal) --------------

    public function test_all_required_stage_waits_for_every_approver_before_advancing(): void
    {
        $template = $this->makeTemplate('MLR', 'MLR');
        $mlr = $this->stageFor($template, 1, 'MLR Review', 'all_required');
        $final = $this->stageFor($template, 2, 'Final Approval');
        $this->approvedTransition($mlr, $final);
        $this->approvedTransition($final, null);

        $medical = User::factory()->create();
        $regulatory = User::factory()->create();
        $legal = User::factory()->create();
        $chairperson = User::factory()->create();
        foreach ([$medical, $regulatory, $legal] as $reviewer) {
            WorkflowStageApprover::create(['workflow_stage_id' => $mlr->id, 'user_id' => $reviewer->id]);
        }
        WorkflowStageApprover::create(['workflow_stage_id' => $final->id, 'user_id' => $chairperson->id]);

        $document = $this->makeDocument($template);
        $instance = $this->engine->start($document, $document->currentVersion, $document->owner);

        // All three tasks were created simultaneously.
        $this->assertSame(3, $instance->assignees()->where('workflow_stage_id', $mlr->id)->count());

        $instance = $this->engine->recordDecision($instance, $medical, 'approved');
        $this->assertSame($mlr->id, $instance->current_stage_id, 'Stage must not advance until every required approver has acted.');

        $instance = $this->engine->recordDecision($instance, $regulatory, 'approved');
        $this->assertSame($mlr->id, $instance->current_stage_id, 'Still one approver short.');

        $instance = $this->engine->recordDecision($instance, $legal, 'approved');
        $this->assertSame($final->id, $instance->current_stage_id, 'All three approved - stage should now advance.');
    }

    // --- Revision loop: AwC routes back, resume picks up where it left off ------

    public function test_approved_with_changes_routes_back_and_resubmission_resumes_at_the_sending_stage(): void
    {
        $template = $this->makeTemplate('Revision', 'REV');
        $contentMgr = $this->stageFor($template, 1, 'Content Manager Review');
        $medical = $this->stageFor($template, 2, 'Medical Review');
        $final = $this->stageFor($template, 3, 'Final Approval');

        $this->approvedTransition($contentMgr, $medical);
        $this->approvedTransition($medical, $final);
        $this->approvedTransition($final, null);

        // Medical: Approved with Changes -> back to Content Manager; once Content
        // Manager re-approves, resume at Medical (the stage that sent it back).
        WorkflowTransition::create([
            'workflow_stage_id' => $medical->id,
            'decision' => 'approved_with_changes',
            'outcome_type' => 'return_to_stage',
            'target_stage_id' => $contentMgr->id,
            'resume_at_stage_id' => $medical->id,
        ]);
        // Content Manager's own "approved" transition when re-entered for a revision
        // pass is still just "go to Medical" per the transition above - the engine's
        // resumeAfterRevision() is what actually returns to $medical directly, so no
        // extra transition row is needed for the resume itself.

        $owner = User::factory()->create();
        $contentMgrUser = User::factory()->create();
        $medicalUser = User::factory()->create();
        $finalUser = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $contentMgr->id, 'user_id' => $contentMgrUser->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $medical->id, 'user_id' => $medicalUser->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $final->id, 'user_id' => $finalUser->id]);

        $document = $this->makeDocument($template, $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $instance = $this->engine->recordDecision($instance, $contentMgrUser, 'approved');
        $this->assertSame($medical->id, $instance->current_stage_id);

        $instance = $this->engine->recordDecision($instance, $medicalUser, 'approved_with_changes', 'Please fix the dosing claim on page 2.');

        $instance = $instance->fresh();
        $this->assertSame($contentMgr->id, $instance->current_stage_id, 'AwC should route back to Content Manager.');
        $this->assertSame($medical->id, $instance->resume_at_stage_id);
        $this->assertSame('approved_with_changes_pending', $document->fresh()->status);

        // Owner uploads V2 and resubmits.
        $v2 = app(DocumentVersionService::class)->storeNewVersion(
            document: $document,
            file: UploadedFile::fake()->create('artwork-v2.pdf', 10, 'application/pdf'),
            uploader: $owner,
            changeNotes: 'Fixed dosing claim per Medical feedback.',
        );

        $this->engine->resumeAfterRevision($instance, $v2);

        $instance = $instance->fresh();
        $this->assertSame($medical->id, $instance->current_stage_id, 'Resubmission must resume at Medical, not restart from Content Manager.');
        $this->assertSame('in_review', $document->fresh()->status);
        $this->assertSame(2, $document->fresh()->versions()->count());
        $this->assertSame($v2->id, $document->fresh()->current_version_id);

        // Medical approves the revised version; workflow completes normally.
        $instance = $this->engine->recordDecision($instance, $medicalUser, 'approved');
        $this->assertSame($final->id, $instance->current_stage_id);

        $instance = $this->engine->recordDecision($instance, $finalUser, 'approved');
        $this->assertSame('approved_for_distribution', $document->fresh()->status);
    }

    // --- Rejection terminates the workflow ---------------------------------------

    public function test_not_approved_terminates_the_workflow_as_rejected(): void
    {
        $template = $this->makeTemplate('Reject', 'REJ');
        $stage1 = $this->stageFor($template, 1, 'Gatekeeper Review');
        WorkflowTransition::create([
            'workflow_stage_id' => $stage1->id,
            'decision' => 'not_approved',
            'outcome_type' => 'terminate_rejected',
        ]);

        $reviewer = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $reviewer->id]);

        $document = $this->makeDocument($template);
        $instance = $this->engine->start($document, $document->currentVersion, $document->owner);

        $instance = $this->engine->recordDecision($instance, $reviewer, 'not_approved', 'Does not meet compliance standards.');

        $this->assertSame('rejected', $instance->status);
        $this->assertSame('rejected', $document->fresh()->status);
        $this->assertNull($instance->current_stage_id);
    }

    // --- Cannot act twice on the same task, and cannot act after the stage moved on --

    public function test_the_same_pending_task_cannot_be_approved_twice(): void
    {
        // An all_required stage that hasn't fully resolved yet is what makes this
        // guard observable single-threaded: the stage stays "current" after the
        // first approver acts (others are still pending), so a second call from
        // that same, now-already-acted approver must still be rejected.
        $template = $this->makeTemplate('DoubleAct', 'DBL');
        $stage1 = $this->stageFor($template, 1, 'Two Reviewers', 'all_required');
        $this->approvedTransition($stage1, null);

        $reviewer = User::factory()->create();
        $otherReviewer = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $reviewer->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $otherReviewer->id]);

        $document = $this->makeDocument($template);
        $instance = $this->engine->start($document, $document->currentVersion, $document->owner);

        $instance = $this->engine->recordDecision($instance, $reviewer, 'approved');
        $this->assertSame($stage1->id, $instance->current_stage_id, 'Stage should still be waiting on otherReviewer.');

        $this->expectException(ValidationException::class);
        $this->engine->recordDecision($instance, $reviewer, 'approved');
    }

    public function test_a_non_approver_cannot_record_a_decision(): void
    {
        $template = $this->makeTemplate('Guard', 'GRD');
        $stage1 = $this->stageFor($template, 1, 'Reviewer Only');
        $this->approvedTransition($stage1, null);

        $reviewer = User::factory()->create();
        $outsider = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $reviewer->id]);

        $document = $this->makeDocument($template);
        $instance = $this->engine->start($document, $document->currentVersion, $document->owner);

        $this->expectException(ValidationException::class);
        $this->engine->recordDecision($instance, $outsider, 'approved');
    }

    // --- Audit trail -------------------------------------------------------------

    public function test_key_lifecycle_events_are_captured_in_the_audit_log(): void
    {
        $template = $this->makeTemplate('Audit', 'AUD');
        $stage1 = $this->stageFor($template, 1, 'Only Stage');
        $this->approvedTransition($stage1, null);

        $reviewer = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $reviewer->id]);

        $document = $this->makeDocument($template);
        $instance = $this->engine->start($document, $document->currentVersion, $document->owner);
        $this->engine->recordDecision($instance, $reviewer, 'approved');

        $actions = AuditLog::where('document_id', $document->id)->pluck('action');

        $this->assertContains('SUBMITTED', $actions);
        $this->assertContains('ASSIGNED', $actions);
        $this->assertContains('APPROVED', $actions);
        $this->assertContains('DISTRIBUTED', $actions);
    }
}
