<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScore;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScores;
use NightWorksIO\MutationGate\Core\Score\Score;

use function sprintf;

/**
 * What every report says of the suites (ADR-0025, decisions 8 and 10): each
 * suite's score, *at least* where it is a lower bound, and why it is one;
 * or that the runner named no test, so none can be put in a suite. The
 * suites are shown where the PHPUnit config declares two or more: one
 * suite's score is the run's own.
 */
final readonly class SuiteText
{
    /** The fewest suites that are shown: one suite's score is the run's own. */
    private const int SHOWN_FROM = 2;

    private const string EXACT = '%s alone kills %s of the %d mutants its tests cover.';

    private const string AT_LEAST = '%s alone kills at least %s of the %d mutants its tests cover.';

    private const string NONE = '%s covers no mutant.';

    private const string UNPLACED = 'The runner named no test, so no suite can be scored.';

    private const string FIRST_KILLERS
        = 'Each is a lower bound, from first killers: `mutation-gate run --kill-matrix=full` makes it exact.';

    private const string INFECTION = 'Each is a lower bound: Infection stops each mutant at its first failing test.';

    /** Whether the suites are shown at all: where the config declares two or more. */
    public static function shows(SuiteScores $scores): bool
    {
        return count($scores) >= self::SHOWN_FROM;
    }

    /**
     * What a suite alone kills, at least, or that its tests cover no mutant;
     * its name, which the project's PHPUnit config writes, one plain line,
     * with no control character that could colour a terminal or break it.
     */
    public static function of(SuiteScore $score): string
    {
        $value = $score->score();
        $suite = Fit::plain($score->suite());

        return match (true) {
            ! $value instanceof Score => sprintf(self::NONE, $suite),
            $score->isExact() => sprintf(self::EXACT, $suite, Percent::of($value), $score->covered()),
            default => sprintf(self::AT_LEAST, $suite, Percent::of($value), $score->covered()),
        };
    }

    /** Why no suite is scored: the runner named no test, so none can be put in a suite by its file. */
    public static function unplaced(): string
    {
        return self::UNPLACED;
    }

    /** Why each score is a lower bound: the run recorded first killers, or its runner cannot record more. */
    public static function lowerBound(NotFull $why): string
    {
        return match ($why) {
            NotFull::FirstKillers => self::FIRST_KILLERS,
            NotFull::Infection => self::INFECTION,
        };
    }

    /**
     * The lines a text report shows: each suite's sentence, then why they
     * are lower bounds where they are; that none can be scored; or nothing,
     * where fewer than two suites are declared.
     *
     * @return list<string>
     */
    public static function lines(SuiteScores $scores, NotFull $why): array
    {
        if (! self::shows($scores)) {
            return [];
        }

        if (! $scores->arePlaced()) {
            return [self::UNPLACED];
        }

        $lines = [];
        $exact = true;

        foreach ($scores as $score) {
            $lines[] = self::of($score);
            $exact = $exact && $score->isExact();
        }

        return $exact ? $lines : [...$lines, self::lowerBound($why)];
    }
}
