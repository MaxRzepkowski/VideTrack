<?php

use App\Enums\ActivityGrouping;
use App\Enums\ActivitySort;
use App\Enums\SortDirection;
use App\Users\ActivitySource;
use App\Users\Data\ActivityFilters;
use App\Users\PanelReader;

function filtersSortedBy(ActivitySort $sort, ActivityGrouping $group = ActivityGrouping::Grid): ActivityFilters
{
    return new ActivityFilters(sort: $sort, dir: SortDirection::Desc, group: $group);
}

test('only the history is finished, and only a visitor sorts by the owner’s rating', function () {
    expect(ActivitySource::Plays->offers(ActivitySort::Completions, PanelReader::Owner))->toBeTrue()
        ->and(ActivitySource::Ratings->offers(ActivitySort::Completions, PanelReader::Visitor))->toBeFalse()
        ->and(ActivitySource::Plays->offers(ActivitySort::OwnerRating, PanelReader::Visitor))->toBeTrue()
        ->and(ActivitySource::Plays->offers(ActivitySort::OwnerRating, PanelReader::Owner))->toBeFalse()
        ->and(ActivitySource::Favorites->offers(ActivitySort::Activity, PanelReader::Owner))->toBeFalse()
        ->and(ActivitySource::Favorites->offers(ActivitySort::Added, PanelReader::Visitor))->toBeTrue();
});

test('the ratings open on the reader’s own scores for the owner and on the owner’s for a visitor', function () {
    expect(ActivitySource::Ratings->defaultSort(PanelReader::Owner))->toBe(ActivitySort::MyRating)
        ->and(ActivitySource::Ratings->defaultSort(PanelReader::Visitor))->toBe(ActivitySort::OwnerRating)
        ->and(ActivitySource::Plays->defaultSort(PanelReader::Visitor))->toBe(ActivitySort::Activity);
});

test('a rating orders a page only laid out as a grid', function () {
    expect(ActivitySort::MyRating->isRating())->toBeTrue()
        ->and(ActivitySort::OwnerRating->isRating())->toBeTrue()
        ->and(ActivitySort::Community->isRating())->toBeFalse()
        ->and(filtersSortedBy(ActivitySort::OwnerRating)->byRating())->toBeTrue()
        ->and(filtersSortedBy(ActivitySort::OwnerRating, ActivityGrouping::Day)->byRating())->toBeFalse()
        ->and(filtersSortedBy(ActivitySort::Title)->byRating())->toBeFalse();
});
