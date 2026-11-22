<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0a0a0d">

        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" sizes="any" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon-180x180.png">
        <link rel="manifest" href="/manifest.webmanifest">

        <title data-inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Fetched beside the app's code; the app waits for it so its first paint already speaks the reader's language. --}}
        <link rel="preload" href="{{ $page['props']['translations'] }}" as="fetch" crossorigin="anonymous" data-translations>

        @routes(nonce: Vite::cspNonce())
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
