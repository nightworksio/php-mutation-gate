<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function is_float;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;
use NightWorksIO\MutationGate\Core\Runner\TestLog;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function str_contains;

/**
 * A mutant's own run as Pest's parent watches it (see Silence): its silence
 * limit, its mutated copy, its last beat, none before its first, and what its
 * beats told of the test it runs, each test held to a limit of its own where
 * a watch holds it to one (ADR-0090).
 *
 * @internal
 */
final class WatchedRun
{
    private function __construct(
        private readonly Seconds|NotGiven $silence,
        private readonly string $mutated,
        private float|NotGiven $beat,
        private TestLog|NotGiven $tests,
    ) {
    }

    /** A run on this mutated copy held to this silence limit, its tests to this watch. */
    public static function of(Seconds|NotGiven $silence, string $mutated, TestWatch $watch): self
    {
        return new self(
            $silence,
            $mutated,
            NotGiven::value(),
            $watch->isOn() ? TestLog::of(TestEvents::Heartbeats, $watch) : NotGiven::value(),
        );
    }

    /** Whether anything could stop it before its whole limit. */
    public function isWatched(): bool
    {
        return $this->silence instanceof Seconds || $this->tests instanceof TestLog;
    }

    /** The mutated copy its run serves. */
    public function mutated(): string
    {
        return $this->mutated;
    }

    /**
     * The limit it has run past, once it wrote this on its error output, at
     * these seconds of the clock: its silence limit, where no test finished
     * for it, or the test it runs and that test's own; none where it is
     * within both.
     */
    public function pastLimit(string $errors, float $now): Seconds|Overrun|NotGiven
    {
        $this->beat = str_contains($errors, TestEvents::BEAT) ? $now : $this->beat;
        $this->tests = $this->tests instanceof TestLog ? $this->tests->after($errors, $now) : $this->tests;
        $silent = $this->silence instanceof Seconds && is_float($this->beat)
            && $now - $this->beat > $this->silence->seconds();

        return match (true) {
            $silent => $this->silence,
            $this->tests instanceof TestLog => $this->tests->pastLimit($now),
            default => NotGiven::value(),
        };
    }
}
