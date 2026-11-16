<?php

test('the visitor\'s browser header never becomes the header the instance sends to services', function () {
    $server = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';

    try {
        // As a web request without a cached configuration reads it.
        $config = require config_path('tracker.php');
    } finally {
        if ($server === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $server;
        }
    }

    expect($config['http']['user_agent'])->toStartWith('VideTrack/')
        ->and($config['wikidata']['user_agent'])->toStartWith('VideTrack/');
});
