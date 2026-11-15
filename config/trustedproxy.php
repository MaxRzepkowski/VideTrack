<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Whose X-Forwarded-* headers to believe: the client IP behind the login
    | throttle and every per-IP limit, and the HTTPS scheme, come from them.
    | The default trusts private addresses only: a proxy on the host or in the
    | Docker network connects from one, while a visitor from the internet
    | reaching an exposed port keeps a public address, so the headers it
    | forges are ignored. The local network is trusted on purpose. A proxy on
    | a public address (another server, a tunnel) is listed here, comma-
    | separated; "*" trusts any sender and is right only when nothing but the
    | proxy can reach the app. Laravel's TrustProxies middleware reads this
    | key itself.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', 'PRIVATE_SUBNETS'),

];
