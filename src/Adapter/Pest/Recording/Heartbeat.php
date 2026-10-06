<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Closure;

use function defined;
use function fwrite;

use NightWorksIO\MutationGate\Adapter\Pest\Silence;

/**
 * What a mutant's own process tells Pest's parent of its progress, on its
 * error output, which the parent's Silence reads: a beat as its tests begin
 * and as each finishes.
 */
final readonly class Heartbeat
{
    /** @param Closure(string): void $write */
    private function __construct(private Closure $write)
    {
    }

    /** Beats on the process's error output, where it has one. */
    public static function onErrorOutput(): self
    {
        return new self(static function (string $beat): void {
            if (defined('STDERR')) {
                fwrite(STDERR, $beat);
            }
        });
    }

    /**
     * Beats through this.
     *
     * @param Closure(string): void $write
     */
    public static function through(Closure $write): self
    {
        return new self($write);
    }

    public function beat(): void
    {
        ($this->write)(Silence::BEAT);
    }
}
