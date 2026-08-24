<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserAccountNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    // Assumes an 'admin' middleware/policy gate is applied in routes/web.php

    public function index(Request $request)
    {
        $users = User::with('roles')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->paginate(25);

        $roles = Role::orderBy('name')->get();

        return view('admin.users.index', compact('users', 'roles'));
    }

    public function create()
    {
        $roles = Role::orderBy('name')->get();
        return view('admin.users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['nullable', 'string', 'max:50', 'unique:users,employee_code'],
            'email' => ['required', 'email', 'unique:users,email'],
            'department' => ['nullable', 'string', 'max:120'],
            'designation' => ['nullable', 'string', 'max:120'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['exists:roles,id'],
        ]);

        $tempPassword = Str::random(12);

        $user = User::create([
            'name' => $validated['name'],
            'employee_code' => $validated['employee_code'] ?? null,
            'email' => $validated['email'],
            'department' => $validated['department'] ?? null,
            'designation' => $validated['designation'] ?? null,
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
            'created_by' => $request->user()->id,
        ]);

        $user->roles()->sync($validated['roles']);

        // The temp password is shown to the admin here (not emailed - never mail a
        // plaintext password) so they can relay it securely; the new user separately
        // gets a "your account was created" notification once they can see it.
        $user->notify(new UserAccountNotification(event: 'account_created', actor: $request->user()));

        return redirect()->route('admin.users.index')
            ->with('status', "User created. Temporary password: {$tempPassword}");
    }

    public function updateRoles(Request $request, User $user)
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['exists:roles,id'],
        ]);

        $user->roles()->sync($validated['roles']);

        $roleNames = Role::whereIn('id', $validated['roles'])->pluck('name')->implode(', ');
        $user->notify(new UserAccountNotification(event: 'roles_updated', actor: $request->user(), rolesSummary: $roleNames));

        return back()->with('status', "Roles updated for {$user->name}.");
    }

    public function toggleActive(Request $request, User $user)
    {
        $user->update(['is_active' => ! $user->is_active]);
        return back()->with('status', $user->is_active ? "{$user->name} activated." : "{$user->name} deactivated.");
    }
}
