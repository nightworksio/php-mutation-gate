<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;

/**
 * Which test files judge a mutant of a line that is not executable: those
 * whose reads the scan found, and the fallback of every test file that covers
 * the mutant's file, used where it is small. An ambiguous read runs the
 * fallback beside the reads; an unambiguous survivor runs it after them.
 */
final readonly class Choice
{
    /** The most test files a fallback may hold. */
    private const int BOUND = 10;

    private const string AMBIGUOUS = 'ambiguous reference; %s is covered by %d test files';

    private const string UNREAD = 'no test reaches this value';

    private function __construct(private Paths $reading, private Paths $covering, private bool $ambiguous)
    {
    }

    /**
     * @param Paths $reading  the test files that read the value, or run a line that does
     * @param Paths $covering the test files that cover the mutant's file
     */
    public static function of(Paths $reading, Paths $covering, bool $ambiguous): self
    {
        return new self($reading, $covering, $ambiguous);
    }

    /** The test files to run first, or why none can judge the mutant of this file. */
    public function first(Path $file): Paths|Outcome
    {
        $bounded = count($this->covering) <= self::BOUND;

        return match (true) {
            $this->ambiguous && ! $bounded => Outcome::unjudged(
                sprintf(self::AMBIGUOUS, $file->value(), count($this->covering)),
            ),
            $this->ambiguous => $this->joined(),
            count($this->reading) === 0 => Outcome::unjudged(self::UNREAD),
            default => $this->reading,
        };
    }

    /** The test files to run where the first leave the mutant alive: the fallback's others, where it is small. */
    public function then(): Paths
    {
        $others = Paths::none();

        foreach (count($this->covering) <= self::BOUND && ! $this->ambiguous ? $this->covering : Paths::none() as $file) {
            $others = $this->reading->has($file) ? $others : $others->with($file);
        }

        return $others;
    }

    private function joined(): Paths
    {
        $joined = $this->reading;

        foreach ($this->covering as $file) {
            $joined = $joined->with($file);
        }

        return $joined;
    }
}
