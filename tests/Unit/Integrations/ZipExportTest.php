<?php

use App\Integrations\Import\ZipExport;

test('a ZIP export lists its files, their sizes and contents', function () {
    $path = scratchPath('zip', '.zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addEmptyDir('likes');
    $zip->addFromString('likes/films.csv', 'Name');
    $zip->addFromString('watched.csv', 'Name,Year');
    $zip->close();

    $export = new ZipExport($path);

    expect($export->names())->toBe(['likes/films.csv', 'watched.csv'])
        ->and($export->has('watched.csv'))->toBeTrue()
        ->and($export->size('watched.csv'))->toBe(9)
        ->and($export->contents('likes/films.csv'))->toBe('Name')
        ->and($export->contents('diary.csv'))->toBeNull()
        ->and($export->size('diary.csv'))->toBeNull();
});

test('a file that is not a ZIP holds nothing', function () {
    $path = scratchPath('csv');
    file_put_contents($path, "Name\nDune\n");

    $export = new ZipExport($path);

    expect($export->names())->toBe([])
        ->and($export->has('Name'))->toBeFalse();
});
