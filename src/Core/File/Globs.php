<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_any;

/** Patterns over paths, any one of which may match. */
final readonly class Globs
{
    /** @param array<Glob> $globs */
    private function __construct(private array $globs)
    {
    }

    public static function of(Glob ...$globs): self
    {
        return new self($globs);
    }

    public function with(Glob $glob): self
    {
        return new self([...$this->globs, $glob]);
    }

    public function matches(Path $path): bool
    {
        return array_any($this->globs, static fn(Glob $glob): bool => $glob->matches($path));
    }
}
