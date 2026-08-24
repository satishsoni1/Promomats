<?php

namespace Tests\Feature\Workflow;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use App\Services\DocumentVersionService;
use App\Services\Workflow\ConditionEvaluator;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConditionalStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_condition_evaluator_handles_equals_any_and_all(): void
    {
        $evaluator = new ConditionEvaluator();
        $videoType = DocumentType::create(['name' => 'Video', 'code' => 'VID', 'status' => 'active']);
        $pdfType = DocumentType::create(['name' => 'PDF', 'code' => 'PDF', 'status' => 'active']);
        $document = Document::create([
            'title' => 'D', 'reference_no' => 'REF-' . uniqid(), 'owner_id' => User::factory()->create()->id,
            'status' => 'draft', 'document_type_id' => $videoType->id, 'target_audience' => 'hcp',
        ]);

        $this->assertTrue($evaluator->evaluate(null, $document), 'No condition = always runs.');

        $this->assertTrue($evaluator->evaluate(
            ['field' => 'document_type_id', 'operator' => 'equals', 'value' => (string) $videoType->id], $document
        ));
        $this->assertFalse($evaluator->evaluate(
            ['field' => 'document_type_id', 'operator' => 'equals', 'value' => (string) $pdfType->id], $document
        ));

        $this->assertTrue($evaluator->evaluate([
            'any' => [
                ['field' => 'document_type_id', 'operator' => 'equals', 'value' => (string) $pdfType->id],
                ['field' => 'target_audience', 'operator' => 'equals', 'value' => 'hcp'],
            ],
        ], $document), 'any: at least one branch matches.');

        $this->assertFalse($evaluator->evaluate([
            'all' => [
                ['field' => 'document_type_id', 'operator' => 'equals', 'value' => (string) $pdfType->id],
                ['field' => 'target_audience', 'operator' => 'equals', 'value' => 'hcp'],
            ],
        ], $document), 'all: one branch fails, so the whole group fails.');
    }

    public function test_a_stage_whose_condition_does_not_match_is_skipped_entirely(): void
    {
        Storage::fake('documents');
        $admin = User::factory()->create();
        $videoType = DocumentType::create(['name' => 'Video', 'code' => 'VID2', 'status' => 'active']);
        $pdfType = DocumentType::create(['name' => 'PDF', 'code' => 'PDF2', 'status' => 'active']);

        $template = WorkflowTemplate::create(['name' => 'Cond', 'code' => 'COND1', 'is_active' => true, 'created_by' => $admin->id]);
        $stage1 = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 1, 'name' => 'S1', 'code' => 'S1']);
        $videoOnlyStage = WorkflowStage::create([
            'workflow_template_id' => $template->id, 'sequence_no' => 2, 'name' => 'Video-only Review', 'code' => 'VIDEO_REVIEW',
            'condition_json' => ['field' => 'document_type_id', 'operator' => 'equals', 'value' => (string) $videoType->id],
        ]);
        $stage3 = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 3, 'name' => 'S3', 'code' => 'S3']);

        WorkflowTransition::create(['workflow_stage_id' => $stage1->id, 'decision' => 'approved', 'outcome_type' => 'next_stage']);
        WorkflowTransition::create(['workflow_stage_id' => $videoOnlyStage->id, 'decision' => 'approved', 'outcome_type' => 'next_stage']);
        WorkflowTransition::create(['workflow_stage_id' => $stage3->id, 'decision' => 'approved', 'outcome_type' => 'complete_approved']);

        $reviewer1 = User::factory()->create();
        $videoReviewer = User::factory()->create();
        $reviewer3 = User::factory()->create();
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $reviewer1->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $videoOnlyStage->id, 'user_id' => $videoReviewer->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $stage3->id, 'user_id' => $reviewer3->id]);

        $owner = User::factory()->create();
        $document = Document::create([
            'title' => 'PDF Doc', 'reference_no' => 'REF-' . uniqid(), 'owner_id' => $owner->id,
            'workflow_template_id' => $template->id, 'document_type_id' => $pdfType->id, 'status' => 'draft',
        ]);
        app(DocumentVersionService::class)->storeNewVersion(
            document: $document, file: UploadedFile::fake()->create('d.pdf', 5, 'application/pdf'), uploader: $owner,
        );
        $document = $document->fresh(['currentVersion']);

        $engine = app(WorkflowEngine::class);
        $instance = $engine->start($document, $document->currentVersion, $owner);

        $this->assertSame($stage1->id, $instance->current_stage_id);

        // Approving stage1 should skip straight past the video-only stage (this
        // is a PDF) and land on stage3 - no task ever created for videoReviewer.
        $instance = $engine->recordDecision($instance, $reviewer1, 'approved');

        $this->assertSame($stage3->id, $instance->current_stage_id);
        $this->assertSame(0, $instance->assignees()->where('workflow_stage_id', $videoOnlyStage->id)->count());

        $skipLog = \App\Models\AuditLog::where('document_id', $document->id)->where('action', 'SKIPPED')->first();
        $this->assertNotNull($skipLog);
        $this->assertSame($videoOnlyStage->id, $skipLog->workflow_stage_id);
    }
}
