<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_any;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * `proofs.ignore`: globs of paths the project states no test reads, such as
 * `docs/**`, each a `Glob` as every glob of the config is.
 */
final readonly class Ignored
{
    /** Why a glob that matches a file defining the runner leaves nothing out. */
    private const string OVERRULED
        = 'proofs.ignore lists %s, which matches %s. That file defines the runner, so every proof key reads it.';

    /** @param list<Glob> $globs */
    private function __construct(private array $globs)
    {
    }

    public static function nothing(): self
    {
        return new self([]);
    }

    public static function globs(string ...$globs): self
    {
        return self::of(...array_map(Glob::of(...), array_values($globs)));
    }

    /** The globs `proofs.ignore` lists, as the config reads them. */
    public static function of(Glob ...$globs): self
    {
        return new self(array_values($globs));
    }

    /**
     * A warning for each glob that matches this file, which defines the runner
     * and which every key therefore reads all the same.
     */
    public function overruledFor(Path $definition): Warnings
    {
        $warnings = [];

        foreach ($this->globs as $glob) {
            $warnings = $glob->matches($definition)
                ? [...$warnings, Warning::that(sprintf(self::OVERRULED, $glob->value(), $definition->value()))]
                : $warnings;
        }

        return Warnings::of(...$warnings);
    }

    public function matches(Path $path): bool
    {
        return array_any($this->globs, static fn(Glob $glob): bool => $glob->matches($path));
    }
}
