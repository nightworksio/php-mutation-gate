<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/** A tree a config declares (ADR-0003): its path, and the floor it declares, if any. */
final readonly class DeclaredTree
{
    /** @param Listed<string> $exclude */
    private function __construct(
        private Path $path,
        private Floor|Exempt|Undeclared $declared,
        private Listed $exclude,
    ) {
    }

    /** A `trees` entry: a floor of 0 has to carry its reason. */
    public static function read(Fields $read, string $at): self|Invalid
    {
        $floor = $read->optional('floor', Floor::class);
        $path = $read->object('path', Path::class);
        $exclude = Listed::of($read->strings('exclude'));

        return match (true) {
            $floor instanceof Absent => new self($path, Undeclared::floor(), $exclude),
            $floor->hundredths() > 0 => new self($path, $floor, $exclude),
            $read->has('reason') => new self($path, Exempt::because($read->string('reason')), $exclude),
            default => Invalid::because(
                Problem::at(At::key($at, 'reason'), 'expected a reason when floor is 0, got nothing'),
            ),
        };
    }

    public function path(): Path
    {
        return $this->path;
    }

    public function declared(): Floor|Exempt|Undeclared
    {
        return $this->declared;
    }

    /** @return Listed<string> `trees[].exclude`: globs from the repository root of files that belong to no tree */
    public function exclude(): Listed
    {
        return $this->exclude;
    }
}
