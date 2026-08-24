<?php

namespace Tests\Feature\Workflow;

use App\Models\Brand;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\WorkflowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowResolverTest extends TestCase
{
    use RefreshDatabase;

    protected WorkflowResolver $resolver;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(WorkflowResolver::class);
        $this->admin = User::factory()->create();
    }

    protected function template(string $code): WorkflowTemplate
    {
        return WorkflowTemplate::create(['name' => $code, 'code' => $code, 'is_active' => true, 'created_by' => $this->admin->id]);
    }

    public function test_a_general_fallback_rule_resolves_when_nothing_more_specific_matches(): void
    {
        $pdfWorkflow = $this->template('PDF_WF');
        $pdfType = DocumentType::create(['name' => 'PDF', 'code' => 'PDF', 'status' => 'active']);

        WorkflowRule::create([
            'workflow_template_id' => $pdfWorkflow->id,
            'document_type_id' => $pdfType->id,
            'priority' => 100,
            'status' => 'active',
        ]);

        $resolved = $this->resolver->resolve(null, $pdfType, null);

        $this->assertSame($pdfWorkflow->id, $resolved?->id);
    }

    public function test_a_brand_specific_rule_wins_over_a_general_one_at_the_same_priority(): void
    {
        $generalWorkflow = $this->template('GENERAL_WF');
        $specialWorkflow = $this->template('LIV52_WF');
        $pdfType = DocumentType::create(['name' => 'PDF', 'code' => 'PDF2', 'status' => 'active']);
        $liv52 = Brand::create(['name' => 'Liv.52', 'code' => 'LIV52', 'status' => 'active']);
        $otherBrand = Brand::create(['name' => 'Pilex', 'code' => 'PILEX', 'status' => 'active']);

        WorkflowRule::create([
            'workflow_template_id' => $generalWorkflow->id,
            'document_type_id' => $pdfType->id,
            'priority' => 100,
            'status' => 'active',
        ]);
        WorkflowRule::create([
            'workflow_template_id' => $specialWorkflow->id,
            'brand_id' => $liv52->id,
            'document_type_id' => $pdfType->id,
            'priority' => 100, // same priority - specificity must break the tie
            'status' => 'active',
        ]);

        $this->assertSame($specialWorkflow->id, $this->resolver->resolve($liv52, $pdfType, null)?->id);
        $this->assertSame($generalWorkflow->id, $this->resolver->resolve($otherBrand, $pdfType, null)?->id);
    }

    public function test_a_lower_priority_number_wins_even_if_less_specific(): void
    {
        $urgentWorkflow = $this->template('URGENT_WF');
        $specificWorkflow = $this->template('SPECIFIC_WF');
        $pdfType = DocumentType::create(['name' => 'PDF', 'code' => 'PDF3', 'status' => 'active']);
        $brand = Brand::create(['name' => 'Vasaka', 'code' => 'VASAKA', 'status' => 'active']);

        WorkflowRule::create([
            'workflow_template_id' => $urgentWorkflow->id,
            'priority' => 1, // no brand/type pinned down, but highest priority (lowest number)
            'status' => 'active',
        ]);
        WorkflowRule::create([
            'workflow_template_id' => $specificWorkflow->id,
            'brand_id' => $brand->id,
            'document_type_id' => $pdfType->id,
            'priority' => 50,
            'status' => 'active',
        ]);

        $this->assertSame($urgentWorkflow->id, $this->resolver->resolve($brand, $pdfType, null)?->id);
    }

    public function test_an_inactive_rule_is_ignored(): void
    {
        $workflow = $this->template('INACTIVE_RULE_WF');
        $pdfType = DocumentType::create(['name' => 'PDF', 'code' => 'PDF4', 'status' => 'active']);

        WorkflowRule::create([
            'workflow_template_id' => $workflow->id,
            'document_type_id' => $pdfType->id,
            'priority' => 100,
            'status' => 'inactive',
        ]);

        $this->assertNull($this->resolver->resolve(null, $pdfType, null));
    }

    public function test_no_matching_rule_resolves_to_null(): void
    {
        $pdfType = DocumentType::create(['name' => 'PDF', 'code' => 'PDF5', 'status' => 'active']);

        $this->assertNull($this->resolver->resolve(null, $pdfType, null));
    }
}
