<?php

namespace App\Tracking\Settings;

use App\Catalog\MediaTypeDefinition;

/**
 * Code that reads a setting only some kinds of title have, declaring it for
 * the tracking page: the setting is offered where it is interpreted, so a new
 * one is a declaration here, not an edit to the page.
 */
interface DeclaresTypeOptions
{
    /**
     * @return list<TypeOption>
     */
    public function typeOptions(MediaTypeDefinition $type): array;
}
