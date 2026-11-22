<?php

namespace App\Notifications\Data;

/**
 * An event told in parts, as a line of a list: the title it is about, what
 * happened, the facts it adds and the value it moved. The digest builds its
 * line from these parts (NotificationCopy::digestParts()).
 */
final readonly class EventLine
{
    /**
     * @param  string  $subject  the title it is about (the line links it)
     * @param  string  $label  the event spelled out ("New episode", "Out today"); empty where the subject says it
     * @param  list<string>  $facts  what it adds, each its own part
     * @param  bool  $atAirTime  the facts happen at the position's air time, which plain text adds in the reader's zone
     */
    public function __construct(
        public string $subject,
        public string $label,
        public array $facts = [],
        public ?ValueChange $change = null,
        public bool $atAirTime = false,
    ) {}
}
