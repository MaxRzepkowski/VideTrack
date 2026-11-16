<?php

use App\Catalog\TitleStatus;

test('a title is running while more of it still comes out, not before it starts or after it ends', function () {
    $running = array_values(array_filter(TitleStatus::cases(), fn (TitleStatus $status): bool => $status->isRunning()));

    expect($running)->toBe([TitleStatus::ReturningSeries, TitleStatus::Publishing, TitleStatus::OnHiatus])
        ->and(TitleStatus::NotYetPublished->isRunning())->toBeFalse()
        ->and(TitleStatus::Finished->isRunning())->toBeFalse();
});
