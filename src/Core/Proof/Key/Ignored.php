<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_any;
use function array_values;
use function fnmatch;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * `proofs.ignore`: globs of paths the project states no test reads, such as
 * `docs/**`. A `*` matches across directories.
 */
final readonly class Ignored
{
    /** @param list<string> $globs */
    private function __construct(private array $globs)
    {
    }

    public static function nothing(): self
    {
        return new self([]);
    }

    public static function globs(string ...$globs): self
    {
        return new self(array_values($globs));
    }

    public function matches(Path $path): bool
    {
        return array_any($this->globs, static fn(string $glob): bool => fnmatch($glob, $path->value()));
    }
}
