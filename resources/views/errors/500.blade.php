@extends('errors.layout')
@section('code', 'ERROR 500')
@section('title', 'Something went wrong on our side')
@section('icon')
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
@endsection
@section('body')
<p>The system hit an error it could not recover from. It has been recorded, and whoever looks after the server has been notified.</p>
<p>Nothing you typed on the previous screen was saved. If this keeps happening, note the time and tell the Tourism office.</p>
@endsection
@section('actions')
<a href="javascript:history.back()">Go back</a>
<a href="{{ url('/') }}" class="muted">Home</a>
@endsection
