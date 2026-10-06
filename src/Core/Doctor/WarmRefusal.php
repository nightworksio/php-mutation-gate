<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;

/**
 * Why the last run's warm workers forked nothing, as the PHPUnit runner keeps
 * it in its directory of the gate's: the guard's reason, naming the bootstrap
 * file, and its line where PHP can tell (ADR-0023, decision 13).
 */
final readonly class WarmRefusal
{
    /** The file it is kept in, in the PHPUnit runner's directory of the gate's. */
    public const string NAME = 'warm-refused.txt';

    private function __construct(private string $reason)
    {
    }

    public static function of(string $reason): self
    {
        return new self($reason);
    }

    /** Where it is kept, as a path of the project. */
    public static function file(): Path
    {
        return Workspace::root()->child(Path::of(BuiltinRunner::PhpUnit->value))->child(Path::of(self::NAME));
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
