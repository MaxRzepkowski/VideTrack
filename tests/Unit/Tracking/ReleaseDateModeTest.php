<?php

use App\Enums\ReleaseDateMode;

test("each release mode shows its own of a film's two dates, and none shows neither", function (ReleaseDateMode $mode, bool $premiere, bool $home) {
    expect($mode->showsPremiere())->toBe($premiere)
        ->and($mode->showsDigital())->toBe($home);
})->with([
    'both' => [ReleaseDateMode::Both, true, true],
    'cinema' => [ReleaseDateMode::Cinema, true, false],
    'digital' => [ReleaseDateMode::Digital, false, true],
    'none' => [ReleaseDateMode::None, false, false],
]);
