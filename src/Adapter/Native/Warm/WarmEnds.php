<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * How runs ended in warm workers' children (ADR-0023): each that a child
 * ended, by its position among the runs, the most memory each child that
 * ended of itself held, and a warning for each worker whose boot the guard
 * refused. A run no child ended is left to a fresh process.
 *
 * @internal
 */
final readonly class WarmEnds
{
    /**
     * @param array<int, Ran>       $ran   by position
     * @param array<int, MemoryCap> $peaks by position
     */
    private function __construct(private array $ran, private array $peaks, private Warnings $warnings)
    {
    }

    /**
     * @param array<int, Ran>       $ran   by position
     * @param array<int, MemoryCap> $peaks by position
     */
    public static function of(array $ran, array $peaks, Warnings $warnings): self
    {
        return new self($ran, $peaks, $warnings);
    }

    /** No run ended in a child, and nothing to warn of. */
    public static function none(): self
    {
        return new self([], [], Warnings::none());
    }

    /** @return array<int, Ran> each run a child ended, by its position */
    public function all(): array
    {
        return $this->ran;
    }

    /** The most memory the child of the run at a position held; none where its end says none. */
    public function peakAt(int $at): MemoryCap|NotGiven
    {
        return array_key_exists($at, $this->peaks) ? $this->peaks[$at] : NotGiven::value();
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }
}
