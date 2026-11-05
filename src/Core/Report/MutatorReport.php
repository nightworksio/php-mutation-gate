<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function array_slice;
use function implode;
use function max;
use function mb_str_pad;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\Cost\MutatorTally;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

use const STR_PAD_LEFT;
use const STR_PAD_RIGHT;

/**
 * What each mutator costs and finds, from a ledger's tally (ADR-0076): one
 * row for each mutator of each runner, the costliest first, with its
 * mutants, kill rate, survivors, runner seconds, seconds per kill and share
 * of every mutator's time; as text for a person, or as `"format": 1` JSON,
 * which `resources/mutators.schema.json` describes.
 *
 * @internal
 *
 * @phpstan-type Row array{
 *     runner: string,
 *     mutator: string,
 *     mutants: int,
 *     killed: int,
 *     survived: int,
 *     ignored: int,
 *     seconds: float,
 *     killRate?: float,
 *     secondsPerKill?: float,
 *     share?: float,
 * }
 * @phpstan-type Costly array{runner: string, mutator: string, seconds: float, survived: int, killRate?: float}
 */
final readonly class MutatorReport
{
    /** The version of this format, which changes only when a reader would misread the new one. */
    public const int FORMAT = 1;

    /** How many of the costliest mutators the summary a dashboard reads holds. */
    public const int COSTLIEST = 10;

    public const string NONE
        = 'The ledger tallies no mutator yet: each run that writes it, judging every mutant, tallies its mutators.';

    /** What a figure there is none of shows as in the text. */
    private const string NO_FIGURE = '-';

    private const string GAP = '  ';

    /** How many columns, the first ones, hold names rather than figures. */
    private const int NAMES = 2;

    private const string SECONDS = '%.1fs';

    private const array HEADINGS = [
        'Runner',
        'Mutator',
        'Mutants',
        'Kill rate',
        'Survivors',
        'Time',
        'Per kill',
        'Share',
    ];

    /** The report as a person reads it: a table, the costliest mutator first; a sentence where there is none. */
    public static function text(MutatorTallies $tallies): string
    {
        $rows = array_map(
            static fn(MutatorTally $tally): array => [
                $tally->runner(),
                $tally->mutator(),
                sprintf('%d', $tally->mutants()),
                self::percent($tally->killRate()),
                sprintf('%d', $tally->survived()),
                sprintf(self::SECONDS, $tally->seconds()->seconds()),
                self::seconds($tally->secondsPerKill()),
                self::percent($tallies->shareOf($tally)),
            ],
            [...$tallies->costliest()],
        );

        return $rows === [] ? sprintf("%s\n", self::NONE) : self::table([self::HEADINGS, ...$rows]);
    }

    /** The report as its public JSON. */
    public static function json(MutatorTallies $tallies): string
    {
        return JsonText::encode([
            'format' => self::FORMAT,
            'seconds' => $tallies->seconds()->seconds(),
            'mutators' => array_map(
                static fn(MutatorTally $tally): array => self::row($tally, $tallies),
                [...$tallies->costliest()],
            ),
        ]);
    }

    /**
     * The costliest mutators, as the summary a dashboard reads holds them.
     *
     * @return list<Costly>
     */
    public static function costliest(MutatorTallies $tallies): array
    {
        $costly = [];

        foreach (array_slice([...$tallies->costliest()], 0, self::COSTLIEST) as $tally) {
            $costly[] = self::costly($tally);
        }

        return $costly;
    }

    /** @return Row */
    private static function row(MutatorTally $tally, MutatorTallies $tallies): array
    {
        $row = [
            'runner' => $tally->runner(),
            'mutator' => $tally->mutator(),
            'mutants' => $tally->mutants(),
            'killed' => $tally->killed(),
            'survived' => $tally->survived(),
            'ignored' => $tally->ignored(),
            'seconds' => $tally->seconds()->seconds(),
        ];
        $killRate = $tally->killRate();
        $perKill = $tally->secondsPerKill();
        $share = $tallies->shareOf($tally);

        if ($killRate instanceof Percentage) {
            $row['killRate'] = $killRate->percent();
        }

        if ($perKill instanceof Seconds) {
            $row['secondsPerKill'] = $perKill->seconds();
        }

        if ($share instanceof Percentage) {
            $row['share'] = $share->percent();
        }

        return $row;
    }

    /** @return Costly */
    private static function costly(MutatorTally $tally): array
    {
        $costly = [
            'runner' => $tally->runner(),
            'mutator' => $tally->mutator(),
            'seconds' => $tally->seconds()->seconds(),
            'survived' => $tally->survived(),
        ];
        $killRate = $tally->killRate();

        if ($killRate instanceof Percentage) {
            $costly['killRate'] = $killRate->percent();
        }

        return $costly;
    }

    private static function percent(Percentage|NotGiven $percent): string
    {
        return $percent instanceof Percentage ? sprintf('%s%%', $percent->written()) : self::NO_FIGURE;
    }

    private static function seconds(Seconds|NotGiven $seconds): string
    {
        return $seconds instanceof Seconds ? sprintf(self::SECONDS, $seconds->seconds()) : self::NO_FIGURE;
    }

    /**
     * Rows as columns, each as wide as its widest cell: the names flush left, the figures flush right.
     *
     * @param list<list<string>> $rows
     */
    private static function table(array $rows): string
    {
        $widths = self::widths($rows);
        $lines = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($row as $column => $cell) {
                $side = $column < self::NAMES ? STR_PAD_RIGHT : STR_PAD_LEFT;
                $cells[] = mb_str_pad($cell, $widths[$column], pad_type: $side);
            }

            $lines[] = implode(self::GAP, $cells);
        }

        return sprintf("%s\n", implode("\n", $lines));
    }

    /**
     * @param  list<list<string>> $rows
     * @return array<int, int>    the width of each column's widest cell
     */
    private static function widths(array $rows): array
    {
        $lengths = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $lengths[$column][] = mb_strlen($cell);
            }
        }

        return array_map(static fn(array $column): int => max($column), $lengths);
    }
}
