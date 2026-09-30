<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_any;
use function array_key_exists;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The project's test and source files, read for where each reads a value a
 * mutant changes. A read inside another declaration's value is followed to
 * where that value is read in turn, at most three declarations deep; a longer
 * chain, or a read inside a value no reference names, is one the scan cannot
 * follow.
 */
final readonly class Codebase
{
    /** How many declarations deep a read is followed. */
    private const int STEPS = 3;

    /** @param list<Source> $sources */
    private function __construct(private array $sources, private Hierarchy $hierarchy)
    {
    }

    public static function of(Source ...$sources): self
    {
        return new self(array_values($sources), Hierarchy::of(...$sources));
    }

    /** The file at a path, if the codebase holds it. */
    public function has(Path $file): bool
    {
        return array_any($this->sources, fn(Source $source): bool => $source->path()->equals($file));
    }

    /** Where the project reads a value, followed through the declarations that read it. */
    public function references(Symbol|Unnamed $symbol): References
    {
        return $symbol instanceof Symbol
            ? $this->followed($symbol, 1, [$symbol->described() => true])
            : References::unknown();
    }

    /** @param array<string, true> $seen every declaration followed so far */
    private function followed(Symbol $symbol, int $step, array $seen): References
    {
        $found = References::none();

        foreach ($this->sources as $source) {
            $found = $found->and($this->through(Reads::of($symbol, $source, $this->hierarchy), $source, $step, $seen));
        }

        return $found;
    }

    /**
     * One file's reads, each that stands in another declaration's value
     * followed to where that is read.
     *
     * @param array<string, true> $seen
     */
    private function through(References $reads, Source $source, int $step, array $seen): References
    {
        $found = $reads->withoutSites();

        foreach ($reads->sites() as $site) {
            $inner = $source->isTest() ? Executable::line() : $source->symbolAt($site->token());
            $found = $found->and(match (true) {
                $inner instanceof Executable => References::at($site),
                array_key_exists($inner->described(), $seen) => References::none(),
                $inner instanceof Unnamed || $step >= self::STEPS => References::unknown(),
                default => $this->followed($inner, $step + 1, [...$seen, $inner->described() => true]),
            });
        }

        return $found;
    }
}
