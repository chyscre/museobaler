<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->to(self::homeFor(Auth::user()));
        }
        return view('auth.login');
    }

    /**
     * Where signing in lands you.
     *
     * The dashboard is the museum's operations screen and the Tourism office
     * has no access to it, so sending the head of tourism there would greet
     * her with a 403 every morning. Her job starts at the attendance board.
     */
    public static function homeFor(\App\Models\Staff $user): string
    {
        return $user->isTourismHead()
            ? route('staff-attendance.index')
            : route('dashboard');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            // SECURITY: Never pass 'remember' — persistent tokens are disabled for admin accounts
            if (!Auth::user()->status) {
                Auth::logout();
                return back()->withErrors(['email' => 'Your account has been deactivated.']);
            }
            $request->session()->regenerate();
            return redirect()->intended(self::homeFor(Auth::user()));
        }

        return back()->withErrors([
            'email' => 'Invalid email or password.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
