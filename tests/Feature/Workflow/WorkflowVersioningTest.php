<?php

namespace Tests\Feature\Workflow;

use App\Models\Document;
use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_template_is_unlocked_until_a_document_actually_starts_using_it(): void
    {
        $admin = User::factory()->create();
        $template = WorkflowTemplate::create(['name' => 'V1', 'code' => 'VER1', 'is_active' => true, 'created_by' => $admin->id]);

        $this->assertFalse($template->isLocked());

        $stage = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 1, 'name' => 'S1', 'code' => 'S1']);
        $document = Document::create(['title' => 'D', 'reference_no' => 'REF-' . uniqid(), 'owner_id' => $admin->id, 'workflow_template_id' => $template->id, 'status' => 'draft']);
        $version = $document->versions()->create([
            'version_no' => 1, 'original_filename' => 'f.pdf', 'disk' => 'documents', 'file_path' => 'x/f.pdf',
            'mime_type' => 'application/pdf', 'file_size_bytes' => 10, 'uploaded_by' => $admin->id, 'is_current' => true,
        ]);

        DocumentWorkflowInstance::create([
            'document_id' => $document->id, 'document_version_id' => $version->id, 'workflow_template_id' => $template->id,
            'current_stage_id' => $stage->id, 'status' => 'running', 'initiated_by' => $admin->id, 'started_at' => now(),
        ]);

        $this->assertTrue($template->fresh()->isLocked());
    }

    public function test_create_new_version_clones_stages_approvers_and_transitions_without_touching_the_original(): void
    {
        $admin = User::factory()->create();
        $reviewer = User::factory()->create();

        $v1 = WorkflowTemplate::create(['name' => 'Clonable', 'code' => 'CLONE1', 'is_active' => true, 'created_by' => $admin->id]);
        $stage1 = WorkflowStage::create(['workflow_template_id' => $v1->id, 'sequence_no' => 1, 'name' => 'Review', 'code' => 'REVIEW', 'sla_hours' => 24]);
        WorkflowStageApprover::create(['workflow_stage_id' => $stage1->id, 'user_id' => $reviewer->id]);
        WorkflowTransition::create(['workflow_stage_id' => $stage1->id, 'decision' => 'approved', 'outcome_type' => 'complete_approved']);

        $v2 = $v1->createNewVersion($admin);

        $this->assertSame('CLONE1', $v2->family_code);
        $this->assertSame(2, $v2->version);
        $this->assertNotSame($v1->id, $v2->id);
        $this->assertTrue($v2->is_active);
        $this->assertFalse($v1->fresh()->is_active, 'The superseded version should drop out of is_active.');

        $v2Stage = $v2->stages()->first();
        $this->assertSame('Review', $v2Stage->name);
        $this->assertSame(24, $v2Stage->sla_hours);
        $this->assertSame($reviewer->id, $v2Stage->approvers()->first()->user_id);
        $this->assertSame('complete_approved', $v2Stage->transitions()->first()->outcome_type);

        // The original stage/approver/transition rows are untouched, not moved.
        $this->assertNotNull($stage1->fresh());
        $this->assertSame($v1->id, $stage1->fresh()->workflow_template_id);
    }

    public function test_editing_a_locked_template_is_rejected_but_a_new_version_is_editable(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $adminRole = \App\Models\Role::create(['name' => 'Admin', 'slug' => 'admin', 'is_system' => true]);
        $admin->roles()->attach($adminRole);

        $template = WorkflowTemplate::create(['name' => 'Locked', 'code' => 'LOCK1', 'is_active' => true, 'created_by' => $admin->id]);
        $stage = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 1, 'name' => 'S1', 'code' => 'S1']);
        $document = Document::create(['title' => 'D', 'reference_no' => 'REF-' . uniqid(), 'owner_id' => $admin->id, 'workflow_template_id' => $template->id, 'status' => 'draft']);
        $version = $document->versions()->create([
            'version_no' => 1, 'original_filename' => 'f.pdf', 'disk' => 'documents', 'file_path' => 'x/f.pdf',
            'mime_type' => 'application/pdf', 'file_size_bytes' => 10, 'uploaded_by' => $admin->id, 'is_current' => true,
        ]);
        DocumentWorkflowInstance::create([
            'document_id' => $document->id, 'document_version_id' => $version->id, 'workflow_template_id' => $template->id,
            'current_stage_id' => $stage->id, 'status' => 'running', 'initiated_by' => $admin->id, 'started_at' => now(),
        ]);

        $response = $this->post(route('admin.workflows.stages.add', $template), [
            'name' => 'New Stage', 'code' => 'NEW', 'approval_mode' => 'any_one', 'role_ids' => [],
        ]);
        $response->assertStatus(422);

        $newVersion = $template->createNewVersion($admin);
        $this->assertFalse($newVersion->isLocked());
    }
}
