<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use Closure;

use function fwrite;

use NightWorksIO\MutationGate\Core\Runner\TestEvents;

use function rawurlencode;
use function sprintf;

/**
 * What a mutant's own process tells Pest's parent of its progress, on its
 * error output, which the parent's Silence reads: a beat as its tests begin
 * and as each finishes, and each test's id as it starts (ADR-0090), each a
 * line of its own.
 *
 * @internal
 */
final readonly class Heartbeat
{
    /** What ends each thing it tells. */
    private const string END = "\n";

    /** @param Closure(string): void $write */
    private function __construct(private Closure $write)
    {
    }

    /** Beats on the error output of a mutant's own process, which Pest starts on the command line. */
    public static function onErrorOutput(): self
    {
        return new self(static function (string $beat): void {
            fwrite(STDERR, $beat);
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
        ($this->write)(sprintf('%s%s', TestEvents::BEAT, self::END));
    }

    /** Tells that this test, by its id, started. */
    public function started(string $test): void
    {
        ($this->write)(sprintf('%s%s%s', TestEvents::TEST_STARTED, rawurlencode($test), self::END));
    }
}
