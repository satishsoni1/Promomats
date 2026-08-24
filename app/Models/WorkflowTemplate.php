<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowTemplate extends Model
{
    protected $fillable = [
        'name', 'code', 'family_code', 'version', 'description', 'applies_to_category', 'target_audiences', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'target_audiences' => 'array',
    ];

    protected static function booted(): void
    {
        // A freshly-created template starts as version 1 of its own family unless
        // createNewVersion() (below) set family_code explicitly to an existing one.
        static::creating(function (WorkflowTemplate $template) {
            $template->family_code ??= $template->code;
        });
    }

    /**
     * Whether an admin has tagged this template as recommended for the given target
     * audience - drives the auto-suggested workflow on the document create form.
     */
    public function recommendedFor(?string $audience): bool
    {
        return $audience && in_array($audience, $this->target_audiences ?? [], true);
    }

    public function stages()
    {
        return $this->hasMany(WorkflowStage::class)->orderBy('sequence_no');
    }

    public function firstStage()
    {
        return $this->stages()->orderBy('sequence_no')->first();
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function workflowInstances()
    {
        return $this->hasMany(DocumentWorkflowInstance::class);
    }

    public function workflowRules()
    {
        return $this->hasMany(WorkflowRule::class);
    }

    /**
     * All versions in this template's family, oldest first.
     */
    public function familyVersions()
    {
        return static::where('family_code', $this->family_code)->orderBy('version');
    }

    /**
     * Once any document has actually started a workflow instance against this
     * exact template row, its stages/transitions become immutable - a document
     * mid-flight must never have its path rewritten out from under it. Editing
     * further requires createNewVersion() instead. Deliberately computed rather
     * than a stored flag: always accurate, no separate write path to keep in sync.
     */
    public function isLocked(): bool
    {
        return $this->workflowInstances()->exists();
    }

    /**
     * Version-forward: clone this template's stages, approvers, and transitions
     * into a brand new WorkflowTemplate row in the same family. The old version
     * (and every document already using it) is left completely untouched: this
     * is how a locked template gets "edited" per spec REQ-18.
     */
    public function createNewVersion(User $admin): self
    {
        return DB::transaction(function () use ($admin) {
            $nextVersion = (int) $this->familyVersions()->max('version') + 1;

            $newTemplate = static::create([
                'name' => $this->name,
                'code' => $this->family_code . '-v' . $nextVersion,
                'family_code' => $this->family_code,
                'version' => $nextVersion,
                'description' => $this->description,
                'applies_to_category' => $this->applies_to_category,
                'target_audiences' => $this->target_audiences,
                'is_active' => true,
                'created_by' => $admin->id,
            ]);

            // Superseded versions stay usable by whatever already points at them,
            // but drop out of "is_active" so they stop being offered for new work.
            static::where('family_code', $this->family_code)
                ->where('id', '!=', $newTemplate->id)
                ->update(['is_active' => false]);

            $stageIdMap = [];

            foreach ($this->stages()->with('approvers')->get() as $oldStage) {
                $newStage = $newTemplate->stages()->create([
                    'sequence_no' => $oldStage->sequence_no,
                    'name' => $oldStage->name,
                    'code' => $oldStage->code,
                    'parallel_group' => $oldStage->parallel_group,
                    'approval_mode' => $oldStage->approval_mode,
                    'quorum_count' => $oldStage->quorum_count,
                    'is_revision_stage' => $oldStage->is_revision_stage,
                    'is_final_distribution_stage' => $oldStage->is_final_distribution_stage,
                    'sla_hours' => $oldStage->sla_hours,
                ]);

                $stageIdMap[$oldStage->id] = $newStage->id;

                foreach ($oldStage->approvers as $approver) {
                    $newStage->approvers()->create([
                        'role_id' => $approver->role_id,
                        'user_id' => $approver->user_id,
                    ]);
                }
            }

            foreach ($this->stages()->with('transitions')->get() as $oldStage) {
                foreach ($oldStage->transitions as $transition) {
                    WorkflowTransition::create([
                        'workflow_stage_id' => $stageIdMap[$oldStage->id],
                        'decision' => $transition->decision,
                        'outcome_type' => $transition->outcome_type,
                        'target_stage_id' => $transition->target_stage_id ? ($stageIdMap[$transition->target_stage_id] ?? null) : null,
                        'resume_at_stage_id' => $transition->resume_at_stage_id ? ($stageIdMap[$transition->resume_at_stage_id] ?? null) : null,
                    ]);
                }
            }

            return $newTemplate;
        });
    }
}
