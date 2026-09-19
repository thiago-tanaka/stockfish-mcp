<?php

declare(strict_types=1);

namespace App\Analysis;

enum AnalysisStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
