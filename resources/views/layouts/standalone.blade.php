<!DOCTYPE html>
<html lang="{{ \App\Support\Locales::declaredLanguage(app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>@hasSection('title')@yield('title') – @endif{{ config('app.name', 'VideTrack') }}</title>

        {{-- Self-contained on purpose: no Vite, no CDN. The pages drawn on
             this layout (health check, error pages) must render when the app
             itself is what is broken — asset build included. Colours are the
             design tokens from resources/css/app.css. --}}
        <style>
            *, *::before, *::after { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1.5rem;
                background: #0a0a0d;
                color: #f2f1f5;
                font-family: ui-sans-serif, system-ui, sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            .card {
                width: 100%;
                max-width: 28rem;
                padding: 1.75rem;
                border: 1px solid #202027;
                border-radius: 0.75rem;
                background: #141418;
            }

            .logo { display: block; height: 1.5rem; width: auto; }

            .status {
                display: flex;
                align-items: center;
                gap: 0.625rem;
                margin-top: 1.5rem;
            }

            .status svg { flex-shrink: 0; width: 1.25rem; height: 1.25rem; }

            .code {
                margin-top: 1.5rem;
                color: #85858d;
                font-size: 3rem;
                font-weight: 700;
                line-height: 1;
                font-variant-numeric: tabular-nums;
            }

            h1 { margin: 0; font-size: 1.125rem; font-weight: 700; }

            .code + h1 { margin-top: 0.75rem; }

            p { margin: 0.5rem 0 0; color: #9a9aa5; font-size: 0.875rem; line-height: 1.5; }

            a { color: #b794ff; text-decoration: none; }

            a:hover { color: #f2f1f5; }

            .actions { margin-top: 1.5rem; font-size: 0.875rem; }
        </style>
    </head>
    <body>
        <main class="card">
            <img src="/logo.svg" alt="VideTrack" width="162" height="27" loading="lazy" class="logo">

            @yield('content')
        </main>
    </body>
</html>
