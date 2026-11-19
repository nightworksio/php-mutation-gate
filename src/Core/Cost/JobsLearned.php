<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function count;
use function intdiv;
use function is_float;
use function max;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sort;

/**
 * What a plan learned of the recent jobs on its platform for every job it
 * plans (ADR-0196): the longest setup before a job's gate started, the
 * longest a gate went on for once it stopped, and the median factor a
 * second pass took of the seconds of its mutants' covering tests; each
 * none where no job measured it.
 */
final readonly class JobsLearned
{
    private function __construct(
        private Seconds|NotGiven $setup,
        private Seconds|NotGiven $windDown,
        private float|NotGiven $passTwo,
    ) {
    }

    /** The longest setup a recent job took before its gate started; none where none was measured. */
    public function setup(): Seconds|NotGiven
    {
        return $this->setup;
    }

    /** Nothing learned of any job. */
    public static function none(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), NotGiven::value());
    }

    /** What was learned: the longest setup and wind-down, and the median second pass's factor, each where it was. */
    public static function learned(
        Seconds|NotGiven $setup,
        Seconds|NotGiven $windDown,
        float|NotGiven $passTwo = new NotGiven(),
    ): self {
        return new self($setup, $windDown, $passTwo);
    }

    /**
     * What these jobs taught of every job on their platform: the longest
     * setup and the longest wind-down, and the median second pass's factor;
     * each none where none taught it.
     */
    public static function of(JobTimes ...$jobs): self
    {
        $setups = [];
        $windDowns = [];
        $factors = [];

        foreach ($jobs as $job) {
            $setups = [...$setups, ...self::secondsIn($job->setup())];
            $windDowns = [...$windDowns, ...self::secondsIn($job->windDown())];
            $factors = [...$factors, ...is_float($job->passTwo()) ? [$job->passTwo()] : []];
        }

        return new self(self::longest($setups), self::longest($windDowns), self::median($factors));
    }

    /** The longest a recent job's gate went on for once it stopped; none where none was measured. */
    public function windDown(): Seconds|NotGiven
    {
        return $this->windDown;
    }

    /** The median factor of their covering tests' seconds recent second passes took; none where none was measured. */
    public function passTwo(): float|NotGiven
    {
        return $this->passTwo;
    }

    /** Whether any job taught its setup or its wind-down, which a job's stop is read from. */
    public function teachesTheStop(): bool
    {
        return $this->setup instanceof Seconds || $this->windDown instanceof Seconds;
    }

    /** This setup, where one was learned; else the one given. */
    public function setupOr(Seconds $otherwise): Seconds
    {
        return $this->setup instanceof Seconds ? $this->setup : $otherwise;
    }

    /** This wind-down, where one was learned; else the one given. */
    public function windDownOr(Seconds $otherwise): Seconds
    {
        return $this->windDown instanceof Seconds ? $this->windDown : $otherwise;
    }

    /** @return list<float> */
    private static function secondsIn(Seconds|NotGiven $seconds): array
    {
        return $seconds instanceof Seconds ? [$seconds->seconds()] : [];
    }

    /** @param list<float> $seconds */
    private static function longest(array $seconds): Seconds|NotGiven
    {
        return $seconds === [] ? NotGiven::value() : Seconds::of(max($seconds));
    }

    /** @param list<float> $factors */
    private static function median(array $factors): float|NotGiven
    {
        if ($factors === []) {
            return NotGiven::value();
        }

        sort($factors);
        $middle = intdiv(count($factors), 2);

        return count($factors) % 2 === 1 ? $factors[$middle] : ($factors[$middle - 1] + $factors[$middle]) / 2;
    }

}
