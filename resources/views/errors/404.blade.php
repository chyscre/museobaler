@extends('errors.layout')
@section('code', 'ERROR 404')
@section('title', 'That page is not here')
@section('icon')
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
@endsection
@section('body')
<p>The address may have been typed wrong, or the record it pointed to has since been removed.</p>
@endsection
@section('actions')
@auth
<a href="{{ url('/') }}">Back to the panel</a>
@else
<a href="{{ route('login') }}">Sign in</a>
@endauth
@endsection
