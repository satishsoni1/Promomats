<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentWorkTask;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Services\DocumentVersionService;
use App\Services\WorkflowEngine;
use App\Support\Workflow\TransitionWiring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The mobile app as a replica of the web: every flow a Brand Manager, reviewer,
 * Content Manager or Content Agency user runs on the web works through the API,
 * with the same rules (the API runs the web controllers).
 */
class MobileApiParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The Pharma R&D / Legal / Regulatory Affairs rule has its own test (VodoVaultReviewTest).
        config(['promomats.workflow.required_stakeholders' => []]);
        Storage::fake('documents');
        Notification::fake();
    }

    protected function user(?string $role = null): User
    {
        $u = User::factory()->create();
        if ($role) {
            $u->roles()->attach(Role::firstOrCreate(['slug' => $role], ['name' => ucwords(str_replace('-', ' ', $role))])->id);
        }

        return $u->fresh('roles');
    }

    protected function auth(User $u): array
    {
        [, $plain] = ApiToken::issue($u, 'test');

        return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    }

    /** @return array<string, WorkflowStage> */
    protected function flow(array $plan, array $attrs = []): array
    {
        $t = WorkflowTemplate::create($attrs + ['name' => 'Flow', 'code' => 'F' . uniqid(), 'is_active' => true, 'owner_can_customize_workflow' => true, 'created_by' => User::factory()->create()->id]);
        $stages = [];
        foreach ($plan as $i => [$code, $role, $type, $group]) {
            $s = WorkflowStage::create(['workflow_template_id' => $t->id, 'sequence_no' => $i + 1, 'name' => $code, 'code' => $code, 'stage_type' => $type, 'parallel_group' => $group, 'approval_mode' => 'any_one', 'is_final_distribution_stage' => $i === count($plan) - 1]);
            WorkflowStageApprover::create(['workflow_stage_id' => $s->id, 'role_id' => Role::firstOrCreate(['slug' => $role], ['name' => ucwords($role)])->id]);
            $stages[$code] = $s;
        }
        TransitionWiring::wire(array_values($stages));

        return $stages;
    }

    protected function job(WorkflowStage $s, User $owner, string $file = 'art.pdf'): Document
    {
        $d = Document::create(['title' => 'Liv.52 LBL', 'reference_no' => 'R-' . uniqid(), 'owner_id' => $owner->id, 'workflow_template_id' => $s->workflow_template_id, 'status' => 'draft']);
        app(DocumentVersionService::class)->storeNewVersion($d, UploadedFile::fake()->create($file, 10), $owner);

        return $d->fresh();
    }

    public function test_brand_manager_creates_a_job_on_the_phone_with_stakeholders_references_and_starts_it(): void
    {
        $bm = $this->user('brand-manager');
        $tm = $this->user('tmagm');
        $this->user('tmagm');
        $rnd = $this->user('rd');
        $s = $this->flow([['TM', 'tmagm', 'review', null], ['RND', 'rd', 'review', 'MLR'], ['LEGAL', 'legal', 'review', 'MLR']]);
        $h = $this->auth($bm);

        $this->getJson('/api/v1/me', $h)->assertOk()->assertJsonPath('user.can_upload', true)->assertJsonPath('user.can_decide', false);
        $options = $this->getJson('/api/v1/create-options', $h)->assertOk();
        $this->assertNotEmpty($options->json('stages.' . $s['TM']->workflow_template_id));

        $res = $this->post('/api/v1/documents', [
            'title' => 'Phone job',
            'workflow_template_id' => $s['TM']->workflow_template_id,
            'stage_approvers' => [$s['TM']->id => [$tm->id], $s['RND']->id => [$rnd->id]],
            'stage_due_hours' => [$s['TM']->id => 24],
            'file' => UploadedFile::fake()->create('lbl.pdf', 10),
            'reference_files' => [UploadedFile::fake()->create('study.pdf', 5)],
        ], $h)->assertCreated();
        $id = $res->json('document_id');
        $this->assertNotNull($id);

        $detail = $this->getJson("/api/v1/documents/{$id}", $h)->assertOk()
            ->assertJsonPath('start_panel.has_file', true)
            ->assertJsonPath('references.0.name', 'study.pdf');
        $this->get($detail->json('references.0.url'))->assertOk();

        $this->postJson("/api/v1/documents/{$id}/submit", [], $h)->assertOk()->assertJsonPath('status', 'in_review');
        $this->getJson("/api/v1/documents/{$id}", $h)->assertJsonPath('pending.0.user', $tm->name);

        // Picking yourself is refused, exactly as on the web.
        $this->post('/api/v1/documents', [
            'title' => 'Self', 'workflow_template_id' => $s['TM']->workflow_template_id,
            'stage_approvers' => [$s['TM']->id => [$bm->id]], 'file' => UploadedFile::fake()->create('x.pdf', 5),
        ], $h)->assertUnprocessable();
    }

    public function test_reviewer_drafts_comments_decides_and_is_then_locked_and_mlr_skips_unpicked_tracks(): void
    {
        $owner = $this->user('brand-manager');
        $tm = $this->user('tmagm');
        $rnd = $this->user('rd');
        $this->user('legal');
        $s = $this->flow([['TM', 'tmagm', 'review', null], ['RND', 'rd', 'review', 'MLR'], ['LEGAL', 'legal', 'review', 'MLR'], ['FINAL', 'chairperson', 'review', null]]);
        $doc = $this->job($s['TM'], $owner);
        $doc->stageApproverSelections()->create(['workflow_stage_id' => $s['TM']->id, 'user_id' => $tm->id]);
        $doc->stageApproverSelections()->create(['workflow_stage_id' => $s['RND']->id, 'user_id' => $rnd->id]);
        app(WorkflowEngine::class)->start($doc, $doc->currentVersion, $owner);
        $ht = $this->auth($tm);

        $this->getJson('/api/v1/tasks', $ht)->assertOk()->assertJsonPath('approvals.0.document.id', $doc->id);
        $this->postJson("/api/v1/documents/{$doc->id}/comments", ['body' => 'Check the claim', 'page_number' => 1, 'x_position' => 40, 'y_position' => 30], $ht)
            ->assertCreated()->assertJsonPath('comment.draft', true);
        $this->getJson("/api/v1/documents/{$doc->id}", $this->auth($owner))->assertJsonCount(0, 'comments');

        $this->postJson("/api/v1/documents/{$doc->id}/decision", ['decision' => 'approved_with_changes', 'comments' => 'Minor tweak'], $ht)
            ->assertOk()->assertJsonPath('status', 'in_review');

        $owners = $this->getJson("/api/v1/documents/{$doc->id}", $this->auth($owner))->assertOk();
        $this->assertSame('Check the claim', $owners->json('comments.0.body'));
        $states = collect($owners->json('stages'))->pluck('state', 'name');
        // MOM 24 Nov: FINAL had nobody selected either, so it is skipped too.
        $this->assertSame(['TM' => 'done', 'RND' => 'current', 'LEGAL' => 'skipped', 'FINAL' => 'skipped'], $states->all());

        $this->getJson("/api/v1/documents/{$doc->id}", $ht)->assertJsonPath('permissions.comments_locked', true);
        $this->postJson("/api/v1/documents/{$doc->id}/comments", ['body' => 'More'], $ht)->assertForbidden();
    }

    public function test_not_approved_agency_rework_and_brand_manager_resubmit_through_the_app(): void
    {
        $owner = $this->user('brand-manager');
        $tm = $this->user('tmagm');
        $agency = $this->user('content-agency');
        $s = $this->flow([['TM', 'tmagm', 'review', null], ['FINAL', 'chairperson', 'review', null]]);
        $this->user('chairperson');
        $doc = $this->job($s['TM'], $owner);
        app(WorkflowEngine::class)->start($doc, $doc->currentVersion, $owner);
        $hb = $this->auth($owner);

        $this->postJson("/api/v1/documents/{$doc->id}/decision", ['decision' => 'not_approved'], $this->auth($tm))->assertUnprocessable();
        $this->postJson("/api/v1/documents/{$doc->id}/decision", ['decision' => 'not_approved', 'comments' => 'Wrong claim'], $this->auth($tm))->assertOk();

        $this->getJson('/api/v1/tasks', $hb)->assertJsonPath('revisions.0.id', $doc->id);
        $this->getJson("/api/v1/documents/{$doc->id}", $hb)->assertJsonPath('owner_panel.mode', 'revise')->assertJsonPath('owner_panel.resubmit_to', 'TM');

        $this->postJson("/api/v1/documents/{$doc->id}/work-tasks", ['team' => 'agency', 'instructions' => 'Fix claim'], $hb)->assertCreated();
        $task = DocumentWorkTask::firstOrFail();
        $ha = $this->auth($agency);
        $this->getJson('/api/v1/tasks', $ha)->assertJsonPath('agency_queue.0.work_task_id', $task->id)->assertJsonPath('agency_queue.0.can_upload', true);
        $this->post("/api/v1/work-tasks/{$task->id}/complete", ['file' => UploadedFile::fake()->create('v2.pdf', 10)], $ha)->assertOk();

        $this->getJson("/api/v1/documents/{$doc->id}", $hb)->assertJsonPath('owner_panel.upload_to_confirm.version_no', 2);
        $this->postJson("/api/v1/documents/{$doc->id}/submit", [], $hb)->assertOk()->assertJsonPath('status', 'in_review');
        $this->getJson("/api/v1/documents/{$doc->id}", $hb)->assertJsonPath('pending.0.user', $tm->name);

        // Reassign + due date from the phone.
        $taskId = $this->getJson("/api/v1/documents/{$doc->id}", $hb)->json('pending.0.task_id');
        $other = $this->user('tmagm');
        $this->postJson("/api/v1/documents/{$doc->id}/tasks/{$taskId}/reassign", ['user_id' => $other->id, 'reason' => 'On leave'], $hb)->assertOk();
        $this->getJson("/api/v1/documents/{$doc->id}", $hb)->assertJsonPath('pending.0.user', $other->name);
    }

    public function test_draft_alignment_and_offline_chairperson_through_the_app(): void
    {
        $owner = $this->user('brand-manager');
        $cm = $this->user('content-manager');
        $s = $this->flow([['DRAFT', 'content-manager', 'draft', null], ['CHAIR', 'content-manager', 'offline', null]], ['completion_status' => 'approved_for_production']);
        $doc = $this->job($s['DRAFT'], $owner, 'draft.docx');
        $hb = $this->auth($owner);
        $hc = $this->auth($cm);

        $this->getJson("/api/v1/documents/{$doc->id}", $hb)->assertJsonPath('start_panel.first_stage_is_draft', true)->assertJsonPath('file.kind', 'word');
        $this->postJson("/api/v1/documents/{$doc->id}/submit", [], $hb)->assertOk();
        $this->getJson("/api/v1/documents/{$doc->id}", $hc)->assertJsonPath('my_task.is_draft', true);
        $this->postJson("/api/v1/documents/{$doc->id}/decision", ['decision' => 'approved'], $hc)->assertOk()->assertJsonPath('status', 'draft_aligned');

        $this->getJson("/api/v1/documents/{$doc->id}", $hb)->assertJsonPath('owner_panel.mode', 'draft_aligned')->assertJsonPath('owner_panel.resume_stage', 'CHAIR');
        $this->post("/api/v1/documents/{$doc->id}/submit", ['file' => UploadedFile::fake()->create('creative.pdf', 10)], $hb)->assertOk()->assertJsonPath('status', 'in_review');

        $this->getJson("/api/v1/documents/{$doc->id}", $hc)->assertJsonPath('my_task.is_offline', true);
        $this->postJson("/api/v1/documents/{$doc->id}/decision", ['decision' => 'approved'], $hc)->assertUnprocessable();
        $this->post("/api/v1/documents/{$doc->id}/decision", ['decision' => 'approved', 'signed_copy' => UploadedFile::fake()->create('signed.pdf', 10)], $hc)
            ->assertOk()->assertJsonPath('status', 'pending_closure');

        // The Brand Manager's final approval & closure.
        $this->getJson("/api/v1/documents/{$doc->id}", $hb)->assertJsonPath('closure_panel.chairperson_decision', 'approved');
        $this->postJson("/api/v1/documents/{$doc->id}/close", [], $hb)->assertOk()->assertJsonPath('status', 'approved_for_production');

        $this->getJson("/api/v1/documents/{$doc->id}", $hb)->assertJsonPath('lifecycle.0.status', 'approved_for_distribution');
        $this->postJson("/api/v1/documents/{$doc->id}/status", ['status' => 'approved_for_distribution'], $hb)->assertOk()->assertJsonPath('status', 'approved_for_distribution');
    }

    public function test_library_notifications_and_helpdesk(): void
    {
        $owner = $this->user('brand-manager');
        $s = $this->flow([['TM', 'tmagm', 'review', null]]);
        $this->job($s['TM'], $owner)->update(['status' => 'approved_for_distribution', 'title' => 'Approved Dangler']);
        $h = $this->auth($owner);

        $this->getJson('/api/v1/library?stage=approved', $h)->assertOk()->assertJsonPath('jobs.0.title', 'Approved Dangler')->assertJsonPath('counts.approved', 1);
        $this->getJson('/api/v1/notifications', $h)->assertOk()->assertJsonStructure(['unread', 'notifications']);
        $this->postJson('/api/v1/notifications/read-all', [], $h)->assertOk();

        $this->postJson('/api/v1/helpdesk', ['category' => 'technical_issue', 'priority' => 'high', 'subject' => 'Stuck', 'description' => 'Job not moving'], $h)->assertCreated();
        $this->getJson('/api/v1/helpdesk', $h)->assertOk()->assertJsonPath('tickets.0.subject', 'Stuck');
    }
}
