<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\LibraryFolderController;
use App\Models\Document;
use App\Models\ReferenceAttachment;
use App\Models\SupportTicket;
use App\Support\TargetAudience;
use Illuminate\Http\Request;

/**
 * Read-only lists for the mobile app: what the "New job" form offers, the Library,
 * notifications and helpdesk tickets. Each comes from the same queries as the web
 * page it mirrors.
 */
class MobileCatalogController extends Controller
{
    /** Everything the web upload form offers, for the app's "New job" screen. */
    public function createOptions(Request $request)
    {
        $data = app(DocumentController::class)->create()->getData();

        return response()->json([
            'brands' => $data['brands']->map->only('id', 'name')->values(),
            'document_types' => $data['documentTypes']->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'channel' => $t->channel])->values(),
            'projects' => $data['projects']->map->only('id', 'name')->values(),
            'target_audiences' => collect(TargetAudience::LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'templates' => $data['templates']->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'customisable' => (bool) $t->owner_can_customize_workflow])->values(),
            'rules' => $data['workflowRules']->map->only('workflow_template_id', 'brand_id', 'document_type_id', 'priority')->values(),
            // Per template: the stages and the people the Brand Manager can pick (never themselves).
            'stages' => $data['templateApproverOptions'],
            'all_users' => $data['allUsers'],
            'default_due_hours' => $data['defaultDueHours'],
            'creative_teams' => [
                ['key' => 'later', 'label' => "Decide later - I'll upload the Word draft for Content Manager alignment first"],
                ['key' => 'agency', 'label' => 'Send the brief to the Content Agency now'],
                ['key' => 'design', 'label' => 'Send the brief to the Internal Design Team now'],
            ],
        ]);
    }

    /** Library -> Jobs: every job this person can see, with the same filters as the web. */
    public function library(Request $request)
    {
        $request->query->set('view', 'jobs');
        $data = app(LibraryFolderController::class)->index($request)->getData();
        $jobs = $data['jobs'];

        return response()->json([
            'stage' => $data['stage'],
            'counts' => $data['counts'],
            'jobs' => collect($jobs->items())->map(fn (Document $d) => MobileController::summarise($d) + [
                'brand' => $d->brand?->name,
                'updated_at' => $d->updated_at?->toIso8601String(),
            ]),
            'next_page' => $jobs->hasMorePages() ? $jobs->currentPage() + 1 : null,
        ]);
    }

    public function notifications(Request $request)
    {
        $page = $request->user()->notifications()->latest()->paginate(30);

        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
            'notifications' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                'message' => $n->data['message'] ?? 'Update',
                'document_id' => $n->data['document_id'] ?? null,
                'reference_no' => $n->data['reference_no'] ?? null,
                'comments' => $n->data['comments'] ?? null,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function helpdesk(Request $request)
    {
        $user = $request->user();
        $tickets = SupportTicket::query()
            ->when(! $user->can('access-admin'), fn ($q) => $q->where('user_id', $user->id))
            ->latest()->limit(50)->get();

        return response()->json([
            'categories' => collect(SupportTicket::CATEGORIES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'priorities' => collect(SupportTicket::PRIORITIES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'tickets' => $tickets->map(fn (SupportTicket $t) => [
                'reference' => $t->reference,
                'subject' => $t->subject,
                'category' => SupportTicket::CATEGORIES[$t->category] ?? $t->category,
                'priority' => SupportTicket::PRIORITIES[$t->priority] ?? $t->priority,
                'status' => str($t->status)->replace('_', ' ')->title()->toString(),
                'first_response_due_at' => $t->first_response_due_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /** Signed, short-lived link to a reference attachment (handed out by the job detail). */
    public function referenceFile(ReferenceAttachment $reference)
    {
        return $reference->download();
    }
}
