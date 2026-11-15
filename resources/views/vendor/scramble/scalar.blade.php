<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ $config->get('ui.title') ?? config('app.name') . ' - API Docs' }}</title>

    {{-- Built with the app and served from this origin, not a CDN: the page
         runs with the session, under this app's script policy. --}}
    @vite('resources/js/api-docs.ts')

    @include('scramble::dev-tools', ['renderer' => 'scalar'])
</head>
<body>
<div id="api-docs"></div>
<script type="application/json" id="api-spec">@json($result->spec($config->openApiVersion()))</script>
</body>
</html>
