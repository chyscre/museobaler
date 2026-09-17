<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Staff;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index()
    {
        $staff = Staff::withCount('tours')->orderByDesc('created_at')->get();

        return view('staff.index', [
            'staff'           => $staff,
            'assignableRoles' => $this->assignableRoles(),
        ]);
    }

    /**
     * SECURITY: Tourism creates the account; it does not choose the password.
     *
     * There used to be a password box on this form. Whatever went into it was
     * a password a person could remember and hand over verbally, it was never
     * changed afterwards because no screen existed for changing it, and
     * Tourism therefore knew a working credential for every account in the
     * building - which meant no audit row could be pinned on one person.
     *
     * Now the password is generated here, shown to Tourism exactly once so it
     * can be handed over, and burned on the staff member's first sign-in by
     * RequirePasswordChange.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'  => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:staff,email',
            'role'  => ['required', Rule::in($this->assignableRoles())],
        ], [
            'role.in' => 'You do not have permission to assign that role.',
        ]);

        $temporary = PasswordPolicy::generateTemporary();

        $staff = Staff::create([
            'name'                 => $request->name,
            'email'                => $request->email,
            'password'             => $temporary, // SECURITY: Staff model has 'hashed' cast - auto-bcrypts on assignment
            'role'                 => $request->role,
            'status'               => true,
            'must_change_password' => true,
            'password_changed_at'  => null,
        ]);

        $this->log('Staff Added', "Added staff: {$staff->name} ({$staff->role})");

        // Flashed, never stored: this is the one and only time the plaintext
        // exists anywhere. If Tourism loses it before handing it over, the
        // answer is to issue another one, not to look the old one up.
        return redirect()->route('staff.index')
            ->with('success', 'Staff member added.')
            ->with('issued_credential', [
                'name'     => $staff->name,
                'email'    => $staff->email,
                'password' => $temporary,
            ]);
    }

    public function edit(Staff $staff)
    {
        if ($denied = $this->guardTarget($staff)) return $denied;

        return view('staff.edit', [
            'staff'           => $staff,
            'assignableRoles' => $this->assignableRoles(),
        ]);
    }

    public function modalEdit(Staff $staff)
    {
        if ($denied = $this->guardTarget($staff)) return $denied;

        return view('staff.partials.edit-form', [
            'staff'           => $staff,
            'assignableRoles' => $this->assignableRoles(),
        ]);
    }

    /**
     * SECURITY: no password field here, deliberately.
     *
     * Editing someone's details and setting someone's password are different
     * acts with different consequences, and they used to share a form: any
     * save of the edit modal could silently take over the account. Password
     * issuing now lives on its own route, so it shows up in the audit log as
     * its own entry and cannot happen as a side effect of fixing a typo in
     * somebody's surname.
     */
    public function update(Request $request, Staff $staff)
    {
        if ($denied = $this->guardTarget($staff)) return $denied;

        $request->validate([
            'name'  => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:staff,email,' . $staff->staff_id . ',staff_id',
            'role'  => ['required', Rule::in($this->assignableRoles())],
        ], [
            'role.in' => 'You do not have permission to assign that role.',
        ]);

        // The audit log is where the Tourism office looks first, so a role
        // change has to say what it changed from, not just that it happened.
        $before = $staff->role;

        $staff->update($request->only(['name', 'email', 'role']));

        $details = "Updated staff: {$staff->name}";
        if ($before !== $staff->role) {
            $details .= " - role {$before} to {$staff->role}";
        }

        $this->log('Staff Updated', $details);

        return redirect()->route('staff.index')->with('success', 'Staff updated.');
    }

    /**
     * Issues a fresh temporary password for someone who is locked out.
     *
     * The account is not left half-open afterwards: the new password only
     * gets them as far as the change-password screen, so a reset Tourism
     * performs cannot leave Tourism holding a usable credential.
     */
    public function resetPassword(Staff $staff)
    {
        if ($denied = $this->guardTarget($staff)) return $denied;

        $temporary = PasswordPolicy::generateTemporary();

        $staff->update([
            'password'             => $temporary,
            'must_change_password' => true,
            'password_changed_at'  => null,
        ]);

        $this->log('Staff Password Reset', "Issued a temporary password for {$staff->name}");

        return redirect()->route('staff.index')
            ->with('success', 'Temporary password issued.')
            ->with('issued_credential', [
                'name'     => $staff->name,
                'email'    => $staff->email,
                'password' => $temporary,
            ]);
    }

    public function toggle(Staff $staff)
    {
        if ($denied = $this->guardTarget($staff)) return $denied;

        if ($staff->staff_id === auth()->id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        // The Tourism office is the account of last resort. Deactivating the
        // final one would lock everybody out of staff management for good.
        if ($staff->isTourismHead() && $staff->status
            && Staff::where('role', Staff::ROLE_TOURISM)->where('status', true)->count() <= 1) {
            return back()->with('error', 'That is the only active Tourism Head account - create another before deactivating this one.');
        }

        $staff->update(['status' => !$staff->status]);
        $action = $staff->status ? 'activated' : 'deactivated';
        $this->log('Staff Updated', "Staff {$staff->name} {$action}");

        return back()->with("success", "Staff {$action}.");
    }

    /**
     * SECURITY: which roles the signed-in user may hand out.
     *
     * Only the Tourism office reaches this controller at all, and they own
     * the whole ladder. The method stays rather than being inlined so that
     * letting a narrower role manage accounts later is a change here instead
     * of a rewrite.
     */
    private function assignableRoles(): array
    {
        return auth()->user()->isTourismHead() ? array_keys(Staff::ROLES) : [];
    }

    /**
     * SECURITY: the last line of defence behind the route middleware. Museum
     * staff cannot touch a staff account at all - including renaming one, or
     * resetting its password to take it over.
     */
    private function guardTarget(Staff $staff)
    {
        if (auth()->user()->isTourismHead()) {
            return null;
        }

        return back()->with('error', 'Only the Tourism Head can manage staff accounts.');
    }

    private function log(string $action, string $details): void
    {
        Log::create([
            'user_id'    => auth()->id(),
            'user_name'  => auth()->user()->name,
            'role'       => auth()->user()->role ?? 'Administrator',
            'action'     => $action,
            'details'    => $details,
            'ip_address' => request()->ip(),
        ]);
    }
}
