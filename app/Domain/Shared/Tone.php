<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Les 5 tonalités d'état de l'interface (couleurs sémantiques, jamais décoratives).
 * Chaque état métier se projette sur l'une d'elles : badge--ok, badge--progress…
 */
enum Tone: string
{
    /** Vert : OK, validé, conforme. */
    case Ok = 'ok';
    /** Bleu (accent) : en cours. */
    case Progress = 'progress';
    /** Orange : attention, en attente, bientôt. */
    case Warn = 'warn';
    /** Rouge : critique, expiré, litige. */
    case Critical = 'critical';
    /** Gris : neutre, brouillon, annulé, sans objet. */
    case Neutral = 'neutral';
}
