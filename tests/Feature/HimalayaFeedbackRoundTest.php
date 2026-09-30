<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentWorkTask;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use App\Notifications\DocumentActionNotification;
use App\Notifications\SupportTicketNotification;
use App\Services\DocumentVersionService;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Himalaya UAT feedback round: stakeholder-only visibility, MLR/Brand Manager
 * permissions, reassignment and due dates, Design Team placeholders and rework,
 * comment editing / replacement text, due-soon alerts, helpdesk.
 */
class HimalayaFeedbackRoundTest extends TestCase
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
        $user->roles()->attach(Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)])->id);

        return $user->fresh('roles');
    }

    /**
     * Two-stage template: stage 1 (role content-manager) -> stage 2 (named user),
     * AwC/NA back to the owner.
     *
     * @return array{0: WorkflowTemplate, 1: WorkflowStage, 2: WorkflowStage}
     */
    protected function template(User $stage2User): array
    {
        $template = WorkflowTemplate::create(['name' => 'T', 'code' => 'T' . uniqid(), 'is_active' => true, 'owner_can_customize_workflow' => true, 'created_by' => $stage2User->id]);
        $s1 = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 1, 'name' => 'Content Manager Review', 'code' => 'CM', 'approval_mode' => 'any_one']);
        $s2 = WorkflowStage::create(['workflow_template_id' => $template->id, 'sequence_no' => 2, 'name' => 'Final', 'code' => 'FINAL', 'approval_mode' => 'any_one', 'is_final_distribution_stage' => true]);
        WorkflowStageApprover::create(['workflow_stage_id' => $s1->id, 'role_id' => Role::firstOrCreate(['slug' => 'content-manager'], ['name' => 'Content Manager'])->id]);
        WorkflowStageApprover::create(['workflow_stage_id' => $s2->id, 'user_id' => $stage2User->id]);
        WorkflowTransition::create(['workflow_stage_id' => $s1->id, 'decision' => 'approved', 'outcome_type' => 'next_stage']);
        WorkflowTransition::create(['workflow_stage_id' => $s2->id, 'decision' => 'approved', 'outcome_type' => 'complete_approved']);
        foreach ([$s1, $s2] as $s) {
            foreach (['approved_with_changes', 'not_approved'] as $d) {
                WorkflowTransition::create(['workflow_stage_id' => $s->id, 'decision' => $d, 'outcome_type' => 'return_to_owner', 'resume_at_stage_id' => $s->id]);
            }
        }

        return [$template, $s1, $s2];
    }

    protected function document(WorkflowTemplate $template, User $owner, array $attrs = []): Document
    {
        $document = Document::create($attrs + [
            'title' => 'Liv.52 Dangler',
            'reference_no' => 'REF-' . uniqid(),
            'owner_id' => $owner->id,
            'workflow_template_id' => $template->id,
            'status' => 'draft',
        ]);
        app(DocumentVersionService::class)->storeNewVersion($document, UploadedFile::fake()->create('art.pdf', 10, 'application/pdf'), $owner);

        return $document->fresh();
    }

    // ---- visibility ----

    public function test_a_job_in_workflow_is_visible_only_to_its_stakeholders_and_approved_work_to_everyone(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $cmA = $this->userWithRole('content-manager');
        $cmB = $this->userWithRole('content-manager');
        $stranger = User::factory()->create();
        [$template, $s1] = $this->template($final);
        $document = $this->document($template, $owner);

        $this->actingAs($stranger)->get(route('documents.show', $document))->assertForbidden();
        $this->actingAs($cmA)->get(route('documents.show', $document))->assertOk();
        $this->assertFalse(Document::visibleTo($stranger)->whereKey($document->id)->exists());
        $this->actingAs($stranger)->get(route('documents.index'))->assertDontSee($document->reference_no);

        // Once the owner picks Jalba-or-Rathna, the other Content Manager drops out.
        $document->stageApproverSelections()->create(['workflow_stage_id' => $s1->id, 'user_id' => $cmA->id]);
        $this->assertTrue($document->fresh()->isVisibleTo($cmA));
        $this->assertFalse($document->fresh()->isVisibleTo($cmB));

        // Approved material is the shared library.
        $document->update(['status' => 'approved_for_distribution']);
        $this->actingAs($stranger)->get(route('documents.show', $document))->assertOk();
    }

    // ---- MLR & Brand Manager permissions ----

    public function test_mlr_reviewers_cannot_upload_but_can_comment(): void
    {
        $owner = User::factory()->create();
        $medical = $this->userWithRole('medical');
        [$template, , $s2] = $this->template($medical);
        $document = $this->document($template, $owner);

        $this->actingAs($medical)->get(route('documents.create'))->assertForbidden();
        $this->actingAs($medical)->post(route('documents.store'), ['title' => 'x', 'file' => UploadedFile::fake()->create('x.pdf', 5)])->assertForbidden();

        $cm = $this->userWithRole('content-manager');
        $this->engine->start($document, $document->currentVersion, $owner);
        $this->engine->recordDecision($document->fresh()->activeWorkflowInstance, $cm, 'approved');

        // Medical now holds the Final stage - still no upload rights.
        $this->assertFalse($medical->can('uploadVersion', $document->fresh()));
        $this->actingAs($medical)->post(route('documents.versions.store', $document), ['file' => UploadedFile::fake()->create('v2.pdf', 5)])->assertForbidden();
        $this->actingAs($medical)->postJson(route('documents.comments.store', $document), ['body' => 'Please add the reference.'])->assertOk();
    }

    public function test_other_stage_holders_can_upload_a_new_version_mid_review_and_reviewers_see_it(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $cm = $this->userWithRole('content-manager');
        [$template] = $this->template($final);
        $document = $this->document($template, $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->actingAs($cm)->post(route('documents.versions.store', $document), ['file' => UploadedFile::fake()->create('fixed.pdf', 5, 'application/pdf')])->assertRedirect();

        $this->assertSame(2, $document->versions()->count());
        $this->assertSame($document->fresh()->current_version_id, $instance->fresh()->document_version_id);
    }

    public function test_brand_managers_are_never_given_decision_tasks_and_cannot_decide(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $bm = $this->userWithRole('content-manager');
        $bm->roles()->detach();
        $bm->roles()->attach(Role::firstOrCreate(['slug' => 'brand-manager'], ['name' => 'Brand Manager'])->id);
        $cm = $this->userWithRole('content-manager');
        [$template, $s1] = $this->template($final);
        // A Brand Manager named directly on the stage is skipped when tasks are handed out.
        WorkflowStageApprover::create(['workflow_stage_id' => $s1->id, 'user_id' => $bm->id]);

        $document = $this->document($template, $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->assertEqualsCanonicalizing([$cm->id], $instance->pendingAssignees()->pluck('user_id')->all());
        $this->assertFalse($bm->fresh('roles')->canRecordDecisions());
    }

    // ---- reassignment & due dates ----

    public function test_tasks_get_the_owners_due_period_and_the_owner_can_reassign_them(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $cm = $this->userWithRole('content-manager');
        $backup = User::factory()->create();
        [$template, $s1] = $this->template($final);
        $document = $this->document($template, $owner);
        $document->stageSettings()->create(['workflow_stage_id' => $s1->id, 'due_hours' => 24]);

        $this->travelTo(now()->startOfMinute());
        $instance = $this->engine->start($document, $document->currentVersion, $owner);
        $task = $instance->pendingAssignees()->first();
        $this->assertTrue($task->due_at->equalTo(now()->addHours(24)));

        // Only the task owner may reassign.
        $this->actingAs($cm)->post(route('documents.tasks.reassign', [$document, $task]), ['user_id' => $backup->id])->assertForbidden();
        $this->actingAs($owner)->post(route('documents.tasks.reassign', [$document, $task]), ['user_id' => $backup->id, 'reason' => 'On leave'])->assertRedirect();

        $this->assertSame('reassigned', $task->fresh()->status);
        $new = $instance->pendingAssignees()->first();
        $this->assertSame($backup->id, $new->user_id);
        $this->assertTrue($new->due_at->equalTo($task->due_at));

        // The new person can decide; the job moves on.
        $this->engine->recordDecision($instance->fresh(), $backup, 'approved');
        $this->assertSame('Final', $instance->fresh()->currentStage->name);
    }

    public function test_due_soon_reminder_is_sent_once_and_overdue_alert_after(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $cm = $this->userWithRole('content-manager');
        [$template] = $this->template($final);
        $document = $this->document($template, $owner);
        $this->engine->start($document, $document->currentVersion, $owner);

        $this->travel(40)->hours(); // 8h left of 48
        $this->artisan('documents:flag-overdue-approvals')->assertSuccessful();
        $this->artisan('documents:flag-overdue-approvals')->assertSuccessful();
        Notification::assertSentToTimes($cm, DocumentActionNotification::class, 2); // stage_assigned + 1 due-soon
        Notification::assertSentTo($cm, DocumentActionNotification::class, fn ($n) => $n->event === 'due_soon_reminder');

        $this->travel(10)->hours();
        $this->artisan('documents:flag-overdue-approvals')->assertSuccessful();
        Notification::assertSentTo($cm, DocumentActionNotification::class, fn ($n) => $n->event === 'overdue_reminder');
    }

    // ---- Design Team: placeholders and rework ----

    public function test_a_placeholder_goes_to_the_design_team_and_their_upload_fills_it(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $designerA = $this->userWithRole('design-team');
        $designerB = $this->userWithRole('design-team');
        [$template] = $this->template($final);

        $this->actingAs($owner)->post(route('documents.store'), [
            'title' => 'Hadjod Retailer Poster',
            'workflow_template_id' => $template->id,
            'channel' => 'print',
            'is_placeholder' => '1',
            'design_instructions' => 'A3 poster, key claim from the brief.',
            'due_date' => now()->addMonths(18)->toDateString(), // may be past expiry
            'expiry_date' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Hadjod Retailer Poster');
        $this->assertTrue($document->is_placeholder);
        $this->assertNull($document->current_version_id);
        $task = DocumentWorkTask::firstOrFail();
        $this->assertNull($task->assigned_to);
        Notification::assertSentTo([$designerA, $designerB], DocumentActionNotification::class, fn ($n) => $n->event === 'work_task_created');

        // Team member assigns it to a designer, who uploads.
        $this->actingAs($designerA)->post(route('work-tasks.assign', $task), ['user_id' => $designerB->id])->assertRedirect();
        $this->actingAs($designerB)->post(route('work-tasks.complete', $task), ['file' => UploadedFile::fake()->create('poster.pdf', 20, 'application/pdf')])->assertRedirect();

        $document->refresh();
        $this->assertFalse($document->is_placeholder);
        $this->assertNotNull($document->current_version_id);
        $this->assertSame('completed', $task->fresh()->status);
        Notification::assertSentTo($owner, DocumentActionNotification::class, fn ($n) => $n->event === 'work_task_completed');
    }

    public function test_after_approved_with_changes_the_owner_can_send_rework_to_the_design_team_and_it_moves_on(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $cm = $this->userWithRole('content-manager');
        $designer = $this->userWithRole('design-team');
        [$template] = $this->template($final);
        $document = $this->document($template, $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->engine->recordDecision($instance, $cm, 'approved_with_changes', 'Fix the logo size.');
        $this->assertSame('approved_with_changes_pending', $document->fresh()->status);
        Notification::assertSentTo($owner, DocumentActionNotification::class, fn ($n) => $n->event === 'revision_needed');

        $this->actingAs($owner)->post(route('documents.work-tasks.store', $document), [
            'instructions' => 'Fix the logo size.',
            'resubmit_on_upload' => '1',
        ])->assertRedirect();

        $task = DocumentWorkTask::firstOrFail();
        $this->actingAs($designer)->post(route('work-tasks.complete', $task), ['file' => UploadedFile::fake()->create('v2.pdf', 20, 'application/pdf')])->assertRedirect();

        // Straight on to the next stage, without going back to the Content Manager.
        $instance = $instance->fresh();
        $this->assertSame('in_review', $document->fresh()->status);
        $this->assertSame('Final', $instance->currentStage->name);
        $this->assertSame($final->id, $instance->pendingAssignees()->value('user_id'));
    }

    // ---- review: comment edit, replacement text ----

    public function test_comment_author_can_edit_and_replacement_text_is_stored(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $other = User::factory()->create();
        [$template] = $this->template(User::factory()->create());
        $document = $this->document($template, $owner);
        $document->watchers()->attach([$reviewer->id, $other->id]);

        $this->actingAs($reviewer)->postJson(route('documents.comments.store', $document), [
            'body' => 'Wrong strength',
            'page_number' => 1, 'x_position' => 10, 'y_position' => 10,
            'selected_text' => '500mg', 'replacement_text' => '250 mg',
            'highlight_rects' => [['x' => 10, 'y' => 10, 'w' => 5, 'h' => 2]],
        ])->assertOk();
        // An area highlight on artwork: boxes but no text.
        $this->actingAs($reviewer)->postJson(route('documents.comments.store', $document), [
            'body' => 'Logo too small', 'page_number' => 1, 'x_position' => 50, 'y_position' => 50,
            'highlight_rects' => [['x' => 50, 'y' => 50, 'w' => 20, 'h' => 10]],
        ])->assertOk();

        $comment = DocumentComment::firstWhere('body', 'Wrong strength');
        $this->assertSame('250 mg', $comment->replacement_text);

        $url = route('documents.comments.update', [$document, $comment]);
        $this->actingAs($other)->patchJson($url, ['body' => 'hijack'])->assertForbidden();
        $this->actingAs($reviewer)->patchJson($url, ['body' => 'Wrong strength - should be 250 mg', 'replacement_text' => '250 mg tablets'])->assertOk();

        $comment->refresh();
        $this->assertSame('250 mg tablets', $comment->replacement_text);
        $this->assertNotNull($comment->edited_at);
    }

    // ---- approvals, active workflow, helpdesk ----

    public function test_decisions_need_no_password_by_default(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $cm = $this->userWithRole('content-manager');
        [$template] = $this->template($final);
        $document = $this->document($template, $owner);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->actingAs($cm)->post(route('approvals.act', $instance), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('Final', $instance->fresh()->currentStage->name);
    }

    public function test_active_workflow_shows_what_needs_action(): void
    {
        $owner = User::factory()->create();
        $final = User::factory()->create();
        $cm = $this->userWithRole('content-manager');
        [$template] = $this->template($final);
        $document = $this->document($template, $owner, ['title' => 'Pilex LBL']);
        $instance = $this->engine->start($document, $document->currentVersion, $owner);

        $this->actingAs($cm)->get(route('workflow.active'))->assertOk()->assertSee('Pilex LBL');

        $this->engine->recordDecision($instance, $cm, 'approved_with_changes', 'Tweak');
        $this->actingAs($owner)->get(route('workflow.active'))->assertOk()->assertSee('Revisions waiting on you')->assertSee('Pilex LBL');
        $this->actingAs($owner)->get(route('workflow.active', ['tab' => 'mine']))->assertOk()->assertSee('revision needed');
        $this->actingAs($owner)->get(route('documents.show', $document))->assertOk()->assertSee('Send to Design Team');
    }

    public function test_helpdesk_ticket_is_logged_and_emailed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('helpdesk.store'), [
            'category' => 'technical_issue', 'priority' => 'high',
            'subject' => 'Flow stuck', 'description' => 'GL-VS-202609-MVWMXB is not moving.',
        ])->assertRedirect(route('helpdesk.index'));

        $ticket = SupportTicket::firstOrFail();
        $this->assertTrue($ticket->first_response_due_at->isFuture());
        Notification::assertSentOnDemand(SupportTicketNotification::class);
        Notification::assertSentTo($user, SupportTicketNotification::class);
    }
}
