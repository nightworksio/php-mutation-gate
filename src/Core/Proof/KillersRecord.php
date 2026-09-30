<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;
use function array_keys;
use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function sprintf;

use stdClass;

/**
 * A kill history as a ledger holds it, in its `killers` section: each
 * mutant's ranking by its id, and each function's by its file and then its
 * name, each ranking a list of `[test, kills]` pairs, most kills first, the
 * test an index into the ledger's `tests`.
 *
 * Reading keeps each well-formed pair and drops anything else, never
 * repairing it: a pair whose index is past `tests`, or whose kills are not a
 * positive count, and a ranking left with no pair.
 *
 * @internal the shape of the `killers` section of the ledger file
 *
 * @phpstan-type Pairs list<array{int, int}>
 * @phpstan-type Written array{
 *     mutants: array<string, Pairs>|stdClass,
 *     functions: array<string, array<string, Pairs>>|stdClass,
 * }
 */
final readonly class KillersRecord
{
    /** The section's name, in a ledger and in the plan the Pest adapter hands its plugin. */
    public const string SECTION = 'killers';

    private const string MUTANTS = 'mutants';

    private const string FUNCTIONS = 'functions';

    /**
     * @param  array<string, int> $tests each test's index in the ledger, by its id
     * @return Written
     */
    public static function of(KillHistory $history, array $tests): array
    {
        $mutants = [];
        $functions = [];

        foreach ($history->mutants() as $ranked) {
            $mutants[$ranked->mutant()->value()] = self::pairs($ranked->ranking(), $tests);
        }

        foreach ($history->functions() as $ranked) {
            $function = $ranked->function();
            $functions[$function->file()->value()][$function->function()] = self::pairs($ranked->ranking(), $tests);
        }

        return [
            self::MUTANTS => $mutants === [] ? new stdClass() : $mutants,
            self::FUNCTIONS => $functions === [] ? new stdClass() : $functions,
        ];
    }

    /**
     * Every test a history names, each once, in the order it first names it.
     *
     * @return list<string>
     */
    public static function testsOf(KillHistory $history): array
    {
        $rankings = [];
        $tests = [];

        foreach ($history->mutants() as $ranked) {
            $rankings[] = $ranked->ranking();
        }

        foreach ($history->functions() as $ranked) {
            $rankings[] = $ranked->ranking();
        }

        foreach ($rankings as $ranking) {
            foreach ($ranking as $kills) {
                $tests[$kills->test()->value()] = true;
            }
        }

        return array_keys($tests);
    }

    /**
     * The history a ledger's `killers` section holds, none where it holds none.
     *
     * @param list<string> $tests the ledger's test ids, each at its index
     */
    public static function read(Node $section, array $tests): KillHistory
    {
        return self::functionsIn($section->field(self::FUNCTIONS), $tests, self::mutantsIn($section, $tests));
    }

    /** @param list<string> $tests */
    private static function mutantsIn(Node $section, array $tests): KillHistory
    {
        $history = KillHistory::none();

        foreach (self::entriesOf($section->field(self::MUTANTS)) as $id => $pairs) {
            $mutant = MutantId::parse(sprintf('%s', $id));
            $ranking = self::rankingIn($pairs, $tests);
            $history = $mutant instanceof MutantId && count($ranking) > 0
                ? $history->withMutant($mutant, $ranking)
                : $history;
        }

        return $history;
    }

    /** @param list<string> $tests */
    private static function functionsIn(Node $functions, array $tests, KillHistory $history): KillHistory
    {
        foreach (self::entriesOf($functions) as $file => $named) {
            foreach (self::entriesOf($named) as $name => $pairs) {
                $function = $file === ''
                    ? Nameless::code()
                    : Enclosing::of(Path::of(sprintf('%s', $file)), sprintf('%s', $name));
                $ranking = self::rankingIn($pairs, $tests);
                $history = $function instanceof Enclosing && count($ranking) > 0
                    ? $history->withFunction($function, $ranking)
                    : $history;
            }
        }

        return $history;
    }

    /**
     * @param  array<string, int> $tests
     * @return list<array{int, int}>
     */
    private static function pairs(Ranking $ranking, array $tests): array
    {
        $pairs = [];

        foreach ($ranking as $kills) {
            $pairs[] = [$tests[$kills->test()->value()], $kills->count()];
        }

        return $pairs;
    }

    /** @param list<string> $tests */
    private static function rankingIn(Node $pairs, array $tests): Ranking
    {
        $kills = [];

        foreach (self::itemsOf($pairs) as $pair) {
            $kills = [...$kills, ...self::killsIn($pair, $tests)];
        }

        return Ranking::of(...$kills);
    }

    /**
     * @param  list<string> $tests
     * @return list<Kills>  the kills a pair holds, or none where it is malformed
     */
    private static function killsIn(Node $pair, array $tests): array
    {
        try {
            $numbers = $pair->integers();
        } catch (NotInShape) {
            return [];
        }

        $wellFormed = array_keys($numbers) === [0, 1] && array_key_exists($numbers[0], $tests) && $numbers[1] > 0;

        return $wellFormed ? [Kills::of(TestId::of($tests[$numbers[0]]), $numbers[1])] : [];
    }

    /** @return list<Node> */
    private static function itemsOf(Node $list): array
    {
        try {
            return $list->items();
        } catch (NotInShape) {
            return [];
        }
    }

    /** @return array<array-key, Node> each entry, by its key, which PHP keys as a number where it reads as one */
    private static function entriesOf(Node $map): array
    {
        try {
            return $map->entries();
        } catch (NotInShape) {
            return [];
        }
    }
}
