<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function function_exists;
use function getcwd;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Bytes;

use function outbreak_state_digest;
use function outbreak_state_watch;
use function outbreak_touched;
use function outbreak_touches_start;

/**
 * The state each fork of a split stream left (ADR-0130, H5): where
 * `outbreak.so` is loaded, each fork arms a watch on the call that reached
 * the site, the nearest outside the gate, in the parent and the child alike,
 * and each writes the digest of the state that call left as it returned, so
 * the run counts the forks that changed nothing; and each child watches
 * what its test touches after the fork, so a kill by a test that touched
 * the world outside the process is judged again by the mutant's own run.
 *
 * @internal
 */
final class ForkStates
{
    /** This class's name under the gate's root namespace, which a watch skips the frames of. */
    private const string OWN = 'Adapter\\Pest\\Split\\ForkStates';

    /** The watch on the forked call's state, in a child; none in the parent. */
    private int $watch = 0;

    /** @var array<string, int> the watch on each forked call's state, by mutant, in the parent's current test */
    private array $watching = [];

    public function __construct(private readonly StreamPlan $plan)
    {
    }

    /** A test begins in the parent, no call of it watched yet. */
    public function began(): void
    {
        $this->watching = [];
    }

    /** A watch on the call that reached the site about to fork, where `outbreak.so` is loaded; none otherwise. */
    public function armed(): int
    {
        $gate = Bytes::slice(self::class, 0, -Bytes::length(self::OWN));

        return function_exists('outbreak_state_watch') ? (int) outbreak_state_watch($gate) : 0;
    }

    /** In the parent, the watch armed as a child of this mutant forked. */
    public function forked(string $mutant, int $watch): void
    {
        if ($watch > 0) {
            $this->watching[$mutant] = $watch;
        }
    }

    /** In a child, the watch armed as it forked, and what its test touches watched from here. */
    public function forkedInto(int $watch): void
    {
        $this->watch = $watch;

        if (function_exists('outbreak_touches_start')) {
            outbreak_touches_start((string) getcwd());
        }
    }

    /**
     * Whether a child's test touched the clock, randomness, the environment,
     * another process, the network or a file outside the project since it forked.
     */
    public function touched(): bool
    {
        return function_exists('outbreak_touched') && outbreak_touched();
    }

    /** A child's test ended: the state its watched call left. */
    public function childEnded(string $mutant, string $test): void
    {
        $this->stated($mutant, $test, $this->watch, child: true);
    }

    /** The parent's test ended: the state each call it watched left. */
    public function parentEnded(string $test): void
    {
        foreach ($this->watching as $mutant => $watch) {
            $this->stated($mutant, $test, $watch, child: false);
        }
    }

    /** Writes the digest of the state a watched call left, where it returned and its state could be read. */
    private function stated(string $mutant, string $test, int $watch, bool $child): void
    {
        $digest = $watch > 0 && function_exists('outbreak_state_digest') ? outbreak_state_digest($watch) : null;

        if (is_string($digest)) {
            $this->plan->recorded(StreamLine::state($mutant, $test, $child, $digest));
        }
    }
}
