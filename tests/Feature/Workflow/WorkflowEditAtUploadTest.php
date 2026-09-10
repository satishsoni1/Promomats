<?php

namespace Tests\Feature\Workflow;

use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Owner customisation of the stage list at upload time: a template with
 * owner_can_customize_workflow gets a private per-document copy
 * (WorkflowTemplate::clonePrivateFor) reshaped by DocumentController::applyWorkflowEdit.
 */
class WorkflowEditAtUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        Storage::fake('documents');
    }

    /** A 3-stage, user-approver template that permits owner customisation. */
    private function customisableTemplate(): array
    {
        $template = WorkflowTemplate::create([
            'name' => 'Editable WF', 'code' => 'EDIT_WF', 'is_active' => true,
            'owner_can_customize_workflow' => true, 'created_by' => $this->admin->id,
        ]);

        $stages = [];
        foreach (['Review A' => 'STG_A', 'Review B' => 'STG_B', 'Review C' => 'STG_C'] as $name => $code) {
            $seq = count($stages) + 1;
            $stage = WorkflowStage::create([
                'workflow_template_id' => $template->id, 'sequence_no' => $seq,
                'name' => $name, 'code' => $code, 'approval_mode' => 'any_one',
                'is_final_distribution_stage' => $seq === 3,
            ]);
            $u = User::factory()->create();
            WorkflowStageApprover::create(['workflow_stage_id' => $stage->id, 'user_id' => $u->id]);
            WorkflowTransition::create(['workflow_stage_id' => $stage->id, 'decision' => 'approved', 'outcome_type' => $seq === 3 ? 'complete_approved' : 'next_stage']);
            WorkflowTransition::create(['workflow_stage_id' => $stage->id, 'decision' => 'approved_with_changes', 'outcome_type' => 'return_to_owner', 'resume_at_stage_id' => $stage->id]);
            WorkflowTransition::create(['workflow_stage_id' => $stage->id, 'decision' => 'not_approved', 'outcome_type' => $seq === 1 ? 'terminate_rejected' : 'return_to_owner', 'resume_at_stage_id' => $seq === 1 ? null : $stage->id]);
            $stages[$code] = [$stage, $u];
        }

        return [$template, $stages];
    }

    private function upload(User $owner, WorkflowTemplate $template, array $editStages, string $title): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner)->post(route('documents.store'), [
            'title' => $title,
            'brand_id' => '',
            'document_type_id' => '',
            'workflow_template_id' => $template->id,
            'workflow_edit' => json_encode(['stages' => $editStages]),
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]);
    }

    public function test_reorder_and_remove_produces_a_private_copy_the_engine_runs(): void
    {
        [$template, $stages] = $this->customisableTemplate();
        $owner = User::factory()->create();

        // New order: C, then A. B removed.
        $this->upload($owner, $template, [
            ['code' => 'STG_C', 'name' => 'Review C', 'isNew' => false, 'removed' => false, 'approversDirty' => false, 'approverUserIds' => [], 'approverRoleIds' => []],
            ['code' => 'STG_B', 'name' => 'Review B', 'isNew' => false, 'removed' => true, 'approversDirty' => false, 'approverUserIds' => [], 'approverRoleIds' => []],
            ['code' => 'STG_A', 'name' => 'Review A', 'isNew' => false, 'removed' => false, 'approversDirty' => false, 'approverUserIds' => [], 'approverRoleIds' => []],
        ], 'Reordered upload')->assertRedirect();

        $document = Document::firstWhere('title', 'Reordered upload');
        $clone = $document->workflowTemplate;

        $this->assertTrue($clone->is_private);
        $this->assertSame($template->id, $clone->derived_from_template_id);
        $this->assertNotSame($template->id, $clone->id);

        // Shared template is untouched.
        $this->assertSame(3, $template->stages()->count());
        $this->assertSame(1, WorkflowTemplate::shared()->where('code', 'EDIT_WF')->count());

        // Clone: two stages, C before A, B gone, A is now final.
        $this->assertSame(['STG_C', 'STG_A'], $clone->stages()->pluck('code')->all());
        $this->assertTrue($clone->stages()->where('code', 'STG_A')->value('is_final_distribution_stage'));

        // Engine runs it: first stage opened is C, its approver assigned.
        $document = $document->fresh(['currentVersion']);
        $instance = app(WorkflowEngine::class)->start($document, $document->currentVersion, $owner);
        $firstStage = $clone->stages()->orderBy('sequence_no')->first();
        $this->assertSame('STG_C', $firstStage->code);
        $this->assertSame(
            $stages['STG_C'][1]->id,
            $instance->assignees()->where('workflow_stage_id', $firstStage->id)->value('user_id')
        );
    }

    public function test_a_new_stage_can_be_added_and_assigned_to_a_role(): void
    {
        [$template, $stages] = $this->customisableTemplate();
        $owner = User::factory()->create();
        $role = Role::create(['name' => 'Extra Check', 'slug' => 'extra-check']);
        $roleHolder = User::factory()->create();
        $roleHolder->roles()->attach($role->id);

        $this->upload($owner, $template, [
            ['code' => 'STG_A', 'name' => 'Review A', 'isNew' => false, 'removed' => false, 'approversDirty' => false, 'approverUserIds' => [], 'approverRoleIds' => []],
            ['code' => null, 'name' => 'Extra Legal Pass', 'isNew' => true, 'removed' => false, 'approvalMode' => 'any_one', 'approverUserIds' => [], 'approverRoleIds' => [$role->id]],
            ['code' => 'STG_B', 'name' => 'Review B', 'isNew' => false, 'removed' => false, 'approversDirty' => false, 'approverUserIds' => [], 'approverRoleIds' => []],
            ['code' => 'STG_C', 'name' => 'Review C', 'isNew' => false, 'removed' => false, 'approversDirty' => false, 'approverUserIds' => [], 'approverRoleIds' => []],
        ], 'Added-stage upload')->assertRedirect();

        $document = Document::firstWhere('title', 'Added-stage upload')->fresh(['currentVersion']);
        $clone = $document->workflowTemplate;

        $this->assertSame(4, $clone->stages()->count());
        $newStage = $clone->stages()->where('name', 'Extra Legal Pass')->first();
        $this->assertNotNull($newStage);
        $this->assertSame(2, $newStage->sequence_no);
        $this->assertSame($role->id, $newStage->approvers()->value('role_id'));

        // Engine advances Review A -> the new role-based stage and assigns the role holder.
        $instance = app(WorkflowEngine::class)->start($document, $document->currentVersion, $owner);
        $instance = app(WorkflowEngine::class)->recordDecision($instance, $stages['STG_A'][1], 'approved');
        $this->assertSame($roleHolder->id, $instance->assignees()->where('workflow_stage_id', $newStage->id)->value('user_id'));
    }

    public function test_edit_is_rejected_when_template_does_not_allow_customisation(): void
    {
        [$template, $stages] = $this->customisableTemplate();
        $template->update(['owner_can_customize_workflow' => false]);
        $owner = User::factory()->create();

        $this->upload($owner, $template, [
            ['code' => 'STG_A', 'name' => 'Review A', 'isNew' => false, 'removed' => false, 'approversDirty' => false, 'approverUserIds' => [], 'approverRoleIds' => []],
        ], 'Blocked upload')->assertSessionHasErrors('workflow_edit');

        $this->assertDatabaseCount('documents', 0);
    }
}
