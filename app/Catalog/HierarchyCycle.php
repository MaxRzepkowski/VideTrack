<?php

namespace App\Catalog;

use RuntimeException;

/** Corrupt data: a chain of parent links that loops back on itself. */
final class HierarchyCycle extends RuntimeException {}
