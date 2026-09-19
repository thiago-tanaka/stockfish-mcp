<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * How bad a move was, by how much it cost against the engine's choice.
 */
enum Classification: string
{
    case Blunder = 'blunder';
    case Mistake = 'mistake';
    case Inaccuracy = 'inaccuracy';
    case Good = 'ok';
}
