@extends('errors.layout')
@section('code', 'ERROR 419')
@section('title', 'That form had been open too long')
@section('icon')
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
@endsection
@section('body')
<p>The page was left open past the session limit, so the form was refused to be safe. Nothing you typed was saved.</p>
<p>Go back, and it will work the second time.</p>
@endsection
@section('actions')
<a href="javascript:history.back()">Go back</a>
<a href="{{ route('login') }}" class="muted">Sign in again</a>
@endsection
