<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Reason;

use function sprintf;

/**
 * Which test files judge a mutant of a line that is not executable: those
 * whose reads the scan found, run first, and the fallback of every test file
 * that covers the mutant's file, run after them where it is small. A kill by
 * either stands. Where the scan found no read, an ambiguous one runs the
 * fallback alone. An ambiguous read may have readers the scan did not follow,
 * so a mutant it leaves alive is a survivor only once the fallback ran: past
 * the bound it is unjudged.
 */
final readonly class Choice
{
    /** The most test files a fallback may hold. */
    private const int BOUND = 10;

    private const string AMBIGUOUS = 'ambiguous reference; %s is covered by %d test files';

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
        return match (true) {
            $this->reading->count() > 0 => $this->reading,
            $this->ambiguous && $this->bounded() && $this->covering->count() > 0 => $this->covering,
            $this->ambiguous => $this->unfollowed($file),
            default => Outcome::unjudged(Reason::UNREACHED),
        };
    }

    /**
     * The test files to run where the first leave the mutant alive: the
     * fallback's others, where it is small; or why it is no survivor, where
     * an ambiguous read's fallback is too large to run.
     */
    public function then(Path $file): Paths|Outcome
    {
        $others = Paths::none();

        if ($this->reading->count() === 0 || ! $this->bounded()) {
            return $this->reading->count() > 0 && $this->ambiguous ? $this->unfollowed($file) : $others;
        }

        foreach ($this->covering as $test) {
            $others = $this->reading->has($test) ? $others : $others->with($test);
        }

        return $others;
    }

    private function bounded(): bool
    {
        return count($this->covering) <= self::BOUND;
    }

    /** Why an ambiguous read of a value in this file judges nothing. */
    private function unfollowed(Path $file): Outcome
    {
        return Outcome::unjudged(sprintf(self::AMBIGUOUS, $file->value(), count($this->covering)));
    }
}
