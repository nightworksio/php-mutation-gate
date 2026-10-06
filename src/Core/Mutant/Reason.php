<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function str_ends_with;

/**
 * Why a mutant stands as it does, in the sentence a report prints beside it:
 * why a runner left it unjudged, and the time budget that ran out before it,
 * where one did (ADR-0008, decision 1), or why the config ignores it
 * (decision 4).
 */
final readonly class Reason
{
    /**
     * Why a mutant of a line that is not executable is unjudged where no test
     * reads its value (ADR-0004), which a test that references the value
     * judges (ADR-0015, decision 1).
     */
    public const string UNREACHED = 'no test reaches this value';

    /**
     * Why a mutant is unjudged where its own run had loaded the file it
     * mutates before Pest's override could put the mutant in its place
     * (ADR-0004), as the ledger keeps it after the file's name. It holds on
     * every run, and an ignore by the mutant's id can leave it out (ADR-0008,
     * decision 4).
     */
    private const string PRELOADED = ' was loaded before the mutant was in place, so its tests ran the original code';

    /**
     * Why a mutant timed out before its limit: no test of its run finished
     * for its silence limit, the standard limit of its slowest test's own
     * time (ADR-0008, decision 2).
     */
    private const string SILENT = 'No test finished for %s, its silence limit, so its run was stopped.';

    private function __construct(private string $text, private OutOfTime|Unreported $outOfTime)
    {
    }

    public static function that(string $text): self
    {
        return new self($text, Unreported::reason());
    }

    /** The reason a time budget that ran out before this left a mutant unjudged, in these words. */
    public static function ranOutOf(OutOfTime $before, string $text): self
    {
        return new self($text, $before);
    }

    /** Why a mutant of this file is unjudged where its own run loaded the file before the mutant was in place. */
    public static function preloaded(Path $file): self
    {
        return self::that(sprintf('%s%s', $file->value(), self::PRELOADED));
    }

    /** Why a mutant's run was stopped where no test of it finished for this long, its silence limit. */
    public static function silent(Seconds $for): self
    {
        return self::that(sprintf(self::SILENT, $for->text()));
    }

    /** Whether it says the mutated file was loaded before the mutant was in place, as the ledger keeps it. */
    public function isPreloaded(): bool
    {
        return str_ends_with($this->text, self::PRELOADED);
    }

    /** Whether it says no test reaches the mutant's value, as the ledger keeps it, in its words. */
    public function isUnreached(): bool
    {
        return $this->text === self::UNREACHED;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** What a time budget ran out before, where one left the mutant unjudged. */
    public function outOfTime(): OutOfTime|Unreported
    {
        return $this->outOfTime;
    }
}
