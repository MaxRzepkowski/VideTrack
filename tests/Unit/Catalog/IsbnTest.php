<?php

use App\Catalog\Isbn;

test('an ISBN-13 is kept, written with hyphens or without', function () {
    expect(Isbn::thirteen('978-0-261-10334-4'))->toBe('9780261103344')
        ->and(Isbn::thirteen('9780261103344'))->toBe('9780261103344');
});

test('an ISBN-10 becomes the ISBN-13 of the same book', function () {
    expect(Isbn::thirteen('0316769487'))->toBe('9780316769488')
        ->and(Isbn::thirteen('0-261-10334-4'))->toBe('9780261103344');
});

test('an ISBN-10 ending in X is read, the X is not a digit of the ISBN-13', function () {
    expect(Isbn::thirteen('080442957X'))->toBe('9780804429573');
});

test('anything of another length is not an ISBN', function () {
    expect(Isbn::thirteen(''))->toBeNull()
        ->and(Isbn::thirteen('12345'))->toBeNull()
        ->and(Isbn::thirteen('97802611033441'))->toBeNull()
        ->and(Isbn::thirteen('X316769487'))->toBeNull();
});

test('an ISBN-13 of the English-language groups is told from one of another language', function () {
    expect(Isbn::isEnglish('9780261103344'))->toBeTrue()
        ->and(Isbn::isEnglish('9781250301697'))->toBeTrue()
        ->and(Isbn::isEnglish('9798888888888'))->toBeTrue()
        ->and(Isbn::isEnglish('9788376382550'))->toBeFalse();
});
