<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// The installable app: the page points the browser at the manifest and the
// icons, and everything they name is a file the web server hands out.

function webManifest(): array
{
    return json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
}

test('a page links the manifest, the icons and the bar colour, each a file in the web root', function () {
    $html = (string) $this->get(route('login'))->assertOk()->getContent();

    preg_match_all('/<link rel="(icon|apple-touch-icon|manifest)" href="([^"]+)"/', $html, $links);

    expect($links[1])->toContain('icon', 'apple-touch-icon', 'manifest')
        ->and($html)->toContain('<meta name="theme-color" content="'.webManifest()['theme_color'].'">');

    foreach ($links[2] as $href) {
        expect(public_path($href))->toBeFile();
    }
});

test('the manifest opens on the dashboard and offers icons of the sizes it claims', function () {
    $manifest = webManifest();

    expect($manifest['start_url'])->toBe(parse_url(route('dashboard'), PHP_URL_PATH))
        ->and(array_column($manifest['icons'], 'purpose'))->toContain('maskable');

    foreach ($manifest['icons'] as $icon) {
        [$width, $height] = getimagesize(public_path($icon['src'])) ?: [0, 0];

        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

test('each shortcut of the manifest is a page of the app', function () {
    foreach (webManifest()['shortcuts'] as $shortcut) {
        $route = Route::getRoutes()->match(Request::create($shortcut['url']));

        expect($route->methods())->toContain('GET');
    }
});

test('the offline page the service worker keeps is in the web root', function () {
    expect(public_path('offline.html'))->toBeFile();
});

test('the bar, the splash and the icon tile wear the design tokens', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));
    $token = function (string $name) use ($css): string {
        preg_match('/--color-'.$name.':\s*(#[0-9a-f]{6});/i', $css, $match);

        return strtolower($match[1] ?? 'missing');
    };
    $manifest = webManifest();

    expect(strtolower($manifest['theme_color']))->toBe($token('surface-base'))
        ->and(strtolower($manifest['background_color']))->toBe($token('surface-base'))
        ->and(strtolower((string) file_get_contents(public_path('favicon.svg'))))->toContain('fill="'.$token('surface').'"');
});
