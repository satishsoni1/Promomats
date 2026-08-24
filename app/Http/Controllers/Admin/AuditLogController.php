<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * The org-wide audit trail: every document_id/action/actor combination,
     * newest first. document_approval_actions remains the system of record for
     * signed A/AwC/NA decisions specifically (see documents.history-report);
     * this view is the broader "who did what" trail spec REQ-34/35 asks for -
     * views, downloads, uploads, and lifecycle changes included.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', AuditLog::class);

        $logs = AuditLog::query()
            ->with(['user', 'document', 'stage'])
            ->when($request->filled('document_id'), fn ($q) => $q->where('document_id', $request->document_id))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        $actions = AuditLog::ACTION_LABELS;
        $documents = Document::orderBy('title')->get(['id', 'title', 'reference_no']);

        return view('admin.audit-logs.index', compact('logs', 'actions', 'documents'));
    }
}
