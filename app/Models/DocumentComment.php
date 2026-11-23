<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * A review comment. A reviewer's comments start as a private *draft*
 * (published_at = null) that only they can see, and are published to everyone
 * when they submit their review or the stage moves on (UAT feedback: "until the
 * workflow is submitted to next level, draft comments should not be visible").
 * The 'published' global scope hides other people's drafts everywhere - the
 * document page, the mobile API, the history report and the "with comments" PDF.
 */
class DocumentComment extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('published', function (Builder $query) {
            $query->where(function ($q) {
                $q->whereNotNull('document_comments.published_at');
                if ($uid = Auth::id()) {
                    $q->orWhere('document_comments.user_id', $uid);
                }
            });
        });

        // Published unless the caller explicitly creates a draft (published_at => null).
        static::creating(function (DocumentComment $comment) {
            if (! array_key_exists('published_at', $comment->getAttributes())) {
                $comment->published_at = now();
            }
        });
    }

    /**
     * Publish every draft comment $userIds left on $documentId.
     *
     * @param  iterable<int>  $userIds
     */
    public static function publishDraftsBy(int $documentId, iterable $userIds): int
    {
        $ids = collect($userIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return static::withoutGlobalScope('published')
            ->where('document_id', $documentId)
            ->whereIn('user_id', $ids)
            ->whereNull('published_at')
            ->update(['published_at' => now()]);
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    protected $fillable = [
        'document_id', 'document_version_id', 'parent_id', 'user_id', 'body',
        'page_number', 'x_position', 'y_position', 'selected_text', 'replacement_text', 'highlight_rects',
        'timestamp_seconds', 'resolved_at', 'resolved_by', 'edited_at', 'published_at',
    ];

    protected $casts = [
        'x_position' => 'float',
        'y_position' => 'float',
        'timestamp_seconds' => 'float',
        'highlight_rects' => 'array',
        'resolved_at' => 'datetime',
        'edited_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function parent()
    {
        return $this->belongsTo(DocumentComment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(DocumentComment::class, 'parent_id')->with('author')->oldest();
    }
}
