<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function str_ends_with;
use function str_starts_with;

/**
 * Why a mutant stands as it does, in the sentence a report prints beside it:
 * why a runner left it unjudged, and the time budget that ran out before it,
 * where one did (ADR-0008), or why the config ignores it.
 */
final readonly class Reason
{
    /**
     * Why a mutant of a line that is not executable is unjudged where no test
     * reads its value (ADR-0004), which a test that references the value
     * judges (ADR-0015).
     */
    public const string UNREACHED = 'no test reaches this value';

    /**
     * Why a mutant is unjudged where its own run had loaded the file it
     * mutates before Pest's override could put the mutant in its place
     * (ADR-0004), as the ledger keeps it after the file's name. It holds on
     * every run, and an ignore by the mutant's id can leave it out
     * (ADR-0008).
     */
    private const string PRELOADED = ' was loaded before the mutant was in place, so its tests ran the original code';

    /**
     * Why a mutant timed out before its limit: no test of its run finished
     * for its silence limit, the standard limit of its slowest test's own
     * time (ADR-0008).
     */
    private const string SILENT = 'No test finished for %s, its silence limit, so its run was stopped.';

    /**
     * Why a mutant timed out before its limit: one test of its run ran past
     * the limit of its own, a multiple of its own time (ADR-0090).
     */
    private const string OVERRAN = 'Its test %s ran past %s, its own limit, so its run was stopped.';

    /**
     * What a local run that left a mutant unjudged says of it where a mutant
     * that subsumes it was killed (ADR-0079): its kill is inferred, which is
     * no proof, before why it was left.
     */
    private const string INFERRED = 'Inferred killed: %s subsumes it and was killed, which proves nothing of it. %s';

    /**
     * Why a heavy mutant is unjudged where `timeouts.heavyParts` stopped it
     * before its last part (ADR-0114), as the ledger keeps it. No test that
     * did not run proves anything of it, so it fails the verdict.
     */
    private const string CAPPED = 'timeouts.heavyParts stopped it at part %d of %d of its tests, no part settling it.';

    private const string CAPPED_START = 'timeouts.heavyParts stopped it';

    private function __construct(
        private string $text,
        private OutOfTime|Unreported $outOfTime,
        private TestId|NotGiven $stoppedAt = new NotGiven(),
    ) {
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

    /** Why a heavy mutant is unjudged where `timeouts.heavyParts` stopped it at this part of these. */
    public static function atThePartCap(int $part, int $parts): self
    {
        return self::that(sprintf(self::CAPPED, $part, $parts));
    }

    /** Why a mutant's run was stopped where no test of it finished for this long, its silence limit. */
    public static function silent(Seconds $for): self
    {
        return self::that(sprintf(self::SILENT, $for->text()));
    }

    /** Why a mutant's run was stopped where this test of it ran past this limit of its own. */
    public static function overran(TestId $test, Seconds $limit): self
    {
        return new self(sprintf(self::OVERRAN, $test->value(), $limit->text()), Unreported::reason(), $test);
    }

    /** This reason, saying first that the mutant's kill is inferred from that of this mutant, which subsumes it. */
    public function inferredFrom(MutantId $subsumer): self
    {
        return new self(sprintf(self::INFERRED, $subsumer->value(), $this->text), $this->outOfTime, $this->stoppedAt);
    }

    /** Whether it says the mutated file was loaded before the mutant was in place, as the ledger keeps it. */
    public function isPreloaded(): bool
    {
        return str_ends_with($this->text, self::PRELOADED);
    }

    /** Whether it says `timeouts.heavyParts` stopped the mutant, as the ledger keeps it, in its words. */
    public function isAtThePartCap(): bool
    {
        return str_starts_with($this->text, self::CAPPED_START);
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

    /** The test that ran past its own limit, so its run was stopped; none where no test's own limit stopped it. */
    public function stoppedAt(): TestId|NotGiven
    {
        return $this->stoppedAt;
    }

    /** What a time budget ran out before, where one left the mutant unjudged. */
    public function outOfTime(): OutOfTime|Unreported
    {
        return $this->outOfTime;
    }
}
