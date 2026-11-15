<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * `/up` — the framework's health check, redrawn in the app's own colours.
 * Laravel renders its version from a fixed file (white page, Tailwind from a
 * CDN), so the route is ours; the contract is kept as is: the same
 * DiagnosingHealth event, 200 when it passes, 500 when a listener throws. The
 * container's HEALTHCHECK reads nothing but the status code.
 */
final class HealthController extends Controller
{
    public function __invoke(): Response
    {
        $failed = false;

        try {
            Event::dispatch(new DiagnosingHealth);
        } catch (Throwable $e) {
            if (App::hasDebugModeEnabled()) {
                throw $e;
            }

            report($e);
            $failed = true;
        }

        return response()->view('health', ['failed' => $failed], $failed ? 500 : 200);
    }
}
