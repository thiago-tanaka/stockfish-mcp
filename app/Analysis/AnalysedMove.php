<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Chess\Fen;
use App\Engine\Evaluation;

/**
 * One half-move, judged against what the engine would have played.
 */
final readonly class AnalysedMove
{
    public function __construct(
        public int $ply,
        public string $san,
        public Fen $fenBefore,
        /** Of the position before the move, from White's point of view. */
        public Evaluation $evaluation,
        public ?string $bestMoveSan,
        public int $centipawnLoss,
        public Classification $classification,
        /** The player had a forced mate and the move played gave it up. */
        public bool $missedMate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ply' => $this->ply,
            'san' => $this->san,
            'fen_before' => $this->fenBefore->value,
            'eval_cp' => $this->evaluation->centipawns,
            'mate_in' => $this->evaluation->mateIn,
            'best_move_san' => $this->bestMoveSan,
            'centipawn_loss' => $this->centipawnLoss,
            'classification' => $this->classification->value,
            'missed_mate' => $this->missedMate,
        ];
    }
}
