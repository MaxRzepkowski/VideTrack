<?php

use App\Catalog\Department;
use App\Catalog\LeadJobs;

test('the lead jobs of a department are the ones the providers single out', function () {
    $jobs = app(LeadJobs::class);

    expect($jobs->in(Department::Directing))->toContain('Director')
        ->and($jobs->in(Department::Directing))->not->toContain('First Assistant Director')
        ->and($jobs->in(Department::Writing))->toContain('Screenplay')
        ->and($jobs->in(Department::Acting))->toBe([]);
});
