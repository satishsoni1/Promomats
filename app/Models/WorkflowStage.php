<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStage extends Model
{
    protected $fillable = [
        'workflow_template_id', 'sequence_no', 'name', 'code', 'parallel_group',
        'approval_mode', 'quorum_count', 'condition_json', 'is_revision_stage', 'is_final_distribution_stage', 'sla_hours',
    ];

    protected $casts = [
        'condition_json' => 'array',
        'is_revision_stage' => 'boolean',
        'is_final_distribution_stage' => 'boolean',
    ];

    public function template()
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function approvers()
    {
        return $this->hasMany(WorkflowStageApprover::class);
    }

    public function transitions()
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    public function transitionFor(string $decision): ?WorkflowTransition
    {
        return $this->transitions()->where('decision', $decision)->first();
    }

    /**
     * Every stage sharing this one's parallel_group (fan-out/fan-in siblings),
     * ordered by sequence_no - or just this stage on its own when it doesn't
     * belong to a group. Group siblings share the same DB rows returned here
     * regardless of which one you call it on.
     */
    public function groupSiblings()
    {
        if (! $this->parallel_group) {
            return collect([$this]);
        }

        return $this->template->stages()->where('parallel_group', $this->parallel_group)
            ->orderBy('sequence_no')->get();
    }

    /**
     * The first stage after this one's whole parallel_group (not just after
     * $this->sequence_no) - so a 3-way parallel group is skipped over as a unit
     * once every member resolves, rather than stepping to the next sibling
     * inside the same group.
     */
    public function nextStage()
    {
        $afterSeq = $this->parallel_group
            ? $this->template->stages()->where('parallel_group', $this->parallel_group)->max('sequence_no')
            : $this->sequence_no;

        return $this->template->stages()->where('sequence_no', '>', $afterSeq)
            ->orderBy('sequence_no')->first();
    }

    /**
     * How many "approved" decisions this stage needs before it can resolve as
     * approved. Defaults to a simple majority (more than half) of everyone
     * assigned; quorum_count overrides that with an exact number for a stage
     * that needs e.g. "3 of 5" rather than whatever the computed majority is.
     */
    public function requiredQuorum(int $totalApprovers): int
    {
        return $this->quorum_count ?? (intdiv($totalApprovers, 2) + 1);
    }

    /**
     * Whether this stage should actually run for the given document (spec
     * REQ-53). A stage with no condition_json always runs.
     */
    public function conditionMatches(Document $document): bool
    {
        return app(\App\Services\Workflow\ConditionEvaluator::class)->evaluate($this->condition_json, $document);
    }
}
