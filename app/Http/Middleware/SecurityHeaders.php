<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for an internet-exposed self-host instance, on
 * every response (pages, API, webhooks, feeds). Anti-clickjacking, no MIME
 * sniffing, a Referrer-Policy that keeps token URLs (the calendar feed) from
 * leaking to external link targets, and a Content-Security-Policy.
 *
 * The CSP is about scripts: only this origin's, plus the inline ones the app
 * renders with the request's nonce (Vite's tags, Ziggy's routes). Images are
 * allowed from any HTTPS host because cover art comes from every provider;
 * styles stay inline-friendly because Vue binds style attributes.
 */
class SecurityHeaders
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // HSTS only over HTTPS (harmless otherwise, but avoid pinning plain-HTTP dev),
        // and for this host alone: an instance on a home domain must not pin
        // HTTPS for a year on the owner's other subdomains (a NAS, a router).
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function appOrigin(): ?string
    {
        $url = parse_url(config()->string('app.url'));

        if (! isset($url['scheme'], $url['host'])) {
            return null;
        }

        return $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
    }

    private function contentSecurityPolicy(): string
    {
        // In development the scripts and styles come from the Vite server,
        // and hot reload talks to it over a WebSocket.
        $vite = Vite::isRunningHot() ? trim((string) file_get_contents(Vite::hotFile())) : null;
        $viteSocket = $vite === null ? null : (string) preg_replace('#^http#', 'ws', $vite);

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-".Vite::cspNonce()."'", $vite],
            'style-src' => ["'self'", "'unsafe-inline'", $vite],
            // The app's own URL too: stored files (avatars) are linked through
            // APP_URL, which need not be the host the page was opened on.
            'img-src' => ["'self'", $this->appOrigin(), 'data:', 'blob:', 'https:'],
            'font-src' => ["'self'", 'data:', $vite],
            'connect-src' => ["'self'", $vite, $viteSocket],
            'frame-src' => ['https://www.youtube-nocookie.com'],
            'media-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return implode('; ', array_map(
            fn (string $name, array $sources): string => $name.' '.implode(' ', array_filter($sources)),
            array_keys($directives),
            $directives,
        ));
    }
}
