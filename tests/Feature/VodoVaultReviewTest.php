<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentAgency;
use App\Models\Country;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentWorkTask;
use App\Models\Role;
use App\Models\Sku;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageApprover;
use App\Models\WorkflowTemplate;
use App\Notifications\DocumentActionNotification;
use App\Services\DocumentVersionService;
use App\Services\WordCommentExtractor;
use App\Services\WorkflowEngine;
use App\Services\WorkTaskService;
use App\Support\Workflow\TransitionWiring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

/**
 * MOM - VODO Vault review with Himalaya (24 Nov 2026): master data dropdowns, IST,
 * empty stages hidden, auto / editable due dates, re-review from TM after a later
 * rejection, comments locked after approval, Chairperson auto-selected and the
 * Brand Manager's closure, the Content Agency restructure, and Word comments
 * shown version-wise.
 */
class VodoVaultReviewTest extends TestCase
{
    use RefreshDatabase;

    protected WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        // The Pharma R&D / Legal / Regulatory Affairs rule has its own test (VodoVaultReviewTest).
        config(['promomats.workflow.required_stakeholders' => []]);
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

    protected function admin(): User
    {
        return $this->userWithRole('admin');
    }

    /** @return array<string, WorkflowStage> */
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
                'is_final_distribution_stage' => $i === count($plan) - 1, 'sla_hours' => 48,
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

    protected function pick(Document $document, WorkflowStage $stage, User $user): void
    {
        $document->stageApproverSelections()->create(['workflow_stage_id' => $stage->id, 'user_id' => $user->id]);
    }

    // ---- 1. Master data & document fields ----

