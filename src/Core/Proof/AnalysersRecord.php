<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_keys;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\RejectionRate;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

use stdClass;

/**
 * What a ledger learned of each analyser, as its optional `analysers` section
 * holds it (ADR-0020, decision 11): by the analyser's name, how many checks
 * it made and their `seconds` together, and each mutator's `[checks,
 * rejections]` pair by the mutator's name. The time's checks and the
 * mutators' are counted apart and read apart, and never reconciled: a
 * check after the tests adds to the time alone.
 *
 * Reading keeps each well-formed analyser and pair and drops anything else,
 * never repairing it; a ledger without the section learned nothing. Losing
 * it costs speed, never a verdict, so the ledger's format does not count it.
 *
 * @internal the shape of the `analysers` section of the ledger file
 *
 * @phpstan-type Pairs array<string, array{int, int}>|stdClass
 * @phpstan-type Written array<string, array{checks: int, seconds: float, mutators: Pairs}>
 */
final readonly class AnalysersRecord
{
    public const string SECTION = 'analysers';

    private const string CHECKS = 'checks';

    private const string SECONDS = 'seconds';

    private const string MUTATORS = 'mutators';

    /** @return Written */
    public static function of(AnalyserHistories $histories): array
    {
        $written = [];

        foreach ($histories as $history) {
            $mutators = [];

            foreach ($history as $rate) {
                $mutators[$rate->mutator()] = [$rate->checks(), $rate->rejections()];
            }

            $written[$history->analyser()] = [
                self::CHECKS => $history->time()->checks(),
                self::SECONDS => $history->time()->seconds()->seconds(),
                self::MUTATORS => $mutators === [] ? new stdClass() : $mutators,
            ];
        }

        return $written;
    }

    /** The histories a ledger's `analysers` section holds, none where it holds none. */
    public static function read(Node $section): AnalyserHistories
    {
        $histories = AnalyserHistories::none();

        foreach (self::entriesOf($section) as $analyser => $entry) {
            foreach (self::historyIn(sprintf('%s', $analyser), $entry) as $history) {
                $histories = $histories->with($history);
            }
        }

        return $histories;
    }

    /** @return list<AnalyserHistory> the history an entry holds, or none where its time is malformed */
    private static function historyIn(string $analyser, Node $entry): array
    {
        try {
            $history = AnalyserHistory::of($analyser)->withTime(self::timeIn($entry));
        } catch (NotInShape) {
            return [];
        }

        foreach (self::entriesOf($entry->field(self::MUTATORS)) as $mutator => $pair) {
            foreach (self::rateIn(sprintf('%s', $mutator), $pair) as $rate) {
                $history = $history->withRate($rate);
            }
        }

        return [$history];
    }

    /** @throws NotInShape */
    private static function timeIn(Node $entry): CheckTime
    {
        $checks = $entry->field(self::CHECKS)->integer();
        $seconds = $entry->field(self::SECONDS)->number();

        return $checks >= 0 && $seconds >= 0.0
            ? CheckTime::of($checks, Seconds::of($seconds))
            : throw NotInShape::at($entry->at(), 'a count of checks and their seconds');
    }

    /**
     * @return list<RejectionRate> the rate a pair holds, or none where it is no
     *                             `[checks, rejections]` with no more rejections than checks
     */
    private static function rateIn(string $mutator, Node $pair): array
    {
        try {
            $numbers = $pair->integers();
        } catch (NotInShape) {
            return [];
        }

        $wellFormed = array_keys($numbers) === [0, 1] && $numbers[1] >= 0 && $numbers[0] >= $numbers[1];

        return $wellFormed ? [RejectionRate::of($mutator, $numbers[0], $numbers[1])] : [];
    }

    /** @return array<array-key, Node> */
    private static function entriesOf(Node $map): array
    {
        try {
            return $map->entries();
        } catch (NotInShape) {
            return [];
        }
    }
}
