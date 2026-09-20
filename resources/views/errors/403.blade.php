@extends('errors.layout')
@section('code', 'ERROR 403')
@section('title', 'Not part of your role')
@section('icon')
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
@endsection
@section('body')
<p>This screen belongs to a different role. Museum staff run the museum; the Tourism office holds the accounts, schedules and approvals. Neither can reach the other's screens.</p>
<p>If you have been asked to do this, the Tourism office can change what your account is.</p>
@endsection
@section('actions')
@auth
<a href="{{ \App\Http\Controllers\Auth\LoginController::homeFor(auth()->user()) }}">Back to your home screen</a>
<form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button type="submit" class="muted">Sign out</button></form>
@else
<a href="{{ route('login') }}">Sign in</a>
@endauth
@endsection
