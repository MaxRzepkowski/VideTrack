<?php

namespace App\Tasks\Attention;

use App\Tasks\Attention\Contracts\AttentionSource;
use App\Tasks\Attention\Data\AttentionItem;
use App\Tasks\Attention\Data\PageVisit;
use App\Tasks\Data\TaskLine;
use App\Tasks\Data\WordedLine;
use App\Tasks\Maintenance\CheckLevel;

/**
 * The files the instance writes: storage it cannot write to breaks uploads,
 * imports and the cache; without the public link, avatars do not show.
 */
final readonly class StorageSetup implements AttentionSource
{
    public function items(PageVisit $visit): array
    {
        $items = [];

        if (! is_writable(storage_path('app')) || ! is_writable(storage_path('framework'))) {
            $items[] = new AttentionItem(CheckLevel::Problem, __('tasks.storage_is_not_writable'), [
                WordedLine::of(new TaskLine('tasks.uploads_imports_and_cache_fail')),
                WordedLine::of(new TaskLine('tasks.give_the_app_user_the_storage', ['path' => storage_path()])),
            ], null);
        }

        $links = array_keys(config()->array('filesystems.links'));

        if (array_filter($links, fn (int|string $link): bool => ! file_exists((string) $link)) !== []) {
            $items[] = new AttentionItem(CheckLevel::Problem, __('tasks.the_storage_link_is_missing'), [
                WordedLine::of(new TaskLine('tasks.avatars_do_not_show')),
                WordedLine::of(new TaskLine('tasks.run_storage_link')),
            ], null);
        }

        return $items;
    }
}
