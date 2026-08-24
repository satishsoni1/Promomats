<?php

namespace Tests\Feature\Workflow;

use App\Models\DistributionRecord;
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
use Tests\TestCase;

class MajorityModeTest extends TestCase
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

    protected function buildFiveReviewerStage(int $quorumCount = null): array
    {
        $template = WorkflowTemplate::create(['name' => 'Majority', 'code' => 'MAJ-' . uniqid(), 'is_active' => true, 'created_by' => $this->admin->id]);
        $stage = WorkflowStage::create([
            'workflow_template_id' => $template->id, 'sequence_no' => 1, 'name' => 'Committee Review', 'code' => 'COMMITTEE',
            'approval_mode' => 'majority', 'quorum_count' => $quorumCount,
        ]);
        WorkflowTransition::create(['workflow_stage_id' => $stage->id, 'decision' => 'approved', 'outcome_type' => 'complete_approved']);
        WorkflowTransition::create(['workflow_stage_id' => $stage->id, 'decision' => 'not_approved', 'outcome_type' => 'terminate_rejected']);

        $reviewers = User::factory()->count(5)->create();
        foreach ($reviewers as $reviewer) {
            WorkflowStageApprover::create(['workflow_stage_id' => $stage->id, 'user_id' => $reviewer->id]);
        }

        $owner = User::factory()->create();
        $document = \App\Models\Document::create([
            'title' => 'Committee Doc', 'reference_no' => 'REF-' . uniqid(), 'owner_id' => $owner->id,
            'workflow_template_id' => $template->id, 'status' => 'draft',
        ]);
        app(DocumentVersionService::class)->storeNewVersion(
            document: $document, file: UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'), uploader: $owner,
        );
        $document = $document->fresh(['currentVersion']);

        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        return [$instance, $reviewers, $document];
    }

    public function test_simple_majority_of_five_resolves_on_the_third_approval(): void
    {
        [$instance, $reviewers] = $this->buildFiveReviewerStage();

        $instance = $this->engine->recordDecision($instance, $reviewers[0], 'approved');
        $this->assertNotNull($instance->current_stage_id, 'Stage should still be waiting.');

        $instance = $this->engine->recordDecision($instance, $reviewers[1], 'approved');
        $this->assertNotNull($instance->current_stage_id, '2 of 5 is not yet a majority.');

        $instance = $this->engine->recordDecision($instance, $reviewers[2], 'approved');
        $this->assertSame('approved', $instance->status, '3 of 5 is a majority - the stage should resolve as approved.');
    }

    public function test_an_explicit_quorum_count_overrides_the_computed_majority(): void
    {
        [$instance, $reviewers] = $this->buildFiveReviewerStage(quorumCount: 4);

        $instance = $this->engine->recordDecision($instance, $reviewers[0], 'approved');
        $instance = $this->engine->recordDecision($instance, $reviewers[1], 'approved');
        $instance = $this->engine->recordDecision($instance, $reviewers[2], 'approved');
        $this->assertNotNull($instance->current_stage_id, '3 of 5 does not meet the explicit quorum of 4.');

        $instance = $this->engine->recordDecision($instance, $reviewers[3], 'approved');
        $this->assertSame('approved', $instance->status);
    }

    public function test_resolves_as_rejected_once_majority_becomes_mathematically_unreachable(): void
    {
        [$instance, $reviewers] = $this->buildFiveReviewerStage();

        // Quorum is 3. If 3 reviewers reject, the remaining 2 can no longer push
        // "approved" to 3 even if both still approve - reject early rather than
        // waiting for votes that can no longer change the outcome.
        $instance = $this->engine->recordDecision($instance, $reviewers[0], 'not_approved', 'No.');
        $this->assertNotNull($instance->current_stage_id);

        $instance = $this->engine->recordDecision($instance, $reviewers[1], 'not_approved', 'No.');
        $this->assertNotNull($instance->current_stage_id);

        $instance = $this->engine->recordDecision($instance, $reviewers[2], 'not_approved', 'No.');
        $this->assertSame('rejected', $instance->status);
    }

    public function test_a_pending_distribution_record_is_created_when_the_workflow_completes_approved(): void
    {
        [$instance, $reviewers, $document] = $this->buildFiveReviewerStage();

        $this->engine->recordDecision($instance, $reviewers[0], 'approved');
        $this->engine->recordDecision($instance, $reviewers[1], 'approved');
        $this->engine->recordDecision($instance, $reviewers[2], 'approved');

        $record = DistributionRecord::where('document_id', $document->id)->first();

        $this->assertNotNull($record);
        $this->assertSame('pending', $record->status);
        $this->assertSame('approved_for_distribution', $document->fresh()->status);
    }
}
