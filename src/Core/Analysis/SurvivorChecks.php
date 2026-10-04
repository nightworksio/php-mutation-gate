<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;

use ArrayIterator;

use function count;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sort;
use function sprintf;

use Traversable;

/**
 * What static analysis's checks of survivors came to (ADR-0020, decisions 9
 * and 11): the time each analyser's checks took, and each survivor it left
 * unchecked, with why. The run warns once for each reason, with how many
 * survivors it left and the first of their files.
 *
 * @implements IteratorAggregate<int, UncheckedSurvivor>
 */
final readonly class SurvivorChecks implements IteratorAggregate
{
    private const string LEFT = 'Static analysis left %d %s unchecked, as %s: %s.';

    private const string ONE = 'survivor';

    private const string MANY = 'survivors';

    /** How a reason is told apart from none as it gathers files: as said, after a mark no reason starts with. */
    private const string SAID = ':%s';


    /** @param list<UncheckedSurvivor> $unchecked */
    private function __construct(private AnalyserHistories $histories, private array $unchecked)
    {
    }

    public static function none(): self
    {
        return new self(AnalyserHistories::none(), []);
    }

    /** These checks, with what they taught of one analyser, replacing what they held of it. */
    public function timing(AnalyserHistory $history): self
    {
        return new self($this->histories->with($history), $this->unchecked);
    }

    /** These checks, and one survivor more left unchecked. */
    public function leaving(UncheckedSurvivor $survivor): self
    {
        return new self($this->histories, [...$this->unchecked, $survivor]);
    }

    /** These checks and another shard's, added. */
    public function plus(self $other): self
    {
        return new self($this->histories->plus($other->histories), [...$this->unchecked, ...$other->unchecked]);
    }

    /** What the checks taught of each analyser: their time alone, as checks after the tests teach no rate. */
    public function histories(): AnalyserHistories
    {
        return $this->histories;
    }

    /**
     * One warning for each reason a survivor was left unchecked, in the order
     * the reasons are declared; for a check that could not run, one for each
     * reason given, as many as a shortened list shows, that reason after
     * it, and one for the survivors of any more, with none.
     */
    public function warnings(): Warnings
    {
        $warnings = Warnings::none();

        foreach (Unchecked::cases() as $why) {
            foreach ($this->said($why) as [$reason, $files]) {
                $warnings = $warnings->with($this->warning($why, $files, $reason));
            }
        }

        return $warnings;
    }

    /** @return Traversable<int, UncheckedSurvivor> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->unchecked);
    }

    /**
     * The files of the survivors left unchecked for this reason, gathered by
     * the reason each was given, for as many reasons as a shortened list
     * shows, in the order they first come; then, together, those given none
     * or any other.
     *
     * @return list<array{string|NotGiven, non-empty-list<string>}>
     */
    private function said(Unchecked $why): array
    {
        [$said, $unsaid] = $this->byReason($why);
        $unsaid = [...$unsaid, ...array_merge(...array_map(
            static fn(array $group): array => $group[1],
            array_slice($said, Fit::SHOWN),
        ))];

        return [...array_slice($said, 0, Fit::SHOWN), ...$unsaid === [] ? [] : [[NotGiven::value(), $unsaid]]];
    }

    /**
     * The files of the survivors left unchecked for this reason, by the
     * reason each was given, in the order they first come, and those given
     * none.
     *
     * @return array{list<array{string, non-empty-list<string>}>, list<string>}
     */
    private function byReason(Unchecked $why): array
    {
        $reasons = [];
        $unsaid = [];
        $mine = array_filter($this->unchecked, static fn(UncheckedSurvivor $one): bool => $one->why() === $why);

        foreach ($mine as $survivor) {
            $reason = $survivor->reason();
            $file = $survivor->file()->value();

            if ($reason instanceof NotGiven) {
                $unsaid[] = $file;

                continue;
            }

            $key = sprintf(self::SAID, $reason);
            $reasons[$key] = [$reason, [...array_key_exists($key, $reasons) ? $reasons[$key][1] : [], $file]];
        }

        return [array_values($reasons), $unsaid];
    }

    /** @param non-empty-list<string> $files each unchecked survivor's file */
    private function warning(Unchecked $why, array $files, string|NotGiven $reason): Warning
    {
        $distinct = array_values(array_unique($files));
        sort($distinct);
        $left = sprintf(
            self::LEFT,
            count($files),
            count($files) === 1 ? self::ONE : self::MANY,
            $why->because(),
            Fit::named($distinct),
        );

        return Warning::that($reason instanceof NotGiven ? $left : sprintf(Fit::JOINED, $left, $reason));
    }
}
