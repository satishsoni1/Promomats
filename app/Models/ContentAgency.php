<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An external content agency. Its people are users with the Content Agency role and
 * this agency set on their account; they only ever see the jobs the Brand Manager
 * sends to their agency, and can hand work only to colleagues in the same agency.
 */
class ContentAgency extends Model
{
    protected $fillable = ['name', 'contact_email', 'contact_phone', 'status', 'created_by'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function workTasks()
    {
        return $this->hasMany(DocumentWorkTask::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Active agency users who can take work. */
    public function activeMembers()
    {
        return User::teamMembers('agency', $this->id);
    }
}
