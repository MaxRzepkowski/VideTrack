<?php

use App\Support\PlainText;

test('tags are removed and entities decoded', function () {
    expect(PlainText::fromHtml('<p>Tom &amp; Jerry&#39;s <i>big</i>&nbsp;day</p>'))->toBe("Tom & Jerry's big day");
});

test('escaped markup stays visible text', function () {
    expect(PlainText::fromHtml('Use &lt;b&gt; for bold'))->toBe('Use <b> for bold');
});

test('an empty or missing description is null', function () {
    expect(PlainText::fromHtml('<p> </p>'))->toBeNull()
        ->and(PlainText::fromHtml(null))->toBeNull();
});
