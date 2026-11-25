<?php

namespace App\Services\Workflow;

use App\Models\Document;
use App\Models\WorkflowStage;
use App\Services\WorkflowEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The job's approval matrix with due dates (MOM 24 Nov):
 *  - stages nobody was selected for are hidden;
 *  - each stage in play has a due date/time - the live task's for an open stage,
 *    the Brand Manager's exact date/time if they set one, otherwise planned from
 *    when the stage before it is due plus the stage's due period;
 *  - the job's overall due date is the last of those, so it follows the stages
 *    actually selected and any date the Brand Manager moves.
 * Parallel tracks (MLR) run side by side: the next stage is planned from the
 * latest of them.
 */
class StageSchedule
{
    public function __construct(protected WorkflowEngine $engine) {}

    /**
     * @return Collection<int, array{stage: WorkflowStage, state: string, due_at: ?Carbon, overridden: bool, assignee_ids: Collection<int, int>}>
     *         state: done | open | upcoming | skipped
     */
    public function for(Document $document): Collection
    {
        $template = $document->workflowTemplate;
        if (! $template) {
            return collect();
        }

        $instance = $document->activeWorkflowInstance ?? $document->workflowInstances()->latest('id')->first();
        $finished = $instance && $instance->status !== 'running';
        $runs = $instance ? $instance->stageRuns()->get()->keyBy('workflow_stage_id') : collect();
        $assignees = $instance ? $instance->assignees()->get()->groupBy('workflow_stage_id') : collect();

        $rows = collect();
        $cursor = now();

        foreach ($template->stages()->orderBy('sequence_no')->get()->groupBy(fn ($s) => $s->parallel_group ?: 'solo-' . $s->id) as $column) {
            $columnEnd = null;

            foreach ($column as $stage) {
                $run = $runs->get($stage->id);
                $tasks = $assignees->get($stage->id, collect());
                $pending = $tasks->where('status', 'pending');
                $override = $document->dueOverrideForStage($stage);

                if ($run && $run->status === 'resolved' && $tasks->isEmpty()) {
                    $state = 'skipped';
                } elseif ($run && $run->status === 'open') {
                    $state = 'open';
                } elseif (($run && $run->status === 'resolved') || $finished) {
                    $state = 'done';
                } elseif ($this->engine->isStageEmpty($document, $stage) || ! $stage->conditionMatches($document)) {
                    $state = 'skipped';
                } else {
                    $state = 'upcoming';
                }

                $due = match ($state) {
                    'open' => $pending->map->dueAt()->filter()->max(),
                    'upcoming' => $document->dueAtForStage($stage, $cursor),
                    default => null,
                };

                if ($due && (! $columnEnd || $due->greaterThan($columnEnd))) {
                    $columnEnd = $due;
                }

                $rows->push([
                    'stage' => $stage,
                    'state' => $state,
                    'due_at' => $due,
                    'overridden' => (bool) $override,
                    'assignee_ids' => $state === 'open'
                        ? $pending->pluck('user_id')->values()
                        : ($document->effectivePicksForStage($stage) ?? collect()),
                ]);
            }

            if ($columnEnd) {
                $cursor = $columnEnd;
            }
        }

        return $rows;
    }

    /** Stage ids hidden from the approval matrix on this job. */
    public function hiddenStageIds(Document $document): Collection
    {
        return $this->for($document)->where('state', 'skipped')->map(fn ($r) => $r['stage']->id)->values();
    }

    /**
     * The overall due date: when the last stage still in play is due. Left alone
     * once the job has no stage ahead of it (finished, or nothing scheduled).
     */
    public function overallDueAt(Document $document): ?Carbon
    {
        return $this->for($document)->whereIn('state', ['open', 'upcoming'])->pluck('due_at')->filter()->max();
    }

    public function refreshDueDate(Document $document): void
    {
        $due = $this->overallDueAt($document);

        if ($due && $document->due_date?->toDateString() !== $due->toDateString()) {
            $document->update(['due_date' => $due->toDateString()]);
        }
    }
}
