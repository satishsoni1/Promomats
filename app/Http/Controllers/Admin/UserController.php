<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserAccountNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    // Route group gate: 'access-admin-area' (global admin OR department admin).
    // Everything here is scoped to the acting admin's department when they are a
    // department admin - see actorScope() / assertReach() below.

    public function index(Request $request)
    {
        $scope = $request->user()->adminDepartmentScope();

        $users = User::with('roles')
            ->when($scope, fn ($q) => $q->where('department', $scope))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $roles = $this->assignableRoles($request->user());

        return view('admin.users.index', compact('users', 'roles'))
            ->with('departmentScope', $scope);
    }

    public function create(Request $request)
    {
        $roles = $this->assignableRoles($request->user());

        return view('admin.users.create', compact('roles'))
            ->with('departmentScope', $request->user()->adminDepartmentScope());
    }

    public function store(Request $request)
    {
        $scope = $request->user()->adminDepartmentScope();
        $allowedRoleIds = $this->assignableRoles($request->user())->pluck('id');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['nullable', 'string', 'max:50', 'unique:users,employee_code'],
            'email' => ['required', 'email', 'unique:users,email'],
            // A department admin can only create users in their own department.
            'department' => $scope
                ? ['required', 'string', Rule::in([$scope])]
                : ['nullable', 'string', 'max:120'],
            'designation' => ['nullable', 'string', 'max:120'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in($allowedRoleIds)],
        ]);

        $tempPassword = Str::random(12);

        $user = User::create([
            'name' => $validated['name'],
            'employee_code' => $validated['employee_code'] ?? null,
            'email' => $validated['email'],
            'department' => $validated['department'] ?? $scope,
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
        $this->assertReach($request->user(), $user);
        $allowedRoleIds = $this->assignableRoles($request->user())->pluck('id');

        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in($allowedRoleIds)],
        ]);

        $user->roles()->sync($validated['roles']);

        $roleNames = Role::whereIn('id', $validated['roles'])->pluck('name')->implode(', ');
        $user->notify(new UserAccountNotification(event: 'roles_updated', actor: $request->user(), rolesSummary: $roleNames));

        return back()->with('status', "Roles updated for {$user->name}.");
    }

    public function toggleActive(Request $request, User $user)
    {
        $this->assertReach($request->user(), $user);

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('status', $user->is_active ? "{$user->name} activated." : "{$user->name} deactivated.");
    }

    /**
     * Roles the acting admin may hand out. A global admin: all of them. A department
     * admin: everything except the two admin roles (they can't mint admins).
     */
    protected function assignableRoles(User $actor)
    {
        return Role::orderBy('name')
            ->when(! $actor->isGlobalAdmin(), fn ($q) => $q->whereNotIn('slug', ['admin', 'department-admin']))
            ->get();
    }

    /**
     * A department admin can only touch users inside their own department; a global
     * admin can touch anyone.
     */
    protected function assertReach(User $actor, User $target): void
    {
        abort_unless($actor->adminCanReachDepartment($target->department), 403, 'This user is outside your department.');
    }
}
