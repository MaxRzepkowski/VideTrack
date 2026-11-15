{{-- Replaces the framework's `errors::minimal`, which every built-in error
     view (401 … 503) extends — overriding this one file restyles them all,
     and their titles and messages stay the framework's translated ones. --}}
@extends('layouts.standalone')

@section('content')
    <div class="code">@yield('code')</div>
    <h1>@yield('message')</h1>

    <div class="actions">
        <a href="{{ url('/') }}">{{ __('errors.home') }}</a>
    </div>
@endsection
