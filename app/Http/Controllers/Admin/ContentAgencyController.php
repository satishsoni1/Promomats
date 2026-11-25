<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentAgency;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserAccountNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Content Agency master (MOM 24 Nov): agencies, and the users created under each.
 * Agency users sign in with their own login, see only the jobs the Brand Manager
 * sends to their agency, and can hand work only to colleagues in the same agency.
 */
class ContentAgencyController extends Controller
{
    public function index()
    {
        $agencies = ContentAgency::withCount([
            'users',
            'workTasks as open_tasks_count' => fn ($q) => $q->where('status', 'open'),
        ])->orderBy('name')->get();

        $unassignedAgencyUsers = User::whereNull('content_agency_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', config('promomats.roles.agency', ['content-agency', 'agency'])))
            ->orderBy('name')->get(['id', 'name', 'email']);

        return view('admin.content-agencies.index', compact('agencies', 'unassignedAgencyUsers'));
    }

    public function show(ContentAgency $contentAgency)
    {
        $contentAgency->load(['users' => fn ($q) => $q->with('roles')->orderBy('name')]);
        $openTasks = $contentAgency->workTasks()->open()->with(['document', 'assignee'])->latest()->get();
        $attachable = User::whereNull('content_agency_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', config('promomats.roles.agency', ['content-agency', 'agency'])))
            ->orderBy('name')->get(['id', 'name', 'email']);

        return view('admin.content-agencies.show', [
            'agency' => $contentAgency,
            'openTasks' => $openTasks,
            'attachable' => $attachable,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:content_agencies,name'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $agency = ContentAgency::create($validated + ['status' => 'active', 'created_by' => $request->user()->id]);

        return redirect()->route('admin.content-agencies.show', $agency)->with('status', 'Content agency added - now add its users.');
    }

    public function update(Request $request, ContentAgency $contentAgency)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('content_agencies', 'name')->ignore($contentAgency->id)],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $contentAgency->update($validated);

        return back()->with('status', 'Content agency updated.');
    }

    public function toggleStatus(ContentAgency $contentAgency)
    {
        $contentAgency->update(['status' => $contentAgency->status === 'active' ? 'inactive' : 'active']);

        return back()->with('status', "\"{$contentAgency->name}\" is now {$contentAgency->status}.");
    }

    /** A new login for someone at this agency (Content Agency role, temporary password). */
    public function storeUser(Request $request, ContentAgency $contentAgency)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'designation' => ['nullable', 'string', 'max:120'],
        ]);

        $tempPassword = Str::random(12);
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'designation' => $validated['designation'] ?? null,
            'department' => $contentAgency->name,
            'content_agency_id' => $contentAgency->id,
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
            'created_by' => $request->user()->id,
        ]);

        $role = Role::firstOrCreate(['slug' => 'content-agency'], ['name' => 'Content Agency', 'is_system' => false]);
        $user->roles()->sync([$role->id]);
        $user->notify(new UserAccountNotification(event: 'account_created', actor: $request->user()));

        return back()->with('status', "{$user->name} added to {$contentAgency->name}. Temporary password: {$tempPassword}");
    }

    /** Put an existing Content Agency user under this agency. */
    public function attachUser(Request $request, ContentAgency $contentAgency)
    {
        $validated = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $user = User::with('roles')->findOrFail($validated['user_id']);
        abort_unless($user->isAgency(), 422, 'Only users with the Content Agency role can belong to an agency.');

        $user->update(['content_agency_id' => $contentAgency->id]);

        return back()->with('status', "{$user->name} now belongs to {$contentAgency->name}.");
    }

    public function detachUser(ContentAgency $contentAgency, User $user)
    {
        abort_unless($user->content_agency_id === $contentAgency->id, 404);
        $user->update(['content_agency_id' => null]);

        return back()->with('status', "{$user->name} removed from {$contentAgency->name}.");
    }
}
