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
    public function index()
    {
        $rules = WorkflowRule::with(['template', 'brand', 'documentType'])
            ->orderBy('priority')
            ->get();

        $brands = Brand::active()->orderBy('name')->get();
        $documentTypes = DocumentType::active()->orderBy('name')->get();
        $templates = WorkflowTemplate::where('is_active', true)->orderBy('name')->get();

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

        WorkflowRule::create($validated + [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Workflow rule added.');
    }

    public function destroy(WorkflowRule $workflowRule)
    {
        $workflowRule->delete();

        return back()->with('status', 'Workflow rule removed.');
    }
}
