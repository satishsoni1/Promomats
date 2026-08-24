<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Document History Report — {{ $document->reference_no }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #1f2937;
            font-size: 13px;
            line-height: 1.5;
            max-width: 900px;
            margin: 0 auto;
            padding: 32px 40px 64px;
            background: #fff;
        }
        h1 { font-size: 20px; margin: 0 0 2px; }
        h2 { font-size: 14px; margin: 28px 0 10px; padding-bottom: 6px; border-bottom: 2px solid #127277; color: #0d5559; }
        .subtitle { color: #6b7280; font-size: 12px; margin-bottom: 20px; }
        .toolbar { margin-bottom: 24px; }
        .toolbar button {
            background: #127277; color: #fff; border: none; padding: 8px 16px;
            border-radius: 6px; font-size: 13px; cursor: pointer;
        }
        .toolbar a { margin-left: 12px; font-size: 13px; color: #127277; text-decoration: none; }
        .meta-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 24px;
            border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 18px; background: #fafaf8;
        }
        .meta-grid div { padding: 3px 0; }
        .meta-grid dt { display: inline-block; width: 130px; color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
        .meta-grid dd { display: inline; margin: 0; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { text-align: left; color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; padding: 6px 8px; border-bottom: 1px solid #d1d5db; }
        td { padding: 7px 8px; border-bottom: 1px solid #f0f0ee; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        .badge {
            display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 8px;
            border-radius: 999px; text-transform: uppercase; letter-spacing: .02em; white-space: nowrap;
        }
        .badge-version { background: #e0f2fe; color: #075985; }
        .badge-signature { background: #d1fae5; color: #065f46; }
        .badge-edit { background: #fef3c7; color: #92400e; }
        .badge-comment { background: #f3f4f6; color: #374151; }
        .badge-legal_hold { background: #fdf1f1; color: #8f2323; }
        .signature-note {
            font-size: 11px; color: #4b5563; background: #f0faf9; border: 1px solid #cbe8e6;
            border-radius: 6px; padding: 10px 14px; margin-top: 6px;
        }
        .empty { color: #9ca3af; font-style: italic; padding: 10px 0; }
        .footer { margin-top: 48px; padding-top: 14px; border-top: 1px solid #e5e7eb; font-size: 10.5px; color: #9ca3af; }
        .checksum { font-family: 'Courier New', monospace; font-size: 10.5px; word-break: break-all; color: #6b7280; }

        @media print {
            .toolbar { display: none; }
            body { padding: 0 8mm; max-width: none; }
            h2 { break-after: avoid; }
            tr { break-inside: avoid; }
            @page { margin: 16mm 12mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">🖨️ Print / Save as PDF</button>
        <a href="{{ route('documents.show', $document) }}">&larr; Back to document</a>
    </div>

    <h1>Document History Report</h1>
    <p class="subtitle">
        A complete, chronological record of this document's versions, approval decisions
        (with electronic signatures per 21&nbsp;CFR&nbsp;Part&nbsp;11), content edits, and comments —
        generated {{ now()->format('M j, Y g:i A') }} by {{ auth()->user()->name }}.
    </p>

    <h2>Document Details</h2>
    <div class="meta-grid">
        <div><dt>Title</dt> <dd>{{ $document->title }}</dd></div>
        <div><dt>Reference No.</dt> <dd>{{ $document->reference_no }}</dd></div>
        <div><dt>Category</dt> <dd>{{ $document->category }}</dd></div>
        <div><dt>Current Status</dt> <dd>{{ $document->statusLabel() }}</dd></div>
        <div><dt>Owner</dt> <dd>{{ $document->owner?->name ?? '—' }}</dd></div>
        <div><dt>Workflow</dt> <dd>{{ $document->workflowTemplate?->name ?? '—' }}</dd></div>
        <div><dt>Project</dt> <dd>{{ $document->project?->name ?? 'Unassigned' }}</dd></div>
        <div><dt>Cycle</dt> <dd>{{ $document->cycle?->name ?? '—' }}</dd></div>
        <div><dt>Start / Expiry</dt> <dd>{{ $document->start_date?->format('M j, Y') ?? '—' }} &rarr; {{ $document->expiry_date?->format('M j, Y') ?? '—' }}</dd></div>
        <div><dt>Created</dt> <dd>{{ $document->created_at->format('M j, Y g:i A') }}</dd></div>
        @if ($document->legal_hold)
            <div style="grid-column: 1 / -1;"><dt>Legal Hold</dt> <dd style="color: #8f2323;">⚖️ ACTIVE — {{ $document->legal_hold_reason }} (set by {{ $document->legalHoldSetBy?->name ?? '—' }}, {{ $document->legal_hold_set_at?->format('M j, Y') }})</dd></div>
        @endif
    </div>

    <h2>Version History ({{ $document->versions->count() }})</h2>
    @if ($document->versions->isEmpty())
        <p class="empty">No versions recorded.</p>
    @else
        <table>
            <thead><tr><th>Ver.</th><th>File</th><th>Uploaded By</th><th>Uploaded At</th><th>Notes</th><th>SHA-256 Checksum</th></tr></thead>
            <tbody>
                @foreach ($document->versions->sortBy('version_no') as $v)
                    <tr>
                        <td>v{{ $v->version_no }}</td>
                        <td>{{ $v->original_filename }}</td>
                        <td>{{ $v->uploader?->name ?? '—' }}</td>
                        <td>{{ $v->created_at->format('M j, Y g:i A') }}</td>
                        <td>{{ $v->change_notes ?: '—' }}</td>
                        <td class="checksum">{{ $v->checksum_sha256 ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Approval &amp; Signature Audit Trail ({{ $document->approvalActions->count() }})</h2>
    @if ($document->approvalActions->isEmpty())
        <p class="empty">No approval decisions recorded yet.</p>
    @else
        <table>
            <thead><tr><th>Date/Time</th><th>Stage</th><th>Decision</th><th>Signed By</th><th>IP Address</th><th>Comments</th></tr></thead>
            <tbody>
                @foreach ($document->approvalActions->sortBy('acted_at') as $a)
                    <tr>
                        <td>{{ $a->acted_at?->format('M j, Y g:i A') }}</td>
                        <td>{{ $a->stage?->name ?? '—' }}</td>
                        <td>{{ $a->decisionLabel() }}</td>
                        <td>{{ $a->signed_name ?? $a->actor?->name ?? '—' }}{{ $a->signed_name ? ' ✓' : '' }}</td>
                        <td class="checksum">{{ $a->ip_address ?: '—' }}</td>
                        <td>{{ $a->comments ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="signature-note">
            ✓ indicates the decision was applied with a re-authenticated electronic signature
            (password confirmation at the moment of signing) under 21&nbsp;CFR&nbsp;Part&nbsp;11.
            The "Signed By" name is a point-in-time record captured at signing and does not
            change even if the signer's account is later renamed.
        </p>
    @endif

    <h2>Content Edits ({{ $document->versions->flatMap->pdfEdits->count() }})</h2>
    @php $allEdits = $document->versions->flatMap->pdfEdits->sortBy('created_at'); @endphp
    @if ($allEdits->isEmpty())
        <p class="empty">No in-place content edits recorded.</p>
    @else
        <table>
            <thead><tr><th>Date/Time</th><th>Version</th><th>Type</th><th>Page</th><th>By</th><th>Content</th></tr></thead>
            <tbody>
                @foreach ($allEdits as $e)
                    <tr>
                        <td>{{ $e->created_at->format('M j, Y g:i A') }}</td>
                        <td>v{{ $e->documentVersion?->version_no }}</td>
                        <td>{{ $e->typeLabel() }}</td>
                        <td>{{ $e->page_number }}</td>
                        <td>{{ $e->actor?->name ?? '—' }}</td>
                        <td>{{ $e->content ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Comments ({{ $document->comments->count() }})</h2>
    @if ($document->comments->isEmpty())
        <p class="empty">No comments recorded.</p>
    @else
        <table>
            <thead><tr><th>Date/Time</th><th>Author</th><th>Comment</th></tr></thead>
            <tbody>
                @foreach ($document->comments->sortBy('created_at') as $c)
                    <tr>
                        <td>{{ $c->created_at->format('M j, Y g:i A') }}</td>
                        <td>{{ $c->author?->name ?? '—' }}</td>
                        <td>{{ $c->body }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($document->claims->isNotEmpty())
        <h2>Claims Used ({{ $document->claims->count() }})</h2>
        <table>
            <thead><tr><th>Claim</th><th>Reference</th></tr></thead>
            <tbody>
                @foreach ($document->claims as $claim)
                    <tr>
                        <td>{{ $claim->match_text }}</td>
                        <td>{{ $claim->references->pluck('citation')->filter()->implode('; ') ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Complete Timeline ({{ $timeline->count() }} events)</h2>
    @if ($timeline->isEmpty())
        <p class="empty">No activity recorded.</p>
    @else
        <table>
            <thead><tr><th>Date/Time</th><th>Type</th><th>Actor</th><th>Event</th><th>Detail</th></tr></thead>
            <tbody>
                @foreach ($timeline as $row)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($row['at'])->format('M j, Y g:i A') }}</td>
                        <td><span class="badge badge-{{ $row['type'] }}">{{ $row['type'] }}</span></td>
                        <td>{{ $row['actor'] ?? '—' }}</td>
                        <td>{{ $row['summary'] }}</td>
                        <td>{{ $row['detail'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        This is a system-generated record from VODO reflecting the state of document
        {{ $document->reference_no }} at the time of generation ({{ now()->format('M j, Y g:i:s A') }}).
        Generated by {{ auth()->user()->name }} ({{ auth()->user()->email }}).
    </div>
</body>
</html>
