<?php

use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Enums\MediaUserStatus;
use App\Library\WatchedAt;
use App\Models\Favorite;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use Carbon\CarbonImmutable;

function seedExportData(User $user): MediaItem
{
    $movie = MediaItem::factory()->movie()->create(['title' => 'The Matrix', 'provider_ref' => '603', 'year' => 1999]);

    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $movie->id,
        'watched_at' => '2026-01-05 20:00:00',
    ]);
    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'rating' => 8]);
    Favorite::query()->create(['user_id' => $user->id, 'media_item_id' => $movie->id]);

    $show = MediaItem::factory()->show()->create();
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $show->id,
        'status' => MediaUserStatus::OnHold,
    ]);

    $user->watchlist()->items()->create(['media_item_id' => $show->id, 'position' => 1]);

    $list = $user->lists()->create(['title' => 'Cyberpunk', 'description' => 'Neon', 'is_public' => true]);
    $list->items()->create(['media_item_id' => $movie->id, 'position' => 1]);
    projectLibrary($user);

    return $movie;
}

test('the export page renders', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/export')
        ->assertOk();
});

test('the JSON export contains every user state section', function () {
    $user = User::factory()->create();
    seedExportData($user);

    $response = $this->actingAs($user)->get('/settings/export/videtrack');

    $response->assertOk();
    $response->assertHeader('content-type', 'application/json');

    $data = json_decode($response->streamedContent(), true);

    expect($data['format'])->toBe('videtrack-export')
        ->and($data['version'])->toBe(1)
        ->and($data['username'])->toBe($user->username)
        ->and($data['plays'])->toHaveCount(1)
        ->and($data['plays'][0])->toMatchArray(['type' => 'movie', 'provider' => 'tmdb', 'ref' => '603'])
        ->and($data['plays'][0]['watched_at'])->toStartWith('2026-01-05T20:00:00')
        ->and($data['plays'][0]['outcome'])->toBe('completed')
        ->and($data['ratings'][0]['rating'])->toBe(8)
        ->and($data['favorites'])->toHaveCount(1)
        ->and($data['statuses'][0]['status'])->toBe('on_hold')
        ->and(collect($data['lists'])->pluck('system_key')->all())->toContain('watchlist');

    $custom = collect($data['lists'])->firstWhere('title', 'Cyberpunk');

    expect($custom['is_public'])->toBeTrue()
        ->and($custom['items'][0]['ref'])->toBe('603');
});

test('the JSON export only contains the requesting user\'s data', function () {
    $user = User::factory()->create();
    seedExportData(User::factory()->create());

    $response = $this->actingAs($user)->get('/settings/export/videtrack');

    $data = json_decode($response->streamedContent(), true);

    expect($data['plays'])->toBeEmpty()
        ->and($data['ratings'])->toBeEmpty()
        ->and($data['favorites'])->toBeEmpty()
        ->and($data['statuses'])->toBeEmpty()
        // Their own watchlist alone, empty.
        ->and($data['lists'])->toHaveCount(1)
        ->and($data['lists'][0]['system_key'])->toBe('watchlist')
        ->and($data['lists'][0]['items'])->toBeEmpty();
});

test('each download is named after the user, the part it holds and the day', function (string $format, string $name) {
    $user = User::factory()->create(['username' => 'alex']);

    $this->actingAs($user)
        ->get("/settings/export/{$format}")
        ->assertHeader('content-disposition', "attachment; filename={$name}");
})->with([
    'the backup' => ['videtrack', 'videtrack-alex-2026-10-10.json'],
    'a sheet' => ['history', 'videtrack-alex-history-2026-10-10.csv'],
    'a service' => ['imdb', 'videtrack-alex-imdb-2026-10-10.csv'],
]);

test('the CSV exports render each section', function () {
    $user = User::factory()->create();
    seedExportData($user);

    $history = $this->actingAs($user)->get('/settings/export/history');

    $history->assertOk();
    expect($history->streamedContent())
        ->toContain('type,provider,ref,title,watched_at')
        ->toContain('movie,tmdb,603,"The Matrix"');

    $lists = $this->actingAs($user)->get('/settings/export/lists');

    expect($lists->streamedContent())
        ->toContain('list,visibility,position,type,provider,ref,title')
        ->toContain('Cyberpunk,public,1,movie,tmdb,603');
});

test('the Letterboxd export carries films with their rating and last watch date', function () {
    $user = User::factory()->create();
    seedExportData($user);

    $response = $this->actingAs($user)->get('/settings/export/letterboxd');

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('Title,Year,Rating10,WatchedDate,tmdbID')
        ->toContain('"The Matrix",1999,8,2026-01-05,603');
});

