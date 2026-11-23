<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentWorkTask;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Notifications\DocumentActionNotification;
use App\Services\DocumentVersionService;
use App\Services\WorkflowEngine;
use App\Support\Workflow\TransitionWiring;
use Database\Seeders\HimalayaUatRoundTwoSeeder;
use Database\Seeders\PharmaWorkflowSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Himalaya UAT round 2 ("fine tuning required"): MLR picks and parallel review,
 * makers can't be checkers, Approved with changes moving on, Not approved coming
 * back via the Content Agency, draft alignment handed back to the Brand Manager,
 * draft / locked comments, offline Chairperson sign-off, references at upload.
 */
class HimalayaUatRoundTwoTest extends TestCase
{
    use RefreshDatabase;

    protected WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();
        $this->engine = app(WorkflowEngine::class);
    }

    protected function userWithRole(string $slug, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->roles()->attach(Role::firstOrCreate(['slug' => $slug], ['name' => ucwords(str_replace('-', ' ', $slug))])->id);

        return $user->fresh('roles');
    }

    /**
     * Template from a plan of [code, role slug, stage_type, parallel_group], wired
     * with the standard routing rules.
     *
     * @return array<string, WorkflowStage>
     */
    protected function flow(array $plan, array $templateAttrs = []): array
    {
        $template = WorkflowTemplate::create($templateAttrs + [
            'name' => 'Flow', 'code' => 'F' . uniqid(), 'is_active' => true,
            'owner_can_customize_workflow' => true, 'created_by' => User::factory()->create()->id,
        ]);

        $stages = [];
        foreach ($plan as $i => [$code, $role, $type, $group]) {
            $stage = WorkflowStage::create([
                'workflow_template_id' => $template->id, 'sequence_no' => $i + 1, 'name' => $code, 'code' => $code,
                'stage_type' => $type, 'parallel_group' => $group, 'approval_mode' => 'any_one',
                'is_final_distribution_stage' => $i === count($plan) - 1,
            ]);
            WorkflowStageApprover::create(['workflow_stage_id' => $stage->id, 'role_id' => Role::firstOrCreate(['slug' => $role], ['name' => ucwords(str_replace('-', ' ', $role))])->id]);
            $stages[$code] = $stage;
        }
        TransitionWiring::wire(array_values($stages));

        return $stages;
    }

    protected function document(WorkflowStage $anyStage, User $owner, string $file = 'art.pdf'): Document
    {
        $document = Document::create([
            'title' => 'Liv.52 LBL', 'reference_no' => 'REF-' . uniqid(), 'owner_id' => $owner->id,
            'workflow_template_id' => $anyStage->workflow_template_id, 'status' => 'draft',
        ]);
        app(DocumentVersionService::class)->storeNewVersion($document, UploadedFile::fake()->create($file, 10), $owner);

        return $document->fresh();
    }

    protected function mlrFlow(): array
    {
        return $this->flow([
            ['TM_AGM', 'tmagm', 'review', null],
            ['RND', 'rd', 'review', 'MLR'],
            ['REG', 'regulatory', 'review', 'MLR'],
            ['LEGAL', 'legal', 'review', 'MLR'],
            ['FINAL', 'chairperson', 'review', null],
        ]);
    }

    // ---- MLR: only the picked stakeholders, all at once ----

    public function test_mlr_runs_in_parallel_and_only_for_the_tracks_the_brand_manager_picked(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $rndA = $this->userWithRole('rd');
        $rndB = $this->userWithRole('rd');
        $reg = $this->userWithRole('regulatory');
        $legal = $this->userWithRole('legal');
        $s = $this->mlrFlow();
        $document = $this->document($s['TM_AGM'], $owner);
        $document->stageApproverSelections()->create(['workflow_stage_id' => $s['RND']->id, 'user_id' => $rndA->id]);

        $instance = $this->engine->start($document, $document->currentVersion, $owner);
        $this->engine->recordDecision($instance, $tm, 'approved');

        $pending = $instance->fresh()->pendingAssignees()->pluck('user_id');
        $this->assertEquals([$rndA->id], $pending->all(), 'Only the picked R&D reviewer gets the MLR task.');
        $this->assertFalse($pending->contains($rndB->id));
        $this->assertFalse($pending->contains($reg->id) || $pending->contains($legal->id), 'Unpicked MLR tracks are skipped.');
        $this->actingAs($owner)->get(route('documents.show', $document))->assertOk()->assertSee('Skipped - no stakeholder selected');

        // With every track picked, all three open at the same time.
        $document2 = $this->document($s['TM_AGM'], $owner);
        foreach ([$s['RND']->id => $rndB, $s['REG']->id => $reg, $s['LEGAL']->id => $legal] as $stageId => $u) {
            $document2->stageApproverSelections()->create(['workflow_stage_id' => $stageId, 'user_id' => $u->id]);
        }
        $instance2 = $this->engine->start($document2, $document2->currentVersion, $owner);
        $this->engine->recordDecision($instance2, $tm, 'approved');
        $this->assertEqualsCanonicalizing([$rndB->id, $reg->id, $legal->id], $instance2->fresh()->pendingAssignees()->pluck('user_id')->all());
    }

    // ---- Makers can't be checkers ----

    public function test_the_job_owner_never_gets_a_decision_task_and_document_owners_are_brand_managers(): void
    {
        $owner = $this->userWithRole('tmagm'); // could otherwise approve the TM/AGM stage
        $otherTm = $this->userWithRole('tmagm');
        $s = $this->mlrFlow();
        $document = $this->document($s['TM_AGM'], $owner);

        $instance = $this->engine->start($document, $document->currentVersion, $owner);
        $this->assertEquals([$otherTm->id], $instance->pendingAssignees()->pluck('user_id')->all());

        // Picking yourself at upload is refused.
        $this->actingAs($owner)->post(route('documents.store'), [
            'title' => 'Self check', 'workflow_template_id' => $s['TM_AGM']->workflow_template_id,
            'stage_approvers' => [$s['TM_AGM']->id => [$owner->id]],
            'file' => UploadedFile::fake()->create('x.pdf', 5),
        ])->assertSessionHasErrors('stage_approvers');

        $this->assertTrue($this->userWithRole('document-owner')->isBrandManagerOnly(), 'Document Owner and Brand Manager are the same role.');
    }

    // ---- Approved with changes moves on; Not approved comes back ----

    public function test_approved_with_changes_moves_on_to_the_next_stage(): void
    {
        $owner = User::factory()->create();
        $tm = $this->userWithRole('tmagm');
        $rnd = $this->userWithRole('rd');
        $this->userWithRole('regulatory');
        $this->userWithRole('legal');
        $s = $this->mlrFlow();
        $document = $this->document($s['TM_AGM'], $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->engine->recordDecision($instance, $tm, 'approved_with_changes', 'Use the new pack shot.');

        $this->assertSame('in_review', $document->fresh()->status);
        $this->assertTrue($instance->fresh()->pendingAssignees()->where('user_id', $rnd->id)->exists());
        Notification::assertSentTo($owner, DocumentActionNotification::class, fn ($n) => $n->event === 'action_taken' && $n->decision === 'approved_with_changes');
    }

    public function test_not_approved_goes_to_the_content_agency_then_the_brand_manager_confirms_and_it_returns_to_the_same_stage(): void
    {
        $owner = User::factory()->create();
        $tm = $this->userWithRole('tmagm');
        $agencyUser = $this->userWithRole('content-agency');
        $s = $this->mlrFlow();
        $document = $this->document($s['TM_AGM'], $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->engine->recordDecision($instance, $tm, 'not_approved', 'Wrong claim.');
        $this->assertSame('approved_with_changes_pending', $document->fresh()->status);

        $this->actingAs($owner)->post(route('documents.work-tasks.store', $document), [
            'team' => 'agency', 'instructions' => 'Fix the claim', 'resubmit_on_upload' => '1',
        ])->assertRedirect();
        $task = DocumentWorkTask::firstOrFail();
        $this->assertSame('agency', $task->team);
        $this->assertFalse($task->resubmit_on_upload, 'Agency uploads always come back to the Brand Manager.');
        Notification::assertSentTo($agencyUser, DocumentActionNotification::class, fn ($n) => $n->event === 'work_task_created');

        $this->actingAs($agencyUser)->get(route('workflow.active', ['tab' => 'agency']))->assertOk()->assertSee('Liv.52 LBL');
        $this->actingAs($agencyUser)->post(route('work-tasks.complete', $task), ['file' => UploadedFile::fake()->create('v2.pdf', 10)])->assertRedirect();

        $this->assertSame('approved_with_changes_pending', $document->fresh()->status, 'Waits for the Brand Manager to confirm.');
        Notification::assertSentTo($owner, DocumentActionNotification::class, fn ($n) => $n->event === 'work_task_completed');

        $this->actingAs($owner)->post(route('documents.submit', $document))->assertRedirect();
        $this->assertSame('in_review', $document->fresh()->status);
        $this->assertTrue($instance->fresh()->pendingAssignees()->where('user_id', $tm->id)->where('workflow_stage_id', $s['TM_AGM']->id)->exists(), 'Back to the stage that rejected it.');
    }

    // ---- Draft alignment: to and fro with the Content Manager, then the BM triggers ----

    public function test_draft_alignment_loops_with_the_content_manager_then_the_brand_manager_commissions_the_creative_and_starts_review(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $cm = $this->userWithRole('content-manager');
        $designer = $this->userWithRole('design-team');
        $tm = $this->userWithRole('tmagm');
        $s = $this->flow([
            ['DRAFT_CM', 'content-manager', 'draft', null],
            ['TM_AGM', 'tmagm', 'review', null],
        ]);
        $document = $this->document($s['DRAFT_CM'], $owner, 'draft.docx');
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        // Needs changes -> back to the BM -> revised -> back to the Content Manager.
        $this->engine->recordDecision($instance, $cm, 'approved_with_changes', 'Tighten the headline.');
        $this->assertSame('approved_with_changes_pending', $document->fresh()->status);
        $this->actingAs($owner)->post(route('documents.submit', $document), ['file' => UploadedFile::fake()->create('draft-v2.docx', 10)])->assertRedirect();
        $this->assertTrue($instance->fresh()->pendingAssignees()->where('user_id', $cm->id)->where('workflow_stage_id', $s['DRAFT_CM']->id)->exists());

        // Aligned -> back with the BM; nothing goes to the Design Team or TM/AGM on its own.
        $this->engine->recordDecision($instance->fresh(), $cm, 'approved');
        $this->assertSame('draft_aligned', $document->fresh()->status);
        $this->assertFalse($instance->fresh()->pendingAssignees()->exists());
        $this->assertSame(0, DocumentWorkTask::count());
        Notification::assertSentTo($owner, DocumentActionNotification::class, fn ($n) => $n->event === 'draft_aligned');

        // BM commissions the creative from the Design Team; the upload comes back to them.
        $this->actingAs($owner)->post(route('documents.work-tasks.store', $document), ['team' => 'design'])->assertRedirect();
        $task = DocumentWorkTask::firstOrFail();
        $this->assertSame('creative', $task->type);
        $this->actingAs($designer)->post(route('work-tasks.complete', $task), ['file' => UploadedFile::fake()->create('creative.pdf', 10)])->assertRedirect();
        $this->assertSame('draft_aligned', $document->fresh()->status);

        $this->actingAs($owner)->post(route('documents.submit', $document))->assertRedirect();
        $this->assertSame('in_review', $document->fresh()->status);
        $this->assertTrue($instance->fresh()->pendingAssignees()->where('user_id', $tm->id)->exists());
        $this->assertSame($document->fresh()->current_version_id, $instance->fresh()->document_version_id, 'Review runs on the creative.');
    }

    // ---- Comments: drafts until submitted, then locked ----

    public function test_reviewer_comments_stay_draft_until_they_submit_then_they_are_locked(): void
    {
        $owner = User::factory()->create();
        $tm = $this->userWithRole('tmagm');
        $rnd = $this->userWithRole('rd');
        $this->userWithRole('regulatory');
        $this->userWithRole('legal');
        $s = $this->mlrFlow();
        $document = $this->document($s['TM_AGM'], $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->actingAs($tm)->postJson(route('documents.comments.store', $document), ['body' => 'Check the dosage line.'])
            ->assertOk()->assertJsonPath('comment.draft', true);
        $comment = DocumentComment::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($comment->published_at);
        Notification::assertNothingSentTo($owner);

        // Invisible to everyone else while it's a draft.
        Auth::login($owner);
        $this->assertSame(0, DocumentComment::where('document_id', $document->id)->count());
        $this->actingAs($owner)->get(route('documents.show', $document))->assertOk()->assertDontSee('Check the dosage line.');
        $this->actingAs($tm)->get(route('documents.show', $document))->assertOk()->assertSee('Check the dosage line.');

        // Submitting the review publishes it...
        $this->engine->recordDecision($instance, $tm, 'approved');
        $this->assertNotNull($comment->fresh()->published_at);
        $this->actingAs($owner)->get(route('documents.show', $document))->assertSee('Check the dosage line.');

        // ... and locks TM/AGM out while the job is with R&D.
        $this->actingAs($tm)->postJson(route('documents.comments.store', $document), ['body' => 'One more thing'])->assertForbidden();
        $this->actingAs($tm)->patchJson(route('documents.comments.update', [$document, $comment]), ['body' => 'Edited'])->assertForbidden();
        $this->assertSame('viewer', \App\Http\Controllers\WordReviewController::editorRole($document->fresh(), $tm));
        $this->actingAs($rnd)->postJson(route('documents.comments.store', $document), ['body' => 'R&D note'])->assertOk();
        $this->actingAs($owner)->postJson(route('documents.comments.store', $document), ['body' => 'Noted'])->assertOk()->assertJsonPath('comment.draft', false);
    }

    // ---- Chairperson: offline sign-off uploaded by the Content Manager ----

    public function test_offline_chairperson_sign_off_needs_the_signed_copy_and_ends_approved_for_production(): void
    {
        $owner = User::factory()->create();
        $cm = $this->userWithRole('content-manager');
        $s = $this->flow([['CHAIRPERSON', 'content-manager', 'offline', null]], ['completion_status' => 'approved_for_production']);
        $document = $this->document($s['CHAIRPERSON'], $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        // Approved for Production can't be set by hand before the Chairperson.
        $this->actingAs($owner)->post(route('documents.status.update', $document), ['status' => 'approved_for_production'])->assertSessionHasErrors('status');

        $this->actingAs($cm)->post(route('approvals.act', $instance), ['decision' => 'approved'])->assertSessionHasErrors('signed_copy');
        $this->assertSame('in_review', $document->fresh()->status);

        $this->actingAs($cm)->post(route('approvals.act', $instance), [
            'decision' => 'approved',
            'signed_copy' => UploadedFile::fake()->create('chairperson-signed.pdf', 10),
        ])->assertRedirect();

        $document->refresh();
        $this->assertSame('approved_for_production', $document->status);
        $this->assertSame('chairperson-signed.pdf', $document->currentVersion->original_filename);
        $this->assertSame($document->current_version_id, $document->approvalActions()->first()->document_version_id);

        // Released for distribution afterwards.
        $this->actingAs($owner)->post(route('documents.status.update', $document), ['status' => 'approved_for_distribution'])->assertRedirect();
        $this->assertSame('approved_for_distribution', $document->fresh()->status);
    }

    // ---- Upload form ----

    public function test_reference_documents_can_be_attached_while_uploading_and_placeholders_wait_for_the_brand_manager(): void
    {
        $owner = User::factory()->create();
        $this->userWithRole('design-team');
        $s = $this->mlrFlow();

        $this->actingAs($owner)->post(route('documents.store'), [
            'title' => 'Print input', 'workflow_template_id' => $s['TM_AGM']->workflow_template_id,
            'file' => UploadedFile::fake()->create('input.pdf', 5),
            'reference_files' => [UploadedFile::fake()->create('study.pdf', 5), UploadedFile::fake()->create('pack.pdf', 5)],
        ])->assertRedirect();
        $this->assertSame(['pack', 'study'], Document::firstWhere('title', 'Print input')->referenceAttachments()->orderBy('title')->pluck('title')->all());

        // A placeholder no longer goes to the Design Team unless the Brand Manager says so.
        $this->actingAs($owner)->post(route('documents.store'), [
            'title' => 'Placeholder job', 'workflow_template_id' => $s['TM_AGM']->workflow_template_id,
            'is_placeholder' => '1', 'design_instructions' => 'A3 poster',
        ])->assertRedirect();
        $this->assertSame(0, DocumentWorkTask::count());
    }

    // ---- Library ----

    public function test_the_library_lists_every_job_the_user_can_see(): void
    {
        $owner = User::factory()->create();
        $tm = $this->userWithRole('tmagm');
        $stranger = User::factory()->create();
        $s = $this->mlrFlow();
        $inFlight = $this->document($s['TM_AGM'], $owner);
        $this->engine->start($inFlight, $inFlight->currentVersion, $owner);
        $approved = $this->document($s['TM_AGM'], $owner);
        $approved->update(['title' => 'Approved Dangler', 'status' => 'approved_for_distribution']);

        $this->actingAs($tm)->get(route('library.folders.index', ['view' => 'jobs']))
            ->assertOk()->assertSee($inFlight->reference_no)->assertSee('Approved Dangler');
        $this->actingAs($stranger)->get(route('library.folders.index', ['view' => 'jobs']))
            ->assertOk()->assertDontSee($inFlight->reference_no)->assertSee('Approved Dangler');
    }

    // ---- Seeded workflows ----

    public function test_seeder_turns_pharma_workflow_one_into_parallel_mlr_with_rnd_and_an_offline_chairperson(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['email' => 'admin@globalspace.in']);
        $this->seed(PharmaWorkflowSeeder::class);
        $this->seed(HimalayaUatRoundTwoSeeder::class);
        $this->seed(HimalayaUatRoundTwoSeeder::class); // re-runnable

        $template = WorkflowTemplate::where('family_code', 'WF1')->where('is_active', true)->firstOrFail();
        $this->assertSame(2, $template->version);
        $this->assertSame('approved_for_production', $template->completion_status);
        $stages = collect($template->stages()->get()->keyBy('code')->all());
        $this->assertSame('R&D', $stages['RND']->name);
        $this->assertSame(['MLR'], $stages->only(['REG_L1', 'REG_L2', 'LEGAL_L1', 'LEGAL_L2', 'RND'])->pluck('parallel_group')->unique()->values()->all());
        $this->assertTrue($stages['CHAIRPERSON']->isOffline());
        $this->assertFalse($stages->has('DESIGN_FINAL'));
        $this->assertSame('next_stage', $stages['TM_AGM']->transitionFor('approved_with_changes')->outcome_type);
        $this->assertSame('return_to_owner', $stages['CONTENT_MGR']->transitionFor('not_approved')->outcome_type);
    }
}
