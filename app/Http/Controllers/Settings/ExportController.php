<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Data\SubscriptionFeed;
use App\Integrations\Export\Data\ExportDownload;
use App\Integrations\Export\ExportFormatRegistry;
use App\Models\User;
use App\Tracking\Surfaces\IcsFeed;
use App\Tracking\Surfaces\RssFeed;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "Export Data" settings section: every download the registered formats
 * offer — the whole backup, flat sheets, other services' import formats —
 * and the calendar feeds.
 */
class ExportController extends Controller
{
    public function index(#[CurrentUser] User $viewer, ExportFormatRegistry $formats): Response
    {
        $token = $viewer->calendarToken();

        return Inertia::render('settings/Export', [
            'calendarFeed' => new SubscriptionFeed(route('calendar.feed', ['token' => $token]), IcsFeed::DEFAULT_SCOPE),
            'rssFeed' => new SubscriptionFeed(route('calendar.rss', ['token' => $token]), RssFeed::DEFAULT_SCOPE),
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
