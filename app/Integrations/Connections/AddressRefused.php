<?php

namespace App\Integrations\Connections;

/**
 * Not a web address, or one in a private network the owner may not reach:
 * the same address is refused every time, until the owner or the operator
 * changes something.
 */
final class AddressRefused extends UnusableAddress {}
