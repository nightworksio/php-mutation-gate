<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use function in_array;

use NightWorksIO\MutationGate\Adapter\Serve\Forking;

/**
 * A daemon's forking that runs each child's work in the test's own process,
 * as the child it stands for, and says the child has ended once it ran; or,
 * holding, runs none, and says a child has ended once it was stopped.
 */
final class InlineForking implements Forking
{
    /** @var list<int> each child's exit code, in the order they ran */
    public private(set) array $codes = [];

    /** @var list<int> the children it was told to stop */
    public private(set) array $stopped = [];

    public private(set) bool $detached = false;

    private int $next = 1000;

    public function __construct(private readonly bool $forks = true, private readonly bool $runs = true)
    {
    }

    public static function failing(): self
    {
        return new self(forks: false);
    }

    /** Forking whose children never run their work, and run on until they are stopped. */
    public static function holding(): self
    {
        return new self(runs: false);
    }

    public function forked(Closure $child): int
    {
        if (! $this->forks) {
            return -1;
        }

        if ($this->runs) {
            $this->codes[] = $child();
        }

        return $this->next++;
    }

    public function hasEnded(int $child): bool
    {
        return $this->runs || in_array($child, $this->stopped, strict: true);
    }

    public function stopped(int $child): void
    {
        $this->stopped[] = $child;
    }

    public function detached(): void
    {
        $this->detached = true;
    }
}
