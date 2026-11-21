<?php

use App\Catalog\MatureContent;

test('each level lets in what the levels below it do and one rating more', function (MatureContent $mature, bool $suggestive, bool $adult) {
    expect($mature->allowsSuggestive())->toBe($suggestive)
        ->and($mature->allowsAdult())->toBe($adult);
})->with([
    'safe' => [MatureContent::Safe, false, false],
    'suggestive' => [MatureContent::Suggestive, true, false],
    'adult' => [MatureContent::Adult, true, true],
]);
