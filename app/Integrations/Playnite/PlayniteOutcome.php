<?php

namespace App\Integrations\Playnite;

/**
 * What a Playnite completion status makes of a game. Playnite creates its
 * statuses in English whatever the language of its interface, and lets the
 * user rename them or add their own: a status it did not create stays on
 * the Playnite list only, as nothing says what it means.
 */
enum PlayniteOutcome: string
{
    case Finished = 'finished';
    case Playing = 'playing';
    case OnHold = 'on_hold';
    case Abandoned = 'abandoned';
    case Planned = 'planned';
    case Listed = 'listed';

    /** Playnite's own status names (folded) => what they make of a game. */
    private const STATUSES = [
        'completed' => self::Finished,
        'beaten' => self::Finished,
        'played' => self::Finished,
        'playing' => self::Playing,
        'on hold' => self::OnHold,
        'abandoned' => self::Abandoned,
        'plan to play' => self::Planned,
    ];

    public static function of(string $status): self
    {
        return self::STATUSES[mb_strtolower(trim($status))] ?? self::Listed;
    }

    /**
     * The translation key that names a status and what it does, for the
     * selection: ":status: …".
     */
    public function selectionLabel(): string
    {
        return match ($this) {
            self::Finished => 'import.playnite_status_finished',
            self::Playing => 'import.playnite_status_playing',
            self::OnHold => 'import.playnite_status_on_hold',
            self::Abandoned => 'import.playnite_status_abandoned',
            self::Planned => 'import.playnite_status_planned',
            self::Listed => 'import.playnite_status_listed',
        };
    }
}
