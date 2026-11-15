@extends('layouts.standalone')

@section('content')
    <div class="status">
        @if ($failed)
            {{-- lucide: circle-alert --}}
            <svg viewBox="0 0 24 24" fill="none" stroke="#ff5c5c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" x2="12" y1="8" y2="12" />
                <line x1="12" x2="12.01" y1="16" y2="16" />
            </svg>
            <h1>{{ __('app.videtrack_is_experiencing_problems') }}</h1>
        @else
            {{-- lucide: circle-check --}}
            <svg viewBox="0 0 24 24" fill="none" stroke="#6aff5c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10" />
                <path d="m9 12 2 2 4-4" />
            </svg>
            <h1>{{ __('app.videtrack_is_up') }}</h1>
        @endif
    </div>

    @if (defined('LARAVEL_START'))
        <p>{{ __('app.request_received_response_rendered_in_time', ['time' => round((microtime(true) - LARAVEL_START) * 1000)]) }}</p>
    @endif
@endsection
