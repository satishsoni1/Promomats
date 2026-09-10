<?php

namespace Tests\Feature\Workflow;

use App\Models\Document;
use App\Models\DocumentStageApproverSelection;
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

/**
 * The "document owner picks who takes each stage at upload" feature:
 * owner_can_customize_workflow on the template + document_stage_approver_selections
 * per document, consumed by WorkflowEngine::resolveStageApprovers().
 */
class StageApproverSelectionTest extends TestCase
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

    private function poolTemplate(): array
    {
        $template = WorkflowTemplate::create([
            'name' => 'Pool WF', 'code' => 'POOL_WF', 'is_active' => true,
            'owner_can_customize_workflow' => true, 'created_by' => $this->admin->id,
        ]);

        $stage = WorkflowStage::create([
            'workflow_template_id' => $template->id, 'sequence_no' => 1,
            'name' => 'Project Lead Review', 'code' => 'PL_REVIEW', 'approval_mode' => 'any_one',
            'is_final_distribution_stage' => true,
        ]);
        WorkflowTransition::create(['workflow_stage_id' => $stage->id, 'decision' => 'approved', 'outcome_type' => 'complete_approved']);

        $pool = User::factory()->count(3)->create();
        foreach ($pool as $u) {
            WorkflowStageApprover::create(['workflow_stage_id' => $stage->id, 'user_id' => $u->id]);
        }

        return [$template, $stage, $pool];
    }

    private function makeDocument(WorkflowTemplate $template, User $owner): Document
    {
        $document = Document::create([
            'title' => 'Doc', 'reference_no' => 'REF-' . uniqid(),
            'owner_id' => $owner->id, 'workflow_template_id' => $template->id, 'status' => 'draft',
        ]);

        app(DocumentVersionService::class)->storeNewVersion(
            document: $document,
            file: UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            uploader: $owner,
            changeNotes: 'Initial upload',
        );

        return $document->fresh(['currentVersion']);
    }

    public function test_selection_narrows_a_pooled_stage_to_the_picked_person(): void
    {
        [$template, $stage, $pool] = $this->poolTemplate();
        $owner = User::factory()->create();
        $document = $this->makeDocument($template, $owner);

        $picked = $pool[1];
        DocumentStageApproverSelection::create([
            'document_id' => $document->id,
            'workflow_stage_id' => $stage->id,
            'user_id' => $picked->id,
        ]);

        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $assigneeIds = $instance->assignees()->pluck('user_id')->all();
        $this->assertSame([$picked->id], $assigneeIds);
    }

    public function test_without_a_selection_the_whole_pool_is_assigned(): void
    {
        [$template, $stage, $pool] = $this->poolTemplate();
        $owner = User::factory()->create();
        $document = $this->makeDocument($template, $owner);

        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->assertEqualsCanonicalizing(
            $pool->pluck('id')->all(),
            $instance->assignees()->pluck('user_id')->all()
        );
    }

    public function test_upload_persists_a_valid_pick_and_rejects_a_non_candidate(): void
    {
        [$template, $stage, $pool] = $this->poolTemplate();
        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        // A pick outside the stage's candidate pool is rejected, nothing created.
        $this->actingAs($owner)
            ->from(route('documents.create'))
            ->post(route('documents.store'), [
                'title' => 'Rejected upload',
                'brand_id' => '',
                'document_type_id' => '',
                'workflow_template_id' => $template->id,
                'stage_approvers' => [(string) $stage->id => [(string) $outsider->id]],
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('stage_approvers');

        $this->assertDatabaseCount('documents', 0);

        // A pick from within the pool is stored against the new document.
        $this->actingAs($owner)
            ->post(route('documents.store'), [
                'title' => 'Good upload',
                'brand_id' => '',
                'document_type_id' => '',
                'workflow_template_id' => $template->id,
                'stage_approvers' => [(string) $stage->id => [(string) $pool[0]->id]],
                'file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = Document::firstWhere('title', 'Good upload');
        $this->assertNotNull($document);
        $this->assertDatabaseHas('document_stage_approver_selections', [
            'document_id' => $document->id,
            'workflow_stage_id' => $stage->id,
            'user_id' => $pool[0]->id,
        ]);
    }
}
