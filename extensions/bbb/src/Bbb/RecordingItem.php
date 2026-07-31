<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.3 — Un enregistrement, **traduit dans le vocabulaire de l'extension.**
 *
 * Ce n'est PAS le `Record` de la bibliothèque : celui-là ne franchit jamais la
 * frontière du client BBB. Deux raisons, et la seconde a déjà coûté un incident
 * à cet epic :
 *
 * 1. il porte des accesseurs sans types et des champs dont personne ici n'a
 *    besoin (`metas`, `playbackType`, `isPublished`) ;
 * 2. **son constructeur explose** sur un enregistrement sans bloc `playback`
 *    (`$xml->playback->format->type->__toString()`, sans la moindre garde) —
 *    même famille que le `getMeetingLayout()` qui aurait cassé toute création de
 *    salon en 57.2. L'hydratation se fait donc enregistrement par
 *    enregistrement, sous garde, et un XML bancal n'emporte pas la liste.
 *
 * `startTime`/`endTime` arrivent de BigBlueButton en **millisecondes** depuis
 * l'époque Unix — pas en secondes. La vue divise ; personne d'autre n'a à le
 * savoir.
 */
final class RecordingItem
{
    public function __construct(
        public readonly string $recordId,
        /** Le `meetingID` BBB — c'est-à-dire le jeton PUBLIC d'un salon. */
        public readonly string $meetingId,
        /** Millisecondes depuis l'époque Unix. */
        public readonly float $startTime,
        public readonly float $endTime,
        /** URL de lecture chez BigBlueButton. Non secrète, mais échappée comme tout le reste. */
        public readonly string $playbackUrl,
        public readonly int $lengthMinutes,
    ) {
    }

    /** Horodatage Unix en SECONDES, prêt pour `date()`. `0` = date inconnue. */
    public function startedAt(): int
    {
        return (int) ($this->startTime / 1000);
    }
}
