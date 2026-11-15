<?php

use App\Enums\FollowMode;
use App\Models\Company;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function runFollowedCompanies(): void
{
    runScheduledTask('refresh-followed-companies');
}

/**
 * A followed TMDB studio whose film opened 50 days ago, with its home release
 * at $homeRelease (null: none announced).
 */
function followStudioWithFilmInCinemas(?string $homeRelease): User
{
    $opened = today()->subDays(50)->toDateString();

    $movie = jsonFixture('tmdb/movie');
    $movie['release_date'] = $opened;
    $movie['release_dates']['results'] = [[
        'iso_3166_1' => 'US',
        'release_dates' => array_values(array_filter([
            ['certification' => 'R', 'release_date' => $opened.'T00:00:00.000Z', 'type' => 3],
            $homeRelease === null ? null : ['certification' => '', 'release_date' => $homeRelease.'T00:00:00.000Z', 'type' => 4],
        ])),
    ]];

    Http::fake([
        '*/company/174*' => Http::response(['id' => 174, 'name' => 'Warner Bros. Pictures', 'logo_path' => '/wb.png']),
        '*/discover/movie*' => Http::response(['results' => [
            ['id' => 603, 'title' => 'Playing In Cinemas', 'poster_path' => '/cinema.jpg', 'release_date' => $opened],
        ], 'total_pages' => 1]),
        '*/discover/tv*' => Http::response(['results' => [], 'total_pages' => 1]),
        '*/movie/603*' => Http::response($movie),
        ...providerDictionaryFakes(),
    ]);

    $user = User::factory()->create();
    $studio = Company::factory()->create(['provider' => 'tmdb', 'kind' => 'company', 'provider_ref' => '174', 'name' => 'Warner Bros. Pictures']);
    Follow::factory()->create(['user_id' => $user->id, 'company_id' => $studio->id, 'mode' => FollowMode::Follow]);

    return $user;
}

test('a studio film past its premiere is followed while its home release is ahead', function () {
    $user = followStudioWithFilmInCinemas(today()->addDays(20)->toDateString());

    runFollowedCompanies();

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->sole();

    expect(Follow::query()->where('user_id', $user->id)->where('media_item_id', $movie->id)->exists())->toBeTrue()
        ->and($user->notifications()->count())->toBe(1);
});

test('a studio film with nothing left ahead is not followed', function () {
    $user = followStudioWithFilmInCinemas(today()->subDays(10)->toDateString());

    runFollowedCompanies();

    expect(Follow::query()->where('user_id', $user->id)->whereNotNull('media_item_id')->exists())->toBeFalse();
});
