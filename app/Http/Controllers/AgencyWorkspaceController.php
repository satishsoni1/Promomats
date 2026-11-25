<?php

namespace App\Http\Controllers;

use App\Models\DocumentWorkTask;
use Illuminate\Http\Request;

/**
 * A Content Agency login's home (MOM 24 Nov: "content agency gets its own login to
 * manage documents"): the jobs the Brand Managers have sent to their agency - mine,
 * waiting for someone to take, and with colleagues - and what was handed back.
 * Nothing about the approval workflow around those jobs is shown.
 */
class AgencyWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user()->loadMissing('roles', 'contentAgency');
        abort_unless($user->isAgency() || $user->canAdminister(), 403);

        $open = DocumentWorkTask::open()
            ->where('team', 'agency')
            ->when(! $user->canAdminister(), fn ($q) => $q->forAgencyOf($user))
            ->with(['document.owner', 'document.brand', 'document.sku', 'assignee', 'requester', 'contentAgency'])
            ->orderBy('due_at')
            ->get();

        $mine = $open->where('assigned_to', $user->id)->values();
        $unassigned = $open->whereNull('assigned_to')->values();
        $colleagues = $open->filter(fn ($t) => $t->assigned_to && $t->assigned_to !== $user->id)->values();

        $handedBack = DocumentWorkTask::where('team', 'agency')
            ->where('status', 'completed')
            ->when(! $user->canAdminister(), fn ($q) => $q->forAgencyOf($user))
            ->with(['document', 'completer', 'contentAgency'])
            ->latest('completed_at')
            ->limit(20)
            ->get();

        return view('agency.index', compact('user', 'mine', 'unassigned', 'colleagues', 'handedBack'));
    }
}
