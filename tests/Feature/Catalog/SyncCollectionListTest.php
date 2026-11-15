<?php

use App\Actions\Media\SyncCollectionList;
use App\Catalog\Data\CollectionDetails;
use App\Catalog\Data\MediaSummary;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use Illuminate\Support\Facades\Http;

test('a collection whose every part failed to load keeps the parts it had', function () {
    $part = MediaItem::factory()->movie()->create(['provider_ref' => '120']);
    $list = MediaList::factory()->create(['user_id' => null, 'system_key' => MediaList::collectionKey('tmdb', '119')]);
    ListItem::query()->create(['list_id' => $list->id, 'media_item_id' => $part->id, 'position' => 1]);

    // Parts the library does not hold yet, and TMDB down for each of them.
    Http::fake(['*' => Http::response([], 503)]);
    $collection = new CollectionDetails(ref: '119', name: 'The Lord of the Rings Collection', parts: [
        new MediaSummary(type: 'movie', ref: '121', provider: 'tmdb', title: 'The Two Towers'),
        new MediaSummary(type: 'movie', ref: '122', provider: 'tmdb', title: 'The Return of the King'),
    ]);

    $result = app(SyncCollectionList::class)->execute('tmdb', $collection, 'en');

    expect($result->removedItemIds)->toBe([])
        ->and($result->addedItemIds)->toBe([])
        ->and($list->items()->pluck('media_item_id')->all())->toBe([$part->id]);
});
