<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfLink extends Model
{
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'document_version_id', 'page_number', 'uri', 'rect', 'matched_reference_attachment_id', 'status',
    ];

    public function documentVersion()
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function matchedReference()
    {
        return $this->belongsTo(ReferenceAttachment::class, 'matched_reference_attachment_id');
    }

    /**
     * Best-effort readable label for the link target - the last path segment of the
     * URI (e.g. "vx204-study.pdf" out of ".../files/vx204-study.pdf"), falling back
     * to the full URI when there's no clean segment to show.
     */
    public function targetLabel(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);
        $segment = $path ? basename($path) : null;

        return filled($segment) ? $segment : $this->uri;
    }
}
