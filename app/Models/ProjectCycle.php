<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectCycle extends Model
{
    protected $fillable = ['project_id', 'name', 'description', 'status', 'start_date', 'end_date', 'created_by'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'cycle_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Same bucket scheme as Project::statusBreakdown() (see Project::STATUS_BUCKETS)
     * so a cycle's breakdown reads identically to its parent project's.
     */
    public function statusBreakdown(): array
    {
        $counts = $this->documents()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $breakdown = array_fill_keys(array_keys(Project::STATUS_BUCKETS), 0);
        foreach (Project::STATUS_BUCKETS as $bucket => $statuses) {
            foreach ($statuses as $status) {
                $breakdown[$bucket] += (int) ($counts[$status] ?? 0);
            }
        }

        return $breakdown;
    }

    public function isEmpty(): bool
    {
        return $this->documents()->doesntExist();
    }
}
