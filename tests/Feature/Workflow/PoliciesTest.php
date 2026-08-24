<?php

namespace Tests\Feature\Workflow;

use App\Models\Document;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentWorkflowInstance;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The authorization layer added alongside the audit log: previously several of
 * these rules only existed as abort_unless() calls scattered through
 * DocumentController. Centralizing them in app/Policies doesn't change the
 * rules themselves (asserted here) but does make them reachable from anywhere
 * a Document/ApprovalTask is touched, not just those specific controller
 * methods - see App\Policies\* and App\Providers\AuthServiceProvider.
 */
class PoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_owner_can_update_but_a_stranger_cannot(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $document = Document::create([
            'title' => 'Owned Doc',
            'reference_no' => 'REF-' . uniqid(),
            'owner_id' => $owner->id,
            'status' => 'draft',
        ]);

        $this->assertTrue($owner->can('update', $document));
        $this->assertFalse($stranger->can('update', $document));
    }

    public function test_admin_can_update_any_document(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'is_system' => true]);
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $document = Document::create([
            'title' => 'Someone Elses Doc',
            'reference_no' => 'REF-' . uniqid(),
            'owner_id' => User::factory()->create()->id,
            'status' => 'draft',
        ]);

        $this->assertTrue($admin->can('update', $document));
        $this->assertTrue($admin->can('manageLegalHold', $document));
    }

    public function test_only_admin_can_manage_legal_hold_even_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $document = Document::create([
            'title' => 'Doc',
            'reference_no' => 'REF-' . uniqid(),
            'owner_id' => $owner->id,
            'status' => 'draft',
        ]);

        $this->assertFalse($owner->can('manageLegalHold', $document));
    }

    public function test_only_the_assigned_pending_approver_can_act_on_their_task(): void
    {
        $template = WorkflowTemplate::create([
            'name' => 'T', 'code' => 'T-' . uniqid(), 'is_active' => true, 'created_by' => User::factory()->create()->id,
        ]);
        $stage = WorkflowStage::create([
            'workflow_template_id' => $template->id, 'sequence_no' => 1, 'name' => 'Review', 'code' => 'REVIEW',
        ]);
        $document = Document::create([
            'title' => 'D', 'reference_no' => 'REF-' . uniqid(), 'owner_id' => User::factory()->create()->id, 'status' => 'in_review',
        ]);
        $version = $document->versions()->create([
            'version_no' => 1, 'original_filename' => 'f.pdf', 'disk' => 'documents', 'file_path' => 'x/f.pdf',
            'mime_type' => 'application/pdf', 'file_size_bytes' => 10, 'uploaded_by' => $document->owner_id, 'is_current' => true,
        ]);
        $instance = DocumentWorkflowInstance::create([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'workflow_template_id' => $template->id,
            'current_stage_id' => $stage->id,
            'status' => 'running',
            'initiated_by' => User::factory()->create()->id,
            'started_at' => now(),
        ]);

        $approver = User::factory()->create();
        $outsider = User::factory()->create();
        $task = DocumentStageAssignee::create([
            'document_workflow_instance_id' => $instance->id,
            'workflow_stage_id' => $stage->id,
            'user_id' => $approver->id,
            'status' => 'pending',
            'assigned_at' => now(),
        ]);

        $this->assertTrue($approver->can('act', $task));
        $this->assertFalse($outsider->can('act', $task));

        $task->update(['status' => 'acted']);
        $this->assertFalse($approver->fresh()->can('act', $task->fresh()), 'An already-acted task cannot be acted on again.');
    }
}
