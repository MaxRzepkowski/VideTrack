<?php

use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\Data\CheckLine;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

test('a kept line reads back as it was kept', function () {
    $line = new TaskLine('tasks.answered_status_at', ['status' => 401], ['time' => CarbonImmutable::parse('2026-10-10T10:30:00Z')]);

    expect(TaskLine::fromStored(json_decode((string) json_encode($line->stored()), true)))->toEqual($line);
});

test('what is not a kept line reads as none, and a broken placeholder is dropped', function () {
    expect(TaskLine::fromStored(null))->toBeNull()
        ->and(TaskLine::fromStored(['params' => []]))->toBeNull()
        ->and(TaskLine::fromStored(['key' => 'tasks.x', 'params' => ['a' => ['nested'], 'b' => 'kept'], 'moments' => ['t' => 'never']]))
        ->toEqual(new TaskLine('tasks.x', ['b' => 'kept']));
});

test('a moment is left for the page to write, and goes to it as an instant', function () {
    $line = new TaskLine('tasks.did_not_answer_at', moments: ['time' => CarbonImmutable::parse('2026-10-10T10:30:00Z')]);

    expect($line->wording('en'))->toBe(__('tasks.did_not_answer_at', [], 'en'))
        ->and($line->wording('en'))->toContain(':time')
        ->and(CarbonImmutable::parse($line->momentsOnTheWire()['time'])->eq(CarbonImmutable::parse('2026-10-10T10:30:00Z')))->toBeTrue();
});

test('a check line reads back with its level, details, subject, since, name and state', function () {
    $line = new CheckLine(
        CheckLevel::Problem,
        new TaskLine('tasks.provider_refuses_the_key', ['provider' => 'TMDB']),
        [new TaskLine('tasks.put_a_new_key_in_variables', ['variables' => 'TMDB_API_KEY'])],
        'tmdb',
        CarbonImmutable::parse('2026-10-10T10:30:00Z'),
        new TaskLine('tasks.service_name', ['name' => 'TMDB']),
        new TaskLine('tasks.provider_state_refuses_the_key'),
    );

    expect(CheckLine::fromStored(json_decode((string) json_encode($line->stored()), true)))->toEqual($line)
        ->and(CheckLine::fromStored(['level' => 'unknown', 'line' => ['key' => 'tasks.x']]))->toBeNull();
});

test('the kept lines of a row read back without the ones that are not lines', function () {
    $kept = new CheckLine(CheckLevel::Ok, new TaskLine('tasks.provider_answers', ['provider' => 'TMDB']));

    expect(CheckLine::listFromStored([$kept->stored(), ['level' => 'ok'], 'broken']))->toEqual([$kept])
        ->and(CheckLine::listFromStored(null))->toBe([]);
});
