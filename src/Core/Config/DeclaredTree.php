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
    private function __construct(private Path $path, private Floor|Exempt|Undeclared $declared)
    {
    }

    /** A `trees` entry: a floor of 0 has to carry its reason. */
    public static function read(Fields $read, string $at): self|Invalid
    {
        $floor = $read->optional('floor', Floor::class);
        $path = $read->object('path', Path::class);

        return match (true) {
            $floor instanceof Absent => new self($path, Undeclared::floor()),
            $floor->hundredths() > 0 => new self($path, $floor),
            $read->has('reason') => new self($path, Exempt::because($read->string('reason'))),
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
}
