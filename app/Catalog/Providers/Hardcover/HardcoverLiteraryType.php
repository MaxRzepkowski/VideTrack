<?php

namespace App\Catalog\Providers\Hardcover;

/**
 * Hardcover's `literary_type_id`: whether a book is made up or not.
 */
enum HardcoverLiteraryType: int
{
    case Fiction = 1;
    case Nonfiction = 2;
}