test('the Letterboxd export dates only a film watched to the end, and leaves out one only dropped', function () {
    $user = User::factory()->create();
    $rated = MediaItem::factory()->movie()->create(['title' => 'Rated, then dropped', 'year' => 2001]);
    $dropped = MediaItem::factory()->movie()->create(['title' => 'Only dropped']);
    app(RateItem::class)->execute($user, $rated, 6);
    app(DropAttempt::class)->execute($user, $rated, WatchedAt::at(CarbonImmutable::parse('2026-04-02 20:00')));
    app(DropAttempt::class)->execute($user, $dropped, WatchedAt::at(CarbonImmutable::parse('2026-04-02 20:00')));

    $content = $this->actingAs($user)->get('/settings/export/letterboxd')->streamedContent();

    expect($content)->toContain('"Rated, then dropped",2001,6,,')
        ->not->toContain('Only dropped');
});

test('the Letterboxd export leaves out shows, which the format cannot hold', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create(['title' => 'Kryminalni']);
    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'rating' => 7]);

    $content = $this->actingAs($user)->get('/settings/export/letterboxd')->streamedContent();

    expect($content)->not->toContain('Kryminalni');
});

test('the IMDb export keys rows by Const and covers shows too', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create([
        'title' => 'Breaking Bad',
        'year' => 2008,
        'metadata' => ['imdbId' => 'tt0903747'],
    ]);
    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'rating' => 10]);

    $response = $this->actingAs($user)->get('/settings/export/imdb');

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('Const,"Your Rating","Date Rated",Title,"Title Type",Year')
        ->toContain('tt0903747,10,')
        ->toContain('"Breaking Bad","TV Series",2008');
});

test('an IMDb row without a known Const still carries title and year', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['title' => 'Sicario', 'year' => 2015, 'metadata' => []]);
    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'rating' => 9]);

    $content = $this->actingAs($user)->get('/settings/export/imdb')->streamedContent();

    expect($content)->toContain(',9,')->toContain('Sicario,Movie,2015');
});

test('a film rated but never played exports without a watch date', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create([
        'title' => 'Sicario', 'provider_ref' => '273481', 'year' => 2015,
    ]);
    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'rating' => 9]);

    $content = $this->actingAs($user)->get('/settings/export/letterboxd')->streamedContent();

    expect($content)->toContain('Sicario,2015,9,,273481');
});

test('an unknown export service is a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/export/facebook')
        ->assertNotFound();
});

test('the service exports only contain the requesting user\'s data', function () {
    $user = User::factory()->create();
    seedExportData(User::factory()->create());

    $content = $this->actingAs($user)->get('/settings/export/letterboxd')->streamedContent();

    expect($content)->not->toContain('The Matrix');
});

test('an unknown CSV section is a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/export/passwords')
        ->assertNotFound();
});

test('guests cannot export', function () {
    $this->get('/settings/export/videtrack')->assertRedirect('/login');
});

test('the export page and the profile take their lists from the server', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.export.index'))
        ->assertInertia(fn ($page) => $page->where('formats', [
            ['code' => 'videtrack', 'label' => 'Download JSON', 'shelf' => 'backup'],
            ['code' => 'history', 'label' => 'History', 'shelf' => 'spreadsheet'],
            ['code' => 'ratings', 'label' => 'Ratings', 'shelf' => 'spreadsheet'],
            ['code' => 'favorites', 'label' => 'Favorites', 'shelf' => 'spreadsheet'],
            ['code' => 'lists', 'label' => 'Lists', 'shelf' => 'spreadsheet'],
            ['code' => 'letterboxd', 'label' => 'Letterboxd', 'shelf' => 'service'],
            ['code' => 'imdb', 'label' => 'IMDb', 'shelf' => 'service'],
            ['code' => 'myanimelist-anime', 'label' => 'MyAnimeList: anime', 'shelf' => 'service'],
        ]));

    $this->actingAs($user)
        ->get(route('settings.profile.edit'))
        ->assertInertia(fn ($page) => $page->where('languages', [['value' => 'en', 'label' => 'English'], ['value' => 'pl', 'label' => 'Polski']]));
});

test('the watch date is the day on the user\'s own calendar', function () {
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    $film = MediaItem::factory()->movie()->create(['title' => 'Late Show', 'year' => 1999, 'provider_ref' => '603']);
    // Half past midnight in Warsaw is still the evening before in UTC.
    app(MarkWatched::class)->execute($user, $film, WatchedAt::at(CarbonImmutable::parse('2026-03-01 23:30', 'UTC')));

    $content = $this->actingAs($user)->get('/settings/export/letterboxd')->streamedContent();

    expect($content)->toContain('"Late Show",1999,,2026-03-02,603');
});
