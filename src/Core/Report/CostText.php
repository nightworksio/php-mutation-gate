<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function implode;

use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\Rate;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * What a run cost, as the collapsed section at the end of the PR comment
 * shows it: the planned and measured wall and runner time, what reach and
 * proofs spared, whether setup was estimated, and money where the team gives
 * a rate (ADR-0016, decisions 6 to 8).
 */
final readonly class CostText
{
    private const string SUMMARY = '<details><summary>What this run cost</summary>';

    private const string SPARED = 'Reach and proofs spared %s of runner time.';

    private const string SETUP = 'Setup is estimated at %s a job, from `shards.setup`.';

    private const string MONEY = 'At %s a runner minute: planned %s, measured %s, spared %s.';

    /** The section of a verdict whose run was costed; nothing for one the flows gave no timings. */
    public static function of(Verdict $verdict): string
    {
        $cost = $verdict->account()->cost();

        return $cost instanceof Cost ? self::markdown($cost) : '';
    }

    public static function markdown(Cost $cost): string
    {
        $price = $cost->price();

        return implode("\n\n", [
            self::SUMMARY,
            implode("\n", [
                '| | Wall | Runner time |',
                '|---|---|---|',
                self::row('Planned', $cost->planned()),
                self::row('Measured', $cost->measured()),
            ]),
            implode("\n", [
                sprintf(self::SPARED, $cost->spared()->text()),
                ...$cost->isSetupEstimated() ? [sprintf(self::SETUP, $cost->setup()->text())] : [],
                ...$price instanceof Rate ? [self::money($cost, $price)] : [],
            ]),
            '</details>',
        ]);
    }

    private static function row(string $name, RunTime $time): string
    {
        return sprintf('| %s | %s | %s |', $name, $time->wall()->text(), $time->runner()->text());
    }

    private static function money(Cost $cost, Rate $price): string
    {
        return sprintf(
            self::MONEY,
            $price->perRunnerMinute()->text(),
            $price->of($cost->planned()->runner())->text(),
            $price->of($cost->measured()->runner())->text(),
            $price->of($cost->spared())->text(),
        );
    }
}
