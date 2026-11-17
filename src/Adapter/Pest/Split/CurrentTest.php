<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function hrtime;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * The test a split stream's process runs, as far as it went: when it began,
 * whether it failed, raised an issue or was handed to a process forked for
 * it, whether the stream may still fork in it, and the sites it forked at;
 * none between tests, where the stream forks nothing.
 *
 * @internal
 */
final class CurrentTest
{
    /** When it began, on `hrtime`'s clock. */
    private readonly float $started;

    private bool $failed = false;

    /** Whether it raised an issue: it was risky, or triggered a deprecation, a notice or a warning. */
    private bool $troubled = false;

    /** Whether the stream handed it to a process forked for it, and skips it itself. */
    private bool $handed = false;

    /** @var array<string, true> the sites forked in it, by file key and switch id */
    private array $forked = [];

    public function __construct(
        public readonly string $id,
        private bool $forkable,
        private readonly bool $heldAtBegin = false,
    ) {
        $this->started = hrtime(as_number: true);
    }

    /** No test: the stream is between tests. */
    public static function none(): self
    {
        return new self('', forkable: false);
    }

    public function isRunning(): bool
    {
        return $this->id !== '';
    }

    /** It failed or errored, in this process. */
    public function fail(): void
    {
        $this->failed = true;
    }

    public function passed(): bool
    {
        return ! $this->failed;
    }

    /** It raised an issue. */
    public function trouble(): void
    {
        $this->troubled = true;
    }

    public function isTroubled(): bool
    {
        return $this->troubled;
    }

    /** The stream handed it to a process forked for it. */
    public function hand(): void
    {
        $this->handed = true;
    }

    public function wasHanded(): bool
    {
        return $this->handed;
    }

    /** Whether the stream may still fork in it. */
    public function isForkable(): bool
    {
        return $this->forkable;
    }

    /** Whether it began holding a descriptor a child would share, which it may hold at every site. */
    public function beganHolding(): bool
    {
        return $this->heldAtBegin;
    }

    /** It runs where no fork reaches it: the stream forks nothing more in it. */
    public function isolate(): void
    {
        $this->forkable = false;
    }

    /** The stream forked at this site in it. */
    public function forkAt(string $file, int $id): void
    {
        $this->forked[$this->site($file, $id)] = true;
    }

    public function hasForkedAt(string $file, int $id): bool
    {
        return array_key_exists($this->site($file, $id), $this->forked);
    }

    /** The seconds since it began. */
    public function seconds(): float
    {
        return (hrtime(as_number: true) - $this->started) / Seconds::NANOSECONDS;
    }

    private function site(string $file, int $id): string
    {
        return sprintf('%s:%d', $file, $id);
    }
}
