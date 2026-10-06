<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\AsItRuns;

/**
 * A CI definition that runs the gate, read as it runs: without its comments,
 * its blank lines, and the commit an action is pinned at. A pin that moves or
 * a comment that changes is not a change to how a mutant runs.
 */
final readonly class CiDefinition
{
    private function __construct(private Path $path, private string $asItRuns)
    {
    }

    public static function at(Path $path, Contents $contents): self
    {
        return new self($path, AsItRuns::text($contents));
    }

    public function path(): Path
    {
        return $this->path;
    }

    public function asItRuns(): string
    {
        return $this->asItRuns;
    }
}
