<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Log;
use App\Support\Device;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Where a staff member changes their own password.
 *
 * This screen is the whole point of the password lifecycle. Accounts are
 * assigned by the Tourism office - nobody self-registers, because the tier
 * being overseen must not mint its own overseers - but an assigned password
 * that can never be changed is a credential two people know forever, and a
 * credential two people know cannot be held against either of them in the
 * audit log.
 *
 * So: Tourism assigns the account, the staff member owns the password.
 */
class PasswordController extends Controller
{
    public function edit(Request $request)
    {
        $staff = $request->user();

        // Someone still on an issued password gets the bare card: nothing
        // else in the panel opens for them yet, so a sidebar full of links
        // that bounce straight back here would only confuse.
        if ($staff->mustChangePassword()) {
            return view('auth.password', ['forced' => true, 'hint' => PasswordPolicy::hint()]);
        }

        // Everyone else gets My Account inside the panel: who the account
        // is, and its password, on one page.
        return view('auth.account', [
            'staff'      => $staff,
            'hint'       => PasswordPolicy::hint(),
            // The sign-in before this one: the latest is the session reading
            // the page. One the person does not recognise is worth a password
            // change. ("POST login" is how the trail wrote it before.)
            'previousSignIn' => Log::where('user_id', $staff->staff_id)
                ->whereIn('action', ['Sign in', 'POST login'])
                ->latest('log_id')->skip(1)->first(['created_at', 'ip_address']),
            'layout'     => Device::isPhone($request->userAgent()) ? 'layouts.mobile' : 'layouts.admin',
        ]);
    }

    public function update(Request $request)
    {
        $staff = $request->user();

        $request->validate(
            [
                // SECURITY: proving the current password is what stops an
                // unattended signed-in browser from being turned into a
                // permanent takeover by whoever walks past the desk.
                'current_password' => ['required', 'string'],
                'password'         => PasswordPolicy::rules([$staff->name, $staff->email]),
            ],
            [
                'password.confirmed' => 'The two passwords do not match.',
            ]
        );

        if (!Hash::check($request->current_password, $staff->password)) {
            return back()->withErrors([
                'current_password' => 'That is not your current password.',
            ]);
        }

        // Reusing the password they were handed would leave the account in
        // exactly the state this screen exists to get it out of.
        if (Hash::check($request->password, $staff->password)) {
            return back()->withErrors([
                'password' => 'Your new password has to be different from your current one.',
            ]);
        }

        $wasForced = $staff->mustChangePassword();

        $staff->update([
            'password'             => $request->password, // 'hashed' cast bcrypts on assignment
            'must_change_password' => false,
            'password_changed_at'  => now(),
        ]);

        // SECURITY: any other session opened with the old password dies here.
        // AuthenticateSession pins each session to the password hash it was
        // opened with and re-stores the current one at the end of this
        // request, so this session survives and every other one - including
        // any the Tourism office opened with the password it had just issued
        // - is thrown out on its next request.
        //
        // Regenerating the id on top of that is the usual fixation defence:
        // the session that carried the old credential does not keep its id.
        $request->session()->regenerate();

        // The plaintext never goes near the log - only the fact, the person,
        // and whether it was the forced first change or a later voluntary one.
        Log::create([
            'user_id'    => $staff->staff_id,
            'user_name'  => $staff->name,
            'role'       => $staff->role,
            'action'     => 'Password Changed',
            'details'    => $wasForced
                ? "{$staff->name} set their own password, replacing the one issued to them"
                : "{$staff->name} changed their password",
            'ip_address' => $request->ip(),
        ]);

        // The first change opens the panel, so it goes to the home screen. A
        // later one was made from My Account, and comes back to it.
        return redirect()
            ->to($wasForced ? LoginController::homeFor($staff->fresh()) : route('password.edit'))
            ->with('success', $wasForced
                ? 'Your password is set. This account is yours now - nobody else knows it.'
                : 'Password changed. Any other device signed in as you has been signed out.');
    }
}
