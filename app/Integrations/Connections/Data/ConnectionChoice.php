<?php

namespace App\Integrations\Connections\Data;

/**
 * One account a service holds that the user may be (a Tracearr instance
 * watches a whole household).
 */
final readonly class ConnectionChoice
{
    public function __construct(
        public string $id,
        public string $label,
    ) {}

    /**
     * @return array{id: string, label: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label];
    }
}
