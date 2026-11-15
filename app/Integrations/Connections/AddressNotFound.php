<?php

namespace App\Integrations\Connections;

/**
 * The host name resolved to nothing: a typo in a form, or a resolver that
 * did not answer this time, which a later attempt may get past.
 */
final class AddressNotFound extends UnusableAddress {}
