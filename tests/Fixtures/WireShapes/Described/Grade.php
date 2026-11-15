<?php

namespace Tests\Fixtures\WireShapes\Described;

/** Not marked: the front has no type for it, so its values are spelled out. */
enum Grade: string
{
    case High = 'high';
    case Low = 'low';
}
