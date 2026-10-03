<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;

use function sprintf;

/**
 * What `explain` prints (ADR-0014, decision 12): each mutant's heading, its
 * diff, why it stands as it does and what its tests miss; each covering test
 * with its outcome and time, and the judging tests where they differ; a
 * limit it ran out of; how the last run took its unit and why; its history;
 * and last the command that runs it again. A cluster is headed by its own
 * heading, hint and stub command, then each member.
 */
final readonly class ExplanationText
{
    private const string UNCOVERED = 'Covered by: no test';

    private const string COVERED = 'Covered by:';

    private const string LIMIT = 'Limit: %s';

    private const string ALONE = '%s; its judging tests take %s on their own';

    private const string RUN = 'Unit: %s, run by the last run.';

    private const string REACHED = 'Unit: %s, run by the last run. Reach:';

    private const string PROVED = 'Unit: %s, proved by the last run from a proof whose key still matches.';

    private const string CARRIED = 'Unit: %s, carried by the last run, whose change does not reach it.';

    private const string FROM = '%s Its result is from run %s.';

    private const string UNJUDGED = 'Unit: unknown. %s';

    private const string HISTORY = 'History:';

    private const string NO_HISTORY = 'History: no ledger read holds it';

    public static function of(Explanations $explained): string
    {
        $cluster = $explained->cluster();
        $blocks = $cluster instanceof Cluster ? [self::cluster($cluster)] : [];

        foreach ($explained as $explanation) {
            $blocks[] = self::one($explanation);
        }

        return implode("\n\n", $blocks);
    }

    private static function cluster(Cluster $cluster): string
    {
        return MutantText::indented(
            ClusterText::heading($cluster),
            ClusterText::hint($cluster),
            sprintf('Stub: %s', $cluster->stub()),
        );
    }

    private static function one(Explanation $explanation): string
    {
        $judged = $explanation->mutant();

        return MutantText::indented(MutantText::heading($judged), ...[
            ...MutantText::diff($judged->mutant()),
            ...MutantText::stands($judged),
            ...MutantText::missed($judged),
            ...self::covering($explanation),
            ...self::judging($explanation),
            ...self::limited($judged),
            ...self::unit($explanation),
            ...self::history($explanation),
            sprintf('Reproduce: %s', $judged->reproduce()),
        ]);
    }

    /**
     * Each test that covers it, with what it did with the mutant in place
     * and how long it runs on its own (ADR-0014, decision 10).
     *
     * @return list<string>
     */
    private static function covering(Explanation $explanation): array
    {
        $matrix = $explanation->matrix();
        $judged = $explanation->mutant();
        $lines = [];

        foreach ($matrix->coveredBy($judged) as $test) {
            $seconds = $matrix->secondsOf($test);
            $lines[] = sprintf(
                '%s%s  %s%s',
                MutantText::INDENT,
                Fit::plain($matrix->names()->nameOf($test)->value()),
                $matrix->outcome($judged, $test)->value,
                $seconds instanceof Seconds ? sprintf('  %s', $seconds->preciseText()) : '',
            );
        }

        return $lines === [] ? [self::UNCOVERED] : [self::COVERED, ...$lines];
    }

    /**
     * The tests that judge it, where they are not the tests that cover it:
     * those the verdict names, or the group or filter that holds its unit
     * (ADR-0004, decision 8).
     *
     * @return list<string>
     */
    private static function judging(Explanation $explanation): array
    {
        $judged = $explanation->mutant();
        $tests = $judged->tests();
        $unit = $explanation->unit();
        $by = $unit instanceof JudgedUnit ? $unit->unit()->judgedBy() : WholeSuite::tests();

        $named = count($tests) > 0;

        return match (true) {
            $named && ! self::same($tests, $explanation->matrix()->coveredBy($judged))
                => [sprintf(MutantText::JUDGED_BY, $explanation->matrix()->names()->listed($tests))],
            $named, $by instanceof WholeSuite => [],
            default => [sprintf(MutantText::JUDGED_BY, MutantText::judging($by))],
        };
    }

    /**
     * The time or memory a mutant that ran out of either was allowed, and
     * what its judging tests need without it; the hint says how it was
     * triaged (ADR-0008).
     *
     * @return list<string>
     */
    private static function limited(JudgedMutant|JudgedKill $judged): array
    {
        $mutant = $judged->mutant();
        $limit = $mutant->limit();
        $need = $judged instanceof JudgedMutant ? $judged->mutant()->unmutatedNeed() : Unmeasured::duration();
        $ranOut = $mutant->status()->ranOutOfTime() || $mutant->status() === MutantStatus::OutOfMemory;

        return match (true) {
            ! $ranOut, $limit instanceof Unmeasured => [],
            $need instanceof Unmeasured => [sprintf(self::LIMIT, self::amount($limit))],
            default => [sprintf(self::LIMIT, sprintf(self::ALONE, self::amount($limit), self::amount($need)))],
        };
    }

    /**
     * How the last run took the unit, and the reach that made it run.
     *
     * @return list<string>
     */
    private static function unit(Explanation $explanation): array
    {
        $unit = $explanation->unit();

        if ($unit instanceof CannotTell) {
            return [sprintf(self::UNJUDGED, $unit->why())];
        }

        $reasons = [];

        foreach ($explanation->reach() as $reason) {
            $reasons[] = sprintf('%s%s', MutantText::INDENT, $reason->text());
        }

        return $unit->origin() === Origin::Run && $reasons !== []
            ? [self::taken($unit, self::REACHED), ...$reasons]
            : [self::taken($unit, self::sentence($unit->origin()))];
    }

    private static function sentence(Origin $origin): string
    {
        return match ($origin) {
            Origin::Run => self::RUN,
            Origin::Proved => self::PROVED,
            Origin::Carried => self::CARRIED,
        };
    }

    /** The sentence for how a unit was taken, with the run its result is from where a proof names one. */
    private static function taken(JudgedUnit $unit, string $sentence): string
    {
        $said = sprintf($sentence, $unit->unit()->path()->value());
        $run = $unit->run();

        return $run instanceof Run ? sprintf(self::FROM, $said, $run->id()) : $said;
    }

    /** @return list<string> every record of it, the newest first */
    private static function history(Explanation $explanation): array
    {
        $lines = [];

        foreach ($explanation->history() as $record) {
            $lines[] = sprintf('%s%s', MutantText::INDENT, RecordText::of($record));
        }

        return $lines === [] ? [self::NO_HISTORY] : [self::HISTORY, ...$lines];
    }

    private static function amount(Seconds|MemoryCap $amount): string
    {
        return $amount instanceof Seconds ? $amount->preciseText() : $amount->written();
    }

    private static function same(TestIds $one, TestIds $other): bool
    {
        $same = count($one) === count($other);

        foreach ($one as $test) {
            $same = $same && $other->has($test);
        }

        return $same;
    }
}
