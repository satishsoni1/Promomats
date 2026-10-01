<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Notifications\SupportTicketNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * In-app helpdesk (UAT feedback: support for technical glitches and modification
 * requests, with an agreed turnaround). A ticket is logged, emailed to the support
 * mailbox and acknowledged to the requester with its first-response target.
 */
class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $tickets = SupportTicket::with('user', 'document')
            ->when(! $user->can('access-admin'), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->paginate(20);

        return view('helpdesk.index', [
            'tickets' => $tickets,
            'prefill' => [
                'document_id' => $request->integer('document') ?: null,
                'page_url' => $request->query('from', url()->previous()),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'in:' . implode(',', array_keys(SupportTicket::CATEGORIES))],
            'priority' => ['required', 'in:' . implode(',', array_keys(SupportTicket::PRIORITIES))],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'document_id' => ['nullable', 'integer', 'exists:documents,id'],
        ]);

        $hours = config('promomats.helpdesk.first_response_hours', ['urgent' => 2, 'high' => 4, 'normal' => 8, 'low' => 24])[$validated['priority']] ?? 8;

        $ticket = SupportTicket::create($validated + [
            'reference' => 'HD-' . now()->format('ymd') . '-' . strtoupper(Str::random(4)),
            'user_id' => $request->user()->id,
            'status' => 'open',
            'first_response_due_at' => now()->addHours($hours),
        ]);

        Notification::route('mail', config('promomats.helpdesk.email', 'support@globalspace.in'))->notify(new SupportTicketNotification($ticket));
        $request->user()->notify(new SupportTicketNotification($ticket, forRequester: true));

        return redirect()->route('helpdesk.index')
            ->with('status', "Ticket {$ticket->reference} raised - first response due by {$ticket->first_response_due_at->format('d M, H:i')}.");
    }

    /** Admin/support: move a ticket through open -> in progress -> resolved -> closed. */
    public function update(Request $request, SupportTicket $ticket)
    {
        abort_unless($request->user()->can('access-admin'), 403);

        $validated = $request->validate(['status' => ['required', 'in:open,in_progress,resolved,closed']]);
        $ticket->update($validated + ['resolved_at' => in_array($validated['status'], ['resolved', 'closed']) ? ($ticket->resolved_at ?? now()) : null]);

        return back()->with('status', "Ticket {$ticket->reference} updated.");
    }
}
