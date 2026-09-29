<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;

/** Where a mutant is: its file, the line it starts on, and the line it ends on where the runner says. */
final readonly class Location
{
    private function __construct(private Path $file, private Line $start, private Line|Unreported $end)
    {
    }

    public static function of(Path $file, Line $start, Line|Unreported $end): self
    {
        return new self($file, $start, $end);
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function start(): Line
    {
        return $this->start;
    }

    public function end(): Line|Unreported
    {
        return $this->end;
    }
}
