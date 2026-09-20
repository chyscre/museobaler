@extends('errors.layout')
@section('code', 'ERROR 429')
@section('title', 'Slow down a moment')
@section('icon')
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4"/><path d="m16.24 7.76 2.83-2.83"/><path d="M18 12h4"/><path d="m16.24 16.24 2.83 2.83"/><path d="M12 18v4"/><path d="m4.93 19.07 2.83-2.83"/><path d="M2 12h4"/><path d="m4.93 4.93 2.83 2.83"/></svg>
@endsection
@section('body')
<p>Too many requests came from this account in the last minute, so the panel paused them. That is usually a browser tab stuck reloading, not anything you did.</p>
<p>Wait a minute and try again.</p>
@endsection
@section('actions')
<a href="javascript:location.reload()">Try again</a>
@endsection
