<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\DocumentType;
use App\Models\WorkflowRule;
use App\Models\WorkflowTemplate;
use Illuminate\Http\Request;

class WorkflowRuleController extends Controller
{
    /**
     * The admin-facing side of App\Services\Workflow\WorkflowResolver: which
     * workflow a document gets, expressed entirely as rows here rather than as
     * PHP conditionals.
     */
    public function index(Request $request)
    {
        $scope = $request->user()->adminDepartmentScope();

        // A department admin only sees / manages rules that point at one of their
        // own department's workflows.
        $ownTemplateIds = $scope
            ? WorkflowTemplate::where('department', $scope)->pluck('id')
            : null;

        $rules = WorkflowRule::with(['template', 'brand', 'documentType'])
            ->when($ownTemplateIds, fn ($q) => $q->whereIn('workflow_template_id', $ownTemplateIds))
            ->orderBy('priority')
            ->get();

        $brands = Brand::active()->orderBy('name')->get();
        $documentTypes = DocumentType::active()->orderBy('name')->get();
        $templates = WorkflowTemplate::shared()->where('is_active', true)
            ->when($scope, fn ($q) => $q->where('department', $scope))
            ->orderBy('name')->get();

        return view('admin.workflow-rules.index', compact('rules', 'brands', 'documentTypes', 'templates'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'workflow_template_id' => ['required', 'exists:workflow_templates,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'document_type_id' => ['nullable', 'exists:document_types,id'],
            'department' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'min:0'],
        ]);

        $this->assertReachTemplate($request, (int) $validated['workflow_template_id']);

        WorkflowRule::create($validated + [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Workflow rule added.');
    }

    public function destroy(Request $request, WorkflowRule $workflowRule)
    {
        $this->assertReachTemplate($request, $workflowRule->workflow_template_id);

        $workflowRule->delete();

        return back()->with('status', 'Workflow rule removed.');
    }

    /** A department admin can only wire rules to their own department's workflows. */
    protected function assertReachTemplate(Request $request, int $templateId): void
    {
        $department = WorkflowTemplate::whereKey($templateId)->value('department');

        abort_unless($request->user()->adminCanReachDepartment($department), 403, 'That workflow is outside your department.');
    }
}
