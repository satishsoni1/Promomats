<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    public const CATEGORIES = [
        'technical_issue' => 'Technical glitch / error',
        'modification' => 'Change / modification request',
        'access' => 'Access or login',
        'other' => 'Other',
    ];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    protected $fillable = [
        'reference', 'user_id', 'category', 'priority', 'subject', 'description', 'page_url',
        'document_id', 'status', 'first_response_due_at', 'resolved_at',
    ];

    protected $casts = [
        'first_response_due_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
