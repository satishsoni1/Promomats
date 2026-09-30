<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The mobile app's API: token login, the action list, opening a document and its
 * file, commenting and deciding - with the same permission rules as the web.
 */
class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reviewer_can_log_in_see_their_task_open_it_and_approve_from_the_app(): void
    {
        Storage::fake('documents');
        Notification::fake();

        $owner = User::factory()->create();
        $reviewer = User::factory()->create(['email' => 'rathna@example.com', 'password' => 'secret-pass']);
        $stranger = User::factory()->create(['password' => 'secret-pass']);

        $template = WorkflowTemplate::create(['name' => 'T', 'code' => 'T1', 'is_active' => true, 'created_by' => $owner->id]);
        $stage = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 1, 'name' => 'Content Manager Review', 'code' => 'CM', 'approval_mode' => 'any_one', 'is_final_distribution_stage' => true]);
        WorkflowStageApprover::create(['workflow_stage_id' => $stage->id, 'user_id' => $reviewer->id]);
        WorkflowTransition::create(['workflow_stage_id' => $stage->id, 'decision' => 'approved', 'outcome_type' => 'complete_approved']);

        $document = Document::create(['title' => 'Liv.52 LBL', 'reference_no' => 'IN-PR-1', 'owner_id' => $owner->id, 'workflow_template_id' => $template->id, 'status' => 'draft']);
        app(DocumentVersionService::class)->storeNewVersion($document, UploadedFile::fake()->create('lbl.pdf', 10, 'application/pdf'), $owner);
        app(WorkflowEngine::class)->start($document->fresh(), $document->fresh()->currentVersion, $owner);

        $this->postJson('/api/v1/login', ['email' => 'rathna@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $token = $this->postJson('/api/v1/login', ['email' => 'rathna@example.com', 'password' => 'secret-pass', 'device_name' => 'Pixel'])
            ->assertOk()->json('token');
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/tasks')->assertUnauthorized();
        $this->getJson('/api/v1/tasks', $auth)->assertOk()
            ->assertJsonPath('approvals.0.document.reference_no', 'IN-PR-1')
            ->assertJsonPath('approvals.0.stage', 'Content Manager Review');

        $detail = $this->getJson("/api/v1/documents/{$document->id}", $auth)->assertOk()
            ->assertJsonPath('my_task.can_decide', true)
            ->assertJsonPath('file.kind', 'pdf');
        $this->get($detail->json('file.url'))->assertOk();
        $this->get("/api/v1/files/{$document->current_version_id}")->assertForbidden(); // unsigned

        $this->postJson("/api/v1/documents/{$document->id}/comments", ['body' => 'Looks good'], $auth)->assertCreated();
        $this->postJson("/api/v1/documents/{$document->id}/decision", ['decision' => 'approved_with_changes'], $auth)->assertUnprocessable(); // needs comments
        $this->postJson("/api/v1/documents/{$document->id}/decision", ['decision' => 'approved'], $auth)->assertOk()
            ->assertJsonPath('status', 'approved_for_distribution');

        // Someone who isn't a stakeholder can't open an in-flight job.
        $otherDoc = Document::create(['title' => 'Private', 'reference_no' => 'IN-PR-2', 'owner_id' => $owner->id, 'workflow_template_id' => $template->id, 'status' => 'draft']);
        $strangerToken = $this->postJson('/api/v1/login', ['email' => $stranger->email, 'password' => 'secret-pass'])->json('token');
        $this->getJson("/api/v1/documents/{$otherDoc->id}", ['Authorization' => "Bearer {$strangerToken}"])->assertForbidden();

        $this->postJson('/api/v1/logout', [], $auth)->assertOk();
        $this->getJson('/api/v1/me', $auth)->assertUnauthorized();
    }
}
