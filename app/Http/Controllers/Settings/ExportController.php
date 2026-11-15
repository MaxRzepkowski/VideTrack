<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Integrations\Export\Data\ExportDownload;
use App\Integrations\Export\ExportFormatRegistry;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "Export Data" settings section: every download the registered formats
 * offer — the whole backup, flat sheets, other services' import formats —
 * and the calendar feed.
 */
class ExportController extends Controller
{
    public function index(#[CurrentUser] User $viewer, ExportFormatRegistry $formats): Response
    {
        $feedUrl = route('calendar.feed', ['token' => $viewer->calendarToken()]);

        return Inertia::render('settings/Export', [
            'calendarFeedUrl' => $feedUrl,
            // webcal:// makes calendar apps offer to subscribe on click.
            'calendarWebcalUrl' => (string) preg_replace('#^https?://#', 'webcal://', $feedUrl),
            'formats' => array_map(ExportDownload::of(...), $formats->all()),
        ]);
    }

    /**
     * The download streams as it is written, so it starts at once however
     * long the history is.
     */
    public function download(#[CurrentUser] User $viewer, ExportFormatRegistry $formats, string $format): StreamedResponse
    {
        $download = $formats->find($format) ?? abort(404);
        $stem = $viewer->username.($download->part() === null ? '' : '-'.$download->part());

        return response()->streamDownload(
            function () use ($download, $viewer): void {
                $out = fopen('php://output', 'w');

                if ($out !== false) {
                    $download->write($viewer, $out);
                    fclose($out);
                }
            },
            'videtrack-'.$stem.'-'.now()->format('Y-m-d').'.'.$download->extension(),
            ['Content-Type' => $download->contentType()],
        );
    }
}