    public function test_admin_manages_countries_and_brand_skus_including_bulk_paste(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.countries.store'), ['name' => 'India', 'code' => 'in'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.countries.bulk'), ['lines' => "Nepal, NP\nIndia, IN\nSri Lanka"])->assertRedirect();
        $this->assertEqualsCanonicalizing(['India', 'Nepal', 'Sri Lanka'], Country::pluck('name')->all());
        $this->assertSame('IN', Country::where('name', 'India')->value('code'));

        $liv = Brand::create(['name' => 'Liv.52', 'code' => 'LIV52', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.skus.store'), ['brand_id' => $liv->id, 'name' => 'Liv.52 DS 60s'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.skus.bulk'), ['lines' => "Liv.52, Liv.52 Syrup 200ml, L52S\nHimalaya Baby, Baby Lotion 100ml\nLiv.52, Liv.52 DS 60s\nbroken line"])
            ->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, '2 SKU(s) added') && str_contains($m, '1 new brand(s)'));
        $this->assertSame(['Liv.52 DS 60s', 'Liv.52 Syrup 200ml'], $liv->skus()->pluck('name')->all());
        $this->assertTrue(Brand::where('name', 'Himalaya Baby')->first()->skus()->where('name', 'Baby Lotion 100ml')->exists());

        $this->actingAs($admin)->get(route('admin.skus.index', ['brand' => $liv->id]))->assertOk()->assertSee('Liv.52 Syrup 200ml')->assertDontSee('Baby Lotion');
        $this->actingAs($admin)->get(route('admin.countries.index'))->assertOk()->assertSee('Sri Lanka');
        $this->actingAs($admin)->get(route('admin.brands.index'))->assertOk()->assertSee('SKUs');
        $this->actingAs($admin)->get(route('admin.content-agencies.index'))->assertOk()->assertSee('Add Content Agency');
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();

        // Non-admins can't reach the masters.
        $this->actingAs($this->userWithRole('brand-manager'))->get(route('admin.skus.index'))->assertForbidden();
    }

    public function test_upload_uses_category_sku_by_brand_and_country_dropdowns(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null]]);
        $liv = Brand::create(['name' => 'Liv.52', 'code' => 'LIV52', 'status' => 'active']);
        $baby = Brand::create(['name' => 'Baby', 'code' => 'BABY', 'status' => 'active']);
        $sku = Sku::create(['brand_id' => $liv->id, 'name' => 'Liv.52 DS 60s', 'status' => 'active']);
        $babySku = Sku::create(['brand_id' => $baby->id, 'name' => 'Baby Lotion', 'status' => 'active']);
        $india = Country::create(['name' => 'India', 'code' => 'IN', 'status' => 'active']);

        $this->actingAs($owner)->get(route('documents.create'))->assertOk()
            ->assertSee('Non-Promotional')->assertSee('SKU')->assertSee('India');

        $base = ['title' => 'LBL', 'workflow_template_id' => $s['TM_AGM']->workflow_template_id, 'brand_id' => $liv->id, 'country_id' => $india->id,
            'stage_approvers' => [$s['TM_AGM']->id => [$tm->id]], 'file' => UploadedFile::fake()->create('a.pdf', 5)];

        $this->actingAs($owner)->post(route('documents.store'), $base + ['category' => 'Promotional Material', 'sku_id' => $sku->id])->assertSessionHasErrors('category');
        $this->actingAs($owner)->post(route('documents.store'), $base + ['category' => 'Promotional', 'sku_id' => $babySku->id])->assertSessionHasErrors('sku_id');

        $this->actingAs($owner)->post(route('documents.store'), $base + ['category' => 'Promotional', 'sku_id' => $sku->id])->assertRedirect();
        $doc = Document::latest('id')->first();
        $this->assertSame($sku->id, $doc->sku_id);
        $this->assertSame($india->id, $doc->country_id);
        $this->assertSame(['Liv.52 DS 60s'], $doc->products);
        $this->assertSame(['India'], $doc->countries);
        $this->assertStringStartsWith('IN-PR-', $doc->reference_no);

        $this->actingAs($owner)->get(route('documents.show', $doc))->assertOk()->assertSee('Liv.52 DS 60s')->assertSee('India');
    }

    public function test_workflows_and_jobs_are_divided_into_pharma_and_scientific_publications(): void
    {
        $admin = $this->admin();
        $pharmaFlow = $this->flow([['TM_AGM', 'tmagm', 'review', null]]);
        $pubFlow = $this->flow([['SP_REVIEW', 'project-lead', 'review', null]], ['name' => 'SciPub Proofs', 'team' => 'publication']);
        $lead = $this->userWithRole('project-lead');
        $tm = $this->userWithRole('tmagm');
        $brand = Brand::create(['name' => 'Liv.52', 'code' => 'LIV52', 'status' => 'active']);

        // Publications people start on their own team; everyone else on Pharma.
        $publisher = $this->userWithRole('content-developer', ['department' => 'Scientific Publications']);
        $this->actingAs($publisher)->get(route('documents.create'))->assertOk()
            ->assertSee('Scientific Publications')->assertSee("team: 'publication'", false)->assertSee('searchSelect(', false)->assertSee('Search brand', false);
        $owner = $this->userWithRole('brand-manager', ['department' => 'Marketing']);
        $this->actingAs($owner)->get(route('documents.create'))->assertOk()->assertSee("team: 'pharma'", false);

        // A Publications job can't use a Pharma workflow, and drops Pharma-only fields.
        $base = ['title' => 'Monograph', 'team' => 'publication', 'brand_id' => $brand->id, 'category' => 'Promotional', 'file' => UploadedFile::fake()->create('m.docx', 5)];
        $this->actingAs($publisher)->post(route('documents.store'), $base + ['workflow_template_id' => $pharmaFlow['TM_AGM']->workflow_template_id])
            ->assertSessionHasErrors('workflow_template_id');
        $this->actingAs($publisher)->post(route('documents.store'), $base + [
            'workflow_template_id' => $pubFlow['SP_REVIEW']->workflow_template_id,
            'stage_approvers' => [$pubFlow['SP_REVIEW']->id => [$lead->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $doc = Document::latest('id')->first();
        $this->assertSame('publication', $doc->team);
        $this->assertNull($doc->brand_id);
        $this->assertNull($doc->category);
        $this->actingAs($publisher)->get(route('documents.show', $doc))->assertOk()->assertSee('Scientific Publications')->assertDontSee('Brand / SKU');

        // Pharma jobs keep their fields.
        $this->actingAs($owner)->post(route('documents.store'), ['title' => 'LBL', 'team' => 'pharma', 'brand_id' => $brand->id,
            'workflow_template_id' => $pharmaFlow['TM_AGM']->workflow_template_id, 'stage_approvers' => [$pharmaFlow['TM_AGM']->id => [$tm->id]],
            'file' => UploadedFile::fake()->create('a.pdf', 5)])->assertRedirect();
        $this->assertSame($brand->id, Document::latest('id')->first()->brand_id);

        // Lists filter by team; the admin sets each workflow's team.
        $this->actingAs($admin)->get(route('documents.index', ['team' => 'publication']))->assertOk()->assertSee('Monograph')->assertDontSee('>LBL<', false);
        $this->actingAs($admin)->get(route('admin.workflows.index', ['team' => 'publication']))->assertOk()->assertSee('SciPub Proofs')->assertDontSee('>Flow<', false);
        $this->actingAs($admin)->post(route('admin.workflows.settings.update', $pharmaFlow['TM_AGM']->template), ['team' => 'publication'])->assertRedirect();
        $this->assertSame('publication', $pharmaFlow['TM_AGM']->template->fresh()->team);
    }

    public function test_the_whole_system_runs_on_ist(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        $this->assertSame('+05:30', now()->format('P'));
    }

    // ---- 2. Workflow & approval matrix ----

    public function test_stages_with_nobody_selected_are_skipped_and_hidden_from_the_matrix(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $cm = $this->userWithRole('content-manager');
        $this->userWithRole('tmagm');
        $legal = $this->userWithRole('legal');
        $s = $this->flow([['CONTENT_MGR', 'content-manager', 'review', null], ['TM_AGM', 'tmagm', 'review', null], ['LEGAL', 'legal', 'review', null]]);
        $doc = $this->document($s['CONTENT_MGR'], $owner);
        $this->pick($doc, $s['CONTENT_MGR'], $cm);
        $this->pick($doc, $s['LEGAL'], $legal);

        $page = $this->actingAs($owner)->get(route('documents.show', $doc))->assertOk();
        $page->assertSee('Not in this job\'s approval matrix (no stakeholder selected): TM_AGM', false);

        $instance = $this->engine->start($doc, $doc->currentVersion, $owner);
        $this->engine->recordDecision($instance, $cm, 'approved');
        $this->assertSame([$legal->id], $instance->fresh()->pendingAssignees()->pluck('user_id')->all(), 'TM had nobody selected - skipped.');

        // A stage whose role nobody holds is skipped rather than stopping the workflow.
        $s2 = $this->flow([['A', 'content-manager', 'review', null], ['B', 'role-nobody-has', 'review', null]]);
        $doc2 = $this->document($s2['A'], $owner);
        $i2 = $this->engine->start($doc2, $doc2->currentVersion, $owner);
        $this->engine->recordDecision($i2, $cm, 'approved');
        $this->assertSame('approved_for_distribution', $doc2->fresh()->status);

        // ... but a job where no stage reaches anyone can't start.
        $s3 = $this->flow([['X', 'role-nobody-has', 'review', null]]);
        $doc3 = $this->document($s3['X'], $owner);
        $this->expectException(ValidationException::class);
        $this->engine->start($doc3, $doc3->currentVersion, $owner);
    }

    public function test_overall_due_date_is_worked_out_from_the_selected_stages_and_stage_due_dates_are_editable_with_notifications(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $rnd = $this->userWithRole('rd');
        $reg = $this->userWithRole('regulatory');
        $cp = $this->userWithRole('content-manager');
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null], ['RND', 'rd', 'review', 'MLR'], ['REG', 'regulatory', 'review', 'MLR'], ['FINAL', 'content-manager', 'review', null]]);

        $this->actingAs($owner)->post(route('documents.store'), [
            'title' => 'Job', 'workflow_template_id' => $s['TM_AGM']->workflow_template_id,
            'stage_approvers' => [$s['TM_AGM']->id => [$tm->id], $s['RND']->id => [$rnd->id], $s['REG']->id => [$reg->id], $s['FINAL']->id => [$cp->id]],
            'stage_due_hours' => [$s['RND']->id => 72],
            'file' => UploadedFile::fake()->create('a.pdf', 5),
        ])->assertRedirect();
        $doc = Document::latest('id')->first();
        // TM 48h + MLR (parallel: max 72h, 48h) + FINAL 48h = 168h.
        $this->assertSame(now()->addHours(168)->toDateString(), $doc->due_date->toDateString());

        $instance = $this->engine->start($doc, $doc->currentVersion, $owner);

        // Move the not-yet-reached FINAL stage; its people are told, and the overall
        // due date follows the last stage.
        $finalDue = now()->addDays(10)->setTime(17, 30);
        $this->actingAs($owner)->post(route('documents.stages.due', [$doc, $s['FINAL']]), ['due_at' => $finalDue->format('Y-m-d\TH:i')])
            ->assertRedirect()->assertSessionHasNoErrors();
        Notification::assertSentTo($cp, DocumentActionNotification::class, fn ($n) => $n->event === 'due_date_changed');
        $this->assertSame($finalDue->toDateString(), $doc->fresh()->due_date->toDateString());

        // The stage in progress: its live task moves too.
        $tmDue = now()->addDay()->setTime(12, 0);
        $this->actingAs($owner)->post(route('documents.stages.due', [$doc, $s['TM_AGM']]), ['due_at' => $tmDue->format('Y-m-d\TH:i')])->assertRedirect();
        $this->assertTrue($instance->pendingAssignees()->first()->due_at->equalTo($tmDue));
        Notification::assertSentTo($tm, DocumentActionNotification::class, fn ($n) => $n->event === 'due_date_changed');

        // Only the Brand Manager (or an admin) may move dates.
        $this->actingAs($tm)->post(route('documents.stages.due', [$doc, $s['FINAL']]), ['due_at' => $finalDue->format('Y-m-d\TH:i')])->assertForbidden();

        // When FINAL opens, its task carries the Brand Manager's date.
        $this->engine->recordDecision($instance, $tm, 'approved');
        $this->engine->recordDecision($instance, $rnd, 'approved');
        $this->engine->recordDecision($instance, $reg, 'approved');
        $this->assertTrue($instance->fresh()->pendingAssignees()->where('user_id', $cp->id)->first()->due_at->equalTo($finalDue));
    }

    public function test_a_rejection_after_tm_goes_back_through_tm_and_every_stage_after_it(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $cm = $this->userWithRole('content-manager');
        $tm = $this->userWithRole('tmagm');
        $legal = $this->userWithRole('legal');
        $s = $this->flow([['CONTENT_MGR', 'content-manager', 'review', null], ['TM_AGM', 'tmagm', 'review', null], ['LEGAL', 'legal', 'review', 'MLR'], ['FINAL', 'regulatory', 'review', null]]);
        $reg = $this->userWithRole('regulatory');
        $doc = $this->document($s['CONTENT_MGR'], $owner);
        $instance = $this->engine->start($doc, $doc->currentVersion, $owner);

        $this->engine->recordDecision($instance, $cm, 'approved');
        $this->engine->recordDecision($instance, $tm, 'approved');
        $this->engine->recordDecision($instance, $legal, 'not_approved', 'Claim not substantiated.');
        $this->assertSame($s['TM_AGM']->id, $instance->fresh()->resume_at_stage_id, 'Rejected after TM - resumes at TM.');

        $this->actingAs($owner)->get(route('documents.show', $doc))->assertSee('and through every stage after it again');

        $this->actingAs($owner)->post(route('documents.submit', $doc), ['file' => UploadedFile::fake()->create('v2.pdf', 5)])->assertRedirect();
        $this->assertSame([$tm->id], $instance->fresh()->pendingAssignees()->pluck('user_id')->all());

        // TM can comment again on the new pass, then Legal reviews again.
        $this->assertFalse($doc->fresh()->commentsLockedFor($tm));
        $this->engine->recordDecision($instance, $tm, 'approved');
        $this->assertSame([$legal->id], $instance->fresh()->pendingAssignees()->pluck('user_id')->all());

        // A rejection at TM itself (or before) still returns to that stage.
        $doc2 = $this->document($s['CONTENT_MGR'], $owner);
        $i2 = $this->engine->start($doc2, $doc2->currentVersion, $owner);
        $this->engine->recordDecision($i2, $cm, 'not_approved', 'Fix');
        $this->assertSame($s['CONTENT_MGR']->id, $i2->fresh()->resume_at_stage_id);
        unset($reg);
    }

    public function test_once_a_stage_approves_its_reviewer_can_no_longer_comment_even_after_the_workflow_ends(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null]]);
        $doc = $this->document($s['TM_AGM'], $owner);
        $instance = $this->engine->start($doc, $doc->currentVersion, $owner);

        $this->actingAs($tm)->post(route('documents.comments.store', $doc), ['body' => 'Fine'])->assertRedirect();
        $this->engine->recordDecision($instance, $tm, 'approved');
        $this->assertSame('approved_for_distribution', $doc->fresh()->status);

        $this->assertTrue($doc->fresh()->commentsLockedFor($tm), 'Locked after the stage approved, workflow finished or not.');
        $this->actingAs($tm)->post(route('documents.comments.store', $doc), ['body' => 'One more'])->assertForbidden();
        $comment = DocumentComment::where('user_id', $tm->id)->first();
        $this->actingAs($tm)->patch(route('documents.comments.update', [$doc, $comment]), ['body' => 'Edited'])->assertForbidden();
        $this->actingAs($owner)->post(route('documents.comments.store', $doc), ['body' => 'Owner note'])->assertRedirect();
    }

    public function test_chairperson_is_auto_selected_and_the_brand_manager_closes_after_approved_with_changes(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $jalba = $this->userWithRole('content-manager', ['email' => 'jalba.rc@himalayawellness.com', 'name' => 'Jalba']);
        $otherCm = $this->userWithRole('content-manager');
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null], ['CHAIRPERSON', 'content-manager', 'offline', null]], ['completion_status' => 'approved_for_production']);

        $this->actingAs($owner)->get(route('documents.create'))->assertOk()->assertSee('auto-selected');

        $doc = $this->document($s['TM_AGM'], $owner);
        $this->pick($doc, $s['TM_AGM'], $tm); // nobody picked for the Chairperson stage
        $instance = $this->engine->start($doc, $doc->currentVersion, $owner);
        $this->engine->recordDecision($instance, $tm, 'approved');

        $pending = $instance->fresh()->pendingAssignees()->pluck('user_id')->all();
        $this->assertSame([$jalba->id], $pending, 'Jalba takes the Chairperson stage automatically.');
        $this->assertNotContains($otherCm->id, $pending);

        $this->actingAs($jalba)->post(route('approvals.act', $instance), [
            'decision' => 'approved_with_changes', 'comments' => 'Change the pack shot.',
            'signed_copy' => UploadedFile::fake()->create('chair.pdf', 10),
        ])->assertRedirect();

        $doc->refresh();
        $this->assertSame('pending_closure', $doc->status);
        Notification::assertSentTo($owner, DocumentActionNotification::class, fn ($n) => $n->event === 'closure_pending' && $n->decision === 'approved_with_changes');
        $this->actingAs($owner)->get(route('documents.show', $doc))->assertOk()->assertSee('Final approval &amp; closure', false)->assertSee('Change the pack shot.');
        $this->actingAs($owner)->get(route('workflow.active'))->assertOk()->assertSee('give the final approval and close');

        // Closure by the Brand Manager only, with the corrected version.
        $this->actingAs($jalba)->post(route('documents.close', $doc))->assertForbidden();
        $this->actingAs($owner)->post(route('documents.close', $doc), ['file' => UploadedFile::fake()->create('final.pdf', 10)])->assertRedirect();

        $doc->refresh();
        $this->assertSame('approved_for_production', $doc->status);
        $this->assertSame('final.pdf', $doc->currentVersion->original_filename);
        $this->assertDatabaseHas('audit_logs', ['document_id' => $doc->id, 'action' => 'DOCUMENT_CLOSED']);

        // Then the Brand Manager submits it for distribution.
        $this->actingAs($owner)->get(route('documents.show', $doc))->assertSee('Submit for Distribution');
        $this->actingAs($owner)->post(route('documents.status.update', $doc), ['status' => 'approved_for_distribution'])->assertRedirect();
        $this->assertSame('approved_for_distribution', $doc->fresh()->status);
    }

    // ---- 3. Content Agency ----

    public function test_content_agency_master_users_scoped_work_and_same_agency_reassignment(): void
    {
        $admin = $this->admin();
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');

        $this->actingAs($admin)->post(route('admin.content-agencies.store'), ['name' => 'Ogilvy'])->assertRedirect();
        $ogilvy = ContentAgency::where('name', 'Ogilvy')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.content-agencies.users.store', $ogilvy), ['name' => 'Asha', 'email' => 'asha@ogilvy.test'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.content-agencies.users.store', $ogilvy), ['name' => 'Ravi', 'email' => 'ravi@ogilvy.test'])->assertRedirect();
        $asha = User::where('email', 'asha@ogilvy.test')->first();
        $ravi = User::where('email', 'ravi@ogilvy.test')->first();
        $this->assertSame($ogilvy->id, $asha->content_agency_id);
        $this->assertTrue($asha->fresh('roles')->isAgencyUser());

        $other = ContentAgency::create(['name' => 'Lowe', 'status' => 'active']);
        $lowe = $this->userWithRole('content-agency', ['content_agency_id' => $other->id]);
        $this->actingAs($admin)->get(route('admin.content-agencies.show', $ogilvy))->assertOk()->assertSee('Asha')->assertSee('Ravi');

        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null], ['LEGAL', 'legal', 'review', null]]);
        $this->userWithRole('legal');
        $doc = $this->document($s['TM_AGM'], $owner);
        $instance = $this->engine->start($doc, $doc->currentVersion, $owner);

        // Agency users can't see a job that isn't with their agency - not even approved library material.
        $this->actingAs($asha)->get(route('documents.show', $doc))->assertForbidden();

        $this->engine->recordDecision($instance, $tm, 'not_approved', 'Wrong claim');
        $this->actingAs($owner)->get(route('documents.show', $doc))->assertOk()->assertSee('Person at the agency')->assertSee('Ogilvy');

        // With two agencies, the Brand Manager has to say which one.
        $this->actingAs($owner)->post(route('documents.work-tasks.store', $doc), ['team' => 'agency', 'instructions' => 'Fix'])->assertSessionHasErrors('content_agency_id');
        // A person from another agency can't be picked.
        $this->actingAs($owner)->post(route('documents.work-tasks.store', $doc), ['team' => 'agency', 'content_agency_id' => $ogilvy->id, 'assign_to' => $lowe->id])->assertSessionHasErrors('assign');
        $this->assertSame(0, DocumentWorkTask::count(), 'Nothing is left half-created.');
        $this->flushSession();

        $this->actingAs($owner)->post(route('documents.work-tasks.store', $doc), [
            'team' => 'agency', 'content_agency_id' => $ogilvy->id, 'assign_to' => $asha->id, 'instructions' => 'Fix the claim',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $task = DocumentWorkTask::open()->firstOrFail();
        $this->assertSame($ogilvy->id, $task->content_agency_id);
        $this->assertSame($asha->id, $task->assigned_to);
        Notification::assertSentTo($asha, DocumentActionNotification::class, fn ($n) => $n->event === 'work_task_assigned');
        Notification::assertNotSentTo($lowe, DocumentActionNotification::class, fn ($n) => str_starts_with($n->event, 'work_task'));

        // Own login + workspace; sees the job and its comments, not the workflow.
        $this->actingAs($asha)->get(route('dashboard'))->assertRedirect(route('agency.index'));
        $this->actingAs($asha)->get(route('agency.index'))->assertOk()->assertSee('Liv.52 LBL');
        $this->actingAs($lowe)->get(route('agency.index'))->assertOk()->assertDontSee('Liv.52 LBL');
        $this->actingAs($lowe)->get(route('documents.show', $doc))->assertForbidden();

        $page = $this->actingAs($asha)->get(route('documents.show', $doc))->assertOk();
        $page->assertSee('Fix the claim')->assertSee('Upload &amp; send to Brand Manager', false)->assertSee('Reviewers\' comments', false);
        $page->assertDontSee('Workflow Status')->assertDontSee('Approval matrix')->assertDontSee('Review Actions');
        foreach (['library.folders.index', 'workflow.active', 'reports.index', 'search', 'documents.index'] as $route) {
            $this->actingAs($asha)->get(route($route))->assertRedirect(route('agency.index'));
        }
        $this->actingAs($asha)->post(route('documents.comments.store', $doc), ['body' => 'hi'])->assertForbidden();

        // Reassign only within the same agency.
        $this->actingAs($asha)->post(route('work-tasks.assign', $task), ['user_id' => $lowe->id])->assertSessionHasErrors('assign');
        $this->flushSession();
        $this->actingAs($asha)->post(route('work-tasks.assign', $task), ['user_id' => $ravi->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($ravi->id, $task->fresh()->assigned_to);
        $this->actingAs($lowe)->post(route('work-tasks.take', $task))->assertForbidden();

        // Ravi uploads; the job leaves the agency's view and comes back to the Brand Manager.
        $this->actingAs($ravi)->post(route('work-tasks.complete', $task), ['file' => UploadedFile::fake()->create('v2.pdf', 10)])
            ->assertRedirect(route('agency.index'));
        $this->assertSame('approved_with_changes_pending', $doc->fresh()->status);
        $this->actingAs($ravi)->get(route('documents.show', $doc))->assertForbidden();
        $this->actingAs($ravi)->get(route('agency.index'))->assertOk()->assertSee('Recently handed back');
    }

    public function test_with_a_single_agency_it_is_chosen_automatically(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $agency = ContentAgency::create(['name' => 'Only One', 'status' => 'active']);
        $member = $this->userWithRole('content-agency', ['content_agency_id' => $agency->id]);
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null]]);
        $doc = $this->document($s['TM_AGM'], $owner);
        $doc->update(['status' => 'approved_with_changes_pending']);

        $task = app(WorkTaskService::class)->requestRework($doc, $owner, 'Brief', null, false, null, 'agency');
        $this->assertSame($agency->id, $task->content_agency_id);
        Notification::assertSentTo($member, DocumentActionNotification::class, fn ($n) => $n->event === 'work_task_created');
    }

    // ---- 4. Document viewer & comments ----

    public function test_word_comments_are_read_from_the_docx_and_comments_are_shown_version_by_version(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null]]);
        $doc = Document::create(['title' => 'Word job', 'reference_no' => 'R-' . uniqid(), 'owner_id' => $owner->id, 'workflow_template_id' => $s['TM_AGM']->workflow_template_id, 'status' => 'draft']);
        $service = app(DocumentVersionService::class);

        $v1 = $service->storeNewVersion($doc, $this->docxWithComments([
            ['id' => 0, 'author' => 'Smriti (Regulatory)', 'text' => 'Add the reference for this claim.', 'anchor' => 'Clinically proven'],
        ]), $owner);
        $this->actingAs($tm)->post(route('documents.comments.store', $doc), ['body' => 'Comment on v1'])->assertRedirect();
        $v2 = $service->storeNewVersion($doc, $this->docxWithComments([
            ['id' => 0, 'author' => 'Aditi (Legal)', 'text' => 'Disclaimer wording is fine now.', 'anchor' => 'Consult your doctor'],
        ]), $owner);

        $extracted = app(WordCommentExtractor::class)->forVersion($v1);
        $this->assertCount(1, $extracted);
        $this->assertSame('Smriti (Regulatory)', $extracted[0]['author']);
        $this->assertSame('Add the reference for this claim.', $extracted[0]['text']);
        $this->assertSame('Clinically proven', $extracted[0]['anchor']);

        $page = $this->actingAs($owner)->get(route('documents.show', $doc))->assertOk();
        $page->assertSeeInOrder(['v' . $v2->version_no, 'Aditi (Legal)', 'Disclaimer wording is fine now.', 'v' . $v1->version_no, 'Comment on v1', 'Smriti (Regulatory)', 'Add the reference for this claim.']);
        $page->assertSee('Download with comments')->assertSee('Full screen');
    }

    /** A minimal .docx with Word comments anchored to some text. */
    protected function docxWithComments(array $comments): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

        $body = '';
        $xml = '';
        foreach ($comments as $c) {
            $body .= "<w:p><w:commentRangeStart w:id=\"{$c['id']}\"/><w:r><w:t>{$c['anchor']}</w:t></w:r><w:commentRangeEnd w:id=\"{$c['id']}\"/><w:r><w:commentReference w:id=\"{$c['id']}\"/></w:r></w:p>";
            $xml .= "<w:comment w:id=\"{$c['id']}\" w:author=\"{$c['author']}\" w:date=\"2026-11-24T10:00:00Z\"><w:p><w:r><w:t>{$c['text']}</w:t></w:r></w:p></w:comment>";
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', "<?xml version=\"1.0\"?><w:document {$w}><w:body>{$body}</w:body></w:document>");
        $zip->addFromString('word/comments.xml', "<?xml version=\"1.0\"?><w:comments {$w}>{$xml}</w:comments>");
        $zip->close();

        return new UploadedFile($path, 'review.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    // ---- Follow-up round: required stakeholders, 48h, TM re-review, divisions, labels ----

    public function test_pharma_jobs_need_an_rnd_a_legal_and_a_regulatory_affairs_stakeholder(): void
    {
        config(['promomats.workflow.required_stakeholders' => [
            'pharma' => ['R&D' => ['rd', 'medical'], 'Legal' => ['legal'], 'Regulatory Affairs' => ['regulatory']],
        ]]);
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $rnd = $this->userWithRole('medical');
        $reg = $this->userWithRole('regulatory');
        $legal = $this->userWithRole('legal');
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null], ['RND', 'medical', 'review', 'MLR'], ['REG', 'regulatory', 'review', 'MLR'], ['LEGAL', 'legal', 'review', 'MLR']]);
        $base = ['title' => 'LBL', 'team' => 'pharma', 'workflow_template_id' => $s['TM_AGM']->workflow_template_id, 'file' => UploadedFile::fake()->create('a.pdf', 5)];

        $this->actingAs($owner)->get(route('documents.create'))->assertOk()->assertSee('Required stakeholders selected');

        $this->actingAs($owner)->post(route('documents.store'), $base + ['stage_approvers' => [$s['TM_AGM']->id => [$tm->id], $s['RND']->id => [$rnd->id]]])
            ->assertSessionHasErrors(['stage_approvers' => 'Pharma jobs need at least one stakeholder from R&D, Legal and Regulatory Affairs - please select someone for Legal and Regulatory Affairs.']);
        $this->assertSame(0, Document::count());

        $this->flushSession();
        $this->actingAs($owner)->post(route('documents.store'), $base + ['stage_approvers' => [
            $s['TM_AGM']->id => [$tm->id], $s['RND']->id => [$rnd->id], $s['REG']->id => [$reg->id], $s['LEGAL']->id => [$legal->id],
        ]])->assertRedirect()->assertSessionHasNoErrors();

        // Nothing picked: every stage goes to its usual people, which covers all three.
        $this->actingAs($owner)->post(route('documents.store'), $base)->assertSessionHasNoErrors();

        // An edited flow that drops Legal is refused too.
        $edit = json_encode(['stages' => [
            ['code' => 'TM_AGM', 'name' => 'TM_AGM', 'approversDirty' => false],
            ['code' => 'RND', 'name' => 'RND', 'approversDirty' => false],
            ['code' => 'REG', 'name' => 'REG', 'approversDirty' => false],
            ['code' => 'LEGAL', 'name' => 'LEGAL', 'removed' => true],
        ]]);
        $this->actingAs($owner)->post(route('documents.store'), $base + ['workflow_edit' => $edit])->assertSessionHasErrors('workflow_edit');

        // Scientific Publications has no such rule.
        $lead = $this->userWithRole('project-lead');
        $pub = $this->flow([['SP_REVIEW', 'project-lead', 'review', null]], ['team' => 'publication']);
        $this->flushSession();
        $this->actingAs($owner)->post(route('documents.store'), ['title' => 'Mono', 'team' => 'publication', 'workflow_template_id' => $pub['SP_REVIEW']->workflow_template_id,
            'stage_approvers' => [$pub['SP_REVIEW']->id => [$lead->id]], 'file' => UploadedFile::fake()->create('m.pdf', 5)])->assertSessionHasNoErrors();
    }

    public function test_every_stakeholder_gets_48_hours_from_when_the_task_is_assigned_to_them(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $tm2 = $this->userWithRole('tmagm');
        $legal = $this->userWithRole('legal');
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null], ['LEGAL', 'legal', 'review', null]]);
        $doc = $this->document($s['TM_AGM'], $owner);
        $this->pick($doc, $s['TM_AGM'], $tm);
        $this->pick($doc, $s['LEGAL'], $legal);
        $instance = $this->engine->start($doc, $doc->currentVersion, $owner);

        $task = $instance->pendingAssignees()->first();
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $task->due_at->timestamp, 5);

        // Reassigned 10 hours later: the new person gets their own 48 hours.
        $this->travel(10)->hours();
        $new = $this->engine->reassignTask($task, $tm2, $owner, 'On leave');
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $new->due_at->timestamp, 5);

        // The next stage's 48 hours start when it is reached.
        $this->travel(20)->hours();
        $this->engine->recordDecision($instance->fresh(), $tm2, 'approved');
        $legalTask = $instance->fresh()->pendingAssignees()->first();
        $this->assertSame($legal->id, $legalTask->user_id);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $legalTask->due_at->timestamp, 5);
    }

    public function test_a_rejection_after_tm_goes_back_to_tm_even_if_tm_approved_with_changes_earlier(): void
    {
        $owner = $this->userWithRole('brand-manager');
        $tm = $this->userWithRole('tmagm');
        $legal = $this->userWithRole('legal');
        $s = $this->flow([['TM_AGM', 'tmagm', 'review', null], ['LEGAL', 'legal', 'review', null], ['FINAL', 'regulatory', 'review', null]]);
        $this->userWithRole('regulatory');
        $doc = $this->document($s['TM_AGM'], $owner);
        $instance = $this->engine->start($doc, $doc->currentVersion, $owner);

        // TM approves with changes: it moves on to Legal.
        $this->engine->recordDecision($instance, $tm, 'approved_with_changes', 'Tweak the headline.');
        $this->assertSame([$legal->id], $instance->fresh()->pendingAssignees()->pluck('user_id')->all());

        // Legal rejects: the new version goes back to TM/AGM first.
        $this->engine->recordDecision($instance->fresh(), $legal, 'not_approved', 'Claim not substantiated.');
        $this->actingAs($owner)->post(route('documents.submit', $doc), ['file' => UploadedFile::fake()->create('v3.pdf', 5)])->assertRedirect();
        $this->assertSame([$tm->id], $instance->fresh()->pendingAssignees()->pluck('user_id')->all());

        $this->engine->recordDecision($instance->fresh(), $tm, 'approved');
        $this->assertSame([$legal->id], $instance->fresh()->pendingAssignees()->pluck('user_id')->all());
    }

    public function test_the_chairperson_is_chosen_by_the_brands_division(): void
    {
        $rathna = User::factory()->create(['email' => 'rathna.b@himalayawellness.com', 'name' => 'Rathna']);
        $jalba = User::factory()->create(['email' => 'jalba.rc@himalayawellness.com', 'name' => 'Jalba']);
        $admin = $this->admin();
        $zeal = Brand::create(['name' => 'Zeal Brand', 'code' => 'ZB', 'status' => 'active']);
        $zandra = Brand::create(['name' => 'Zandra Brand', 'code' => 'ZD', 'division' => 'zandra', 'status' => 'active']);
        $none = Brand::create(['name' => 'Other', 'code' => 'OT', 'status' => 'active']);

        $this->actingAs($admin)->post(route('admin.brands.division', $zeal), ['division' => 'zeal'])->assertRedirect();
        $this->assertSame('zeal', $zeal->fresh()->division);
        $this->actingAs($admin)->get(route('admin.brands.index'))->assertOk()->assertSee('Zandra');

        $this->assertSame($rathna->id, User::defaultChairpersonApprover($zeal->fresh())->id);
        $this->assertSame($jalba->id, User::defaultChairpersonApprover($zandra)->id);
        $this->assertSame($jalba->id, User::defaultChairpersonApprover($none)->id, 'No division: the configured default.');

        // On a job with nobody picked for the Chairperson stage, the brand's division decides.
        $owner = $this->userWithRole('brand-manager');
        $s = $this->flow([['CHAIRPERSON', 'chairperson', 'review', null]]);
        $doc = $this->document($s['CHAIRPERSON'], $owner);
        $doc->update(['brand_id' => $zeal->id]);
        $this->assertSame([$rathna->id], $doc->fresh()->effectivePicksForStage($s['CHAIRPERSON'])->all());

        // The upload form offers both and pre-ticks the brand's one.
        $page = $this->actingAs($owner)->get(route('documents.create'))->assertOk()->assertSee('chairpersonByBrand', false);
        $map = $page->viewData('chairpersonByBrand');
        $this->assertSame([$rathna->id, $jalba->id, $jalba->id], [$map[$zeal->id], $map[$zandra->id], $map[$none->id]]);
    }

    public function test_medical_and_regulatory_are_renamed_r_and_d_and_regulatory_affairs(): void
    {
        $medical = Role::firstOrCreate(['slug' => 'medical'], ['name' => 'Medical']);
        $reg = Role::firstOrCreate(['slug' => 'regulatory-level-1'], ['name' => 'Regulatory Level 1']);
        $legal = Role::firstOrCreate(['slug' => 'legal'], ['name' => 'Legal']);
        $s = $this->flow([['MLR_MEDICAL', 'medical', 'review', 'MLR'], ['MLR_REGULATORY', 'regulatory', 'review', 'MLR']]);
        $s['MLR_MEDICAL']->update(['name' => 'MLR Review - Medical']);
        $s['MLR_REGULATORY']->update(['name' => 'MLR Review - Regulatory']);
        $user = User::factory()->create(['department' => 'Regulatory']);

        \App\Support\StakeholderLabels::apply();
        \App\Support\StakeholderLabels::apply(); // idempotent

        $this->assertSame('R&D', $medical->fresh()->name);
        $this->assertSame('Regulatory Affairs Level 1', $reg->fresh()->name);
        $this->assertSame('Legal', $legal->fresh()->name);
        $this->assertSame('medical', $medical->fresh()->slug, 'Slugs are untouched.');
        $this->assertSame('MLR Review - R&D', $s['MLR_MEDICAL']->fresh()->name);
        $this->assertSame('MLR Review - Regulatory Affairs', $s['MLR_REGULATORY']->fresh()->name);
        $this->assertSame('Regulatory Affairs', $user->fresh()->department);
    }

    public function test_medical_is_merged_into_an_existing_r_and_d_role(): void
    {
        $rnd = Role::firstOrCreate(['slug' => 'rd'], ['name' => 'R&D']);
        $ann = $this->userWithRole('medical');
        $s = $this->flow([['MLR_MEDICAL', 'medical', 'review', 'MLR'], ['MLR_RND', 'rd', 'review', 'MLR']]);
        WorkflowStageApprover::create(['workflow_stage_id' => $s['MLR_RND']->id, 'role_id' => Role::where('slug', 'medical')->value('id')]);

        \App\Support\StakeholderLabels::apply();

        $this->assertFalse(Role::where('slug', 'medical')->exists());
        $this->assertSame(['rd'], $ann->fresh('roles')->roles->pluck('slug')->all());
        $this->assertSame([$rnd->id], $s['MLR_MEDICAL']->approvers()->pluck('role_id')->all());
        $this->assertSame([$rnd->id], $s['MLR_RND']->approvers()->pluck('role_id')->all(), 'No duplicate approver rows.');
        $this->assertSame('R&D', $rnd->fresh()->name);
    }
}
