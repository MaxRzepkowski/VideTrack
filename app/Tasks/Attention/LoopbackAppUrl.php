<?php

namespace App\Tasks\Attention;

use App\Support\LoopbackAddress;
use App\Support\PublicUrl;
use App\Tasks\Attention\Contracts\AttentionSource;
use App\Tasks\Attention\Data\AttentionItem;
use App\Tasks\Attention\Data\PageVisit;
use App\Tasks\Data\TaskLine;
use App\Tasks\Data\WordedLine;
use App\Tasks\Maintenance\CheckLevel;

/**
 * An APP_URL that names this very computer (localhost, 127.0.0.1) while the
 * admin opened the instance from another address: the links it sends out
 * (notifications, the calendar feed) then lead nowhere on any other device.
 * An APP_URL others can reach, opened here through localhost, is fine.
 */
final readonly class LoopbackAppUrl implements AttentionSource
{
    public function items(PageVisit $visit): array
    {
        $configured = rtrim(PublicUrl::to('/'), '/');

        if (! LoopbackAddress::is($configured) || LoopbackAddress::is($visit->root)) {
            return [];
        }

        return [new AttentionItem(CheckLevel::Warning, __('tasks.app_url_names_this_computer'), [
            WordedLine::of(new TaskLine('tasks.app_url_is_and_page_is', ['configured' => $configured, 'visited' => rtrim($visit->root, '/')])),
            WordedLine::of(new TaskLine('tasks.links_sent_out_lead_nowhere')),
            WordedLine::of(new TaskLine('tasks.set_app_url_to_an_address_others_reach')),
        ], null)];
    }
}
