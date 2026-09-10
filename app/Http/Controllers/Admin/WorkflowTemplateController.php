<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowTemplateController extends Controller
{
    public function index(Request $request)
    {
        $scope = $request->user()->adminDepartmentScope();

        $templates = WorkflowTemplate::shared()
            ->when($scope, fn ($q) => $q->where('department', $scope))
            ->withCount('stages')
            ->latest()
            ->get();

        return view('admin.workflows.index', compact('templates'))
            ->with('departmentScope', $scope);
    }

    public function create(Request $request)
    {
        return view('admin.workflows.create')
            ->with('departmentScope', $request->user()->adminDepartmentScope());
    }

    public function store(Request $request)
    {
        $scope = $request->user()->adminDepartmentScope();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:workflow_templates,code'],
            'description' => ['nullable', 'string'],
            'applies_to_category' => ['nullable', 'string', 'max:120'],
            'department' => $scope
                ? ['required', 'string', \Illuminate\Validation\Rule::in([$scope])]
                : ['nullable', 'string', 'max:120'],
            'target_audiences' => ['nullable', 'array'],
            'target_audiences.*' => ['string', 'in:' . implode(',', array_keys(\App\Support\TargetAudience::LABELS))],
            'owner_can_customize_workflow' => ['sometimes', 'boolean'],
        ]);

        $template = WorkflowTemplate::create($validated + [
            'department' => $scope,
            'created_by' => $request->user()->id,
            'owner_can_customize_workflow' => $request->boolean('owner_can_customize_workflow'),
        ]);

        return redirect()->route('admin.workflows.edit', $template)->with('status', 'Workflow created. Now add stages.');
    }

    public function edit(Request $request, WorkflowTemplate $workflow)
    {
        $this->assertReach($request, $workflow);

        $workflow->load('stages.approvers.role', 'stages.transitions');
        $roles = Role::orderBy('name')->get();
        return view('admin.workflows.edit', compact('workflow', 'roles'));
    }

    /**
     * Template-level settings that are safe to change even on a locked template
     * (they don't rewrite any in-flight document's path) - currently just whether
     * a document owner may pick specific approvers per stage at upload time.
     */
    public function updateSettings(Request $request, WorkflowTemplate $workflow)
    {
        $this->assertReach($request, $workflow);

        $validated = $request->validate([
            'owner_can_customize_workflow' => ['sometimes', 'boolean'],
        ]);

        $workflow->update([
            'owner_can_customize_workflow' => (bool) ($validated['owner_can_customize_workflow'] ?? false),
        ]);

        return back()->with('status', 'Workflow settings saved.');
    }

    public function addStage(Request $request, WorkflowTemplate $workflow)
    {
        $this->assertReach($request, $workflow);
        $this->assertNotLocked($workflow);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:60'],
            'approval_mode' => ['required', 'in:any_one,all_required,majority'],
            'quorum_count' => ['nullable', 'integer', 'min:1'],
            'condition_field' => ['nullable', 'in:brand_id,document_type_id,category,target_audience,department'],
            'condition_operator' => ['nullable', 'in:equals,not_equals,in,not_in'],
            'condition_value' => ['nullable', 'string', 'max:500'],
            'is_revision_stage' => ['sometimes', 'boolean'],
            'is_final_distribution_stage' => ['sometimes', 'boolean'],
            'sla_hours' => ['nullable', 'integer', 'min:1'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['exists:roles,id'],
        ]);

        DB::transaction(function () use ($workflow, $validated) {
            $nextSeq = ($workflow->stages()->max('sequence_no') ?? 0) + 1;

            $conditionJson = null;
            if (! empty($validated['condition_field']) && ! empty($validated['condition_operator'])) {
                $rawValue = trim($validated['condition_value'] ?? '');
                $isList = in_array($validated['condition_operator'], ['in', 'not_in'], true);
                $conditionJson = [
                    'field' => $validated['condition_field'],
                    'operator' => $validated['condition_operator'],
                    'value' => $isList
                        ? array_values(array_filter(array_map('trim', explode(',', $rawValue))))
                        : $rawValue,
                ];
            }

            $stage = WorkflowStage::create([
                'workflow_template_id' => $workflow->id,
                'sequence_no' => $nextSeq,
                'name' => $validated['name'],
                'code' => $validated['code'],
                'approval_mode' => $validated['approval_mode'],
                'quorum_count' => $validated['approval_mode'] === 'majority' ? ($validated['quorum_count'] ?? null) : null,
                'condition_json' => $conditionJson,
                'is_revision_stage' => $validated['is_revision_stage'] ?? false,
                'is_final_distribution_stage' => $validated['is_final_distribution_stage'] ?? false,
                'sla_hours' => $validated['sla_hours'] ?? null,
            ]);

            foreach ($validated['role_ids'] as $roleId) {
                $stage->approvers()->create(['role_id' => $roleId]);
            }
        });

        return back()->with('status', 'Stage added.');
    }

    /**
     * Configure what happens after a decision at a given stage:
     * next_stage / return_to_stage (revision loop) / terminate_rejected / complete_approved.
     */
    public function setTransition(Request $request, WorkflowStage $stage)
    {
        $this->assertReach($request, $stage->template);
        $this->assertNotLocked($stage->template);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,approved_with_changes,not_approved'],
            'outcome_type' => ['required', 'in:next_stage,return_to_stage,terminate_rejected,complete_approved'],
            'target_stage_id' => ['nullable', 'exists:workflow_stages,id'],
            'resume_at_stage_id' => ['nullable', 'exists:workflow_stages,id'],
        ]);

        WorkflowTransition::updateOrCreate(
            ['workflow_stage_id' => $stage->id, 'decision' => $validated['decision']],
            [
                'outcome_type' => $validated['outcome_type'],
                'target_stage_id' => $validated['target_stage_id'] ?? null,
                'resume_at_stage_id' => $validated['resume_at_stage_id'] ?? null,
            ]
        );

        return back()->with('status', 'Transition rule saved.');
    }

    public function reorderStages(Request $request, WorkflowTemplate $workflow)
    {
        $this->assertReach($request, $workflow);
        $this->assertNotLocked($workflow);

        $validated = $request->validate([
            'stage_ids' => ['required', 'array'],
            'stage_ids.*' => ['exists:workflow_stages,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['stage_ids'] as $index => $stageId) {
                WorkflowStage::where('id', $stageId)->update(['sequence_no' => $index + 1]);
            }
        });

        return back()->with('status', 'Stage order updated.');
    }

    /**
     * Version-forward: clone this (locked) template's stages/approvers/
     * transitions into a new, freely-editable version in the same family.
     * Existing documents keep pointing at the old, untouched version.
     */
    public function newVersion(Request $request, WorkflowTemplate $workflow)
    {
        $this->assertReach($request, $workflow);

        $newTemplate = $workflow->createNewVersion($request->user());

        return redirect()->route('admin.workflows.edit', $newTemplate)
            ->with('status', "Created version {$newTemplate->version} - edit it freely, the previous version documents already used is untouched.");
    }

    /**
     * A published template that any document has actually started a workflow
     * instance against is frozen (spec REQ-18): its stages/transitions can only
     * be read from here on, never rewritten out from under an in-flight
     * document. createNewVersion() is the only way to make further changes.
     */
    protected function assertNotLocked(WorkflowTemplate $workflow): void
    {
        abort_if($workflow->isLocked(), 422, "\"{$workflow->name}\" (v{$workflow->version}) is locked - it's already in use by at least one document. Create a new version to make changes.");
    }

    /**
     * A department admin may only touch workflows tagged to their own department;
     * a global admin may touch any (including department-less shared templates).
     */
    protected function assertReach(Request $request, WorkflowTemplate $workflow): void
    {
        abort_unless($request->user()->adminCanReachDepartment($workflow->department), 403, 'This workflow is outside your department.');
    }
}
