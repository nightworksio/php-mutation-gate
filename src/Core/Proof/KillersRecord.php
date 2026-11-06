<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;
use function array_keys;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\RankedFunction;
use NightWorksIO\MutationGate\Core\Order\RankedMutant;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function sprintf;

use stdClass;

/**
 * A kill history as a ledger holds it, in its `killers` section: each
 * mutant's ranking, and each function's by its file and then its name, each
 * ranking a list of `[test, kills]` pairs, most kills first, the test an
 * index into the ledger's `tests`. A ledger places the ranking of a mutant
 * one of its proofs records as `[proof, place, pairs]`, by the proof's index
 * among the ledger's proofs and the mutant's among its records, the newest
 * last, and keeps any other by its id; a kill history's own file keeps every
 * one by its id.
 *
 * Reading keeps each well-formed pair and drops anything else, never
 * repairing it: a pair whose index is past `tests`, or whose kills are not a
 * positive count, and a ranking left with no pair.
 *
 * @internal the shape of the `killers` section of the ledger file
 *
 * @phpstan-type Pairs list<array{int, int}>
 * @phpstan-type Written array{
 *     placed?: list<array{int, int, Pairs}>,
 *     mutants: array<string, Pairs>|stdClass,
 *     functions: array<string, array<string, Pairs>>|stdClass,
 * }
 */
final readonly class KillersRecord
{
    /** The section's name, in a ledger and in the plan the Pest adapter hands its plugin. */
    public const string SECTION = 'killers';

    /** The rankings a ledger places among its proofs' records. */
    public const string PLACED = 'placed';

    private const string MUTANTS = 'mutants';

    private const string FUNCTIONS = 'functions';

    /**
     * @param  array<string, int> $tests each test's index in the ledger, by its id
     * @return Written
     */
    public static function of(KillHistory $history, array $tests): array
    {
        return self::placing($history, $tests, []);
    }

    /**
     * A ledger's kill history, each mutant's ranking placed where one of its proofs records the mutant.
     *
     * @param  array<string, int>             $tests  each test's index in the ledger, by its id
     * @param  array<string, array{int, int}> $places each mutant's proof and place among its records, by its id
     * @return Written
     */
    public static function placing(KillHistory $history, array $tests, array $places): array
    {
        $placed = PlacedRankings::of($history, $tests, $places);
        $mutants = PlacedRankings::unplaced($history, $tests, $places);
        $functions = [];

        foreach ($history->functions() as $ranked) {
            $function = $ranked->function();
            $functions[$function->file()->value()][$function->function()] = self::pairs($ranked->ranking(), $tests);
        }

        return [
            ...$placed === [] ? [] : [self::PLACED => $placed],
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
                $tests[$kills->test()->value()] = $kills->test()->value();
            }
        }

        return array_values($tests);
    }

    /**
     * The history a ledger's `killers` section holds, none where it holds none.
     *
     * @param list<string>             $tests  the ledger's test ids, each at its index
     * @param array<int, list<string>> $proofs the ids each placed proof records, in the order it records them, by
     *                                         the proof's index among the ledger's proofs
     */
    public static function read(Node $section, array $tests, array $proofs = []): KillHistory
    {
        return self::functionsIn(
            $section->field(self::FUNCTIONS),
            $tests,
            self::mutantsIn($section, $tests, PlacedRankings::read($section->field(self::PLACED), $tests, $proofs)),
        );
    }

    /**
     * A ranking as its `[test, kills]` pairs, most kills first.
     *
     * @param  array<string, int>    $tests each test's index in the ledger, by its id
     * @return list<array{int, int}>
     */
    public static function pairs(Ranking $ranking, array $tests): array
    {
        $pairs = [];

        foreach ($ranking as $kills) {
            $pairs[] = [$tests[$kills->test()->value()], $kills->count()];
        }

        return $pairs;
    }

    /**
     * The ranking a list of `[test, kills]` pairs holds, each well-formed pair of it.
     *
     * @param list<string> $tests the ledger's test ids, each at its index
     */
    public static function rankingIn(Node $pairs, array $tests): Ranking
    {
        $kills = [];

        foreach (self::itemsOf($pairs) as $pair) {
            $kills = [...$kills, ...self::killsIn($pair, $tests)];
        }

        return Ranking::of(...$kills);
    }

    /**
     * @param list<string>       $tests
     * @param list<RankedMutant> $placed
     */
    private static function mutantsIn(Node $section, array $tests, array $placed): KillHistory
    {
        $ranked = $placed;

        foreach (self::entriesOf($section->field(self::MUTANTS)) as $id => $pairs) {
            $mutant = MutantId::parse(sprintf('%s', $id));
            $ranking = self::rankingIn($pairs, $tests);
            if ($mutant instanceof MutantId && count($ranking) > 0) {
                $ranked[] = RankedMutant::of($mutant, $ranking);
            }
        }

        return KillHistory::none()->withMutants(...$ranked);
    }

    /** @param list<string> $tests */
    private static function functionsIn(Node $functions, array $tests, KillHistory $history): KillHistory
    {
        $ranked = [];

        foreach (self::entriesOf($functions) as $file => $named) {
            foreach (self::entriesOf($named) as $name => $pairs) {
                $function = self::functionAt(sprintf('%s', $file), sprintf('%s', $name));
                $ranking = self::rankingIn($pairs, $tests);

                if ($function instanceof Enclosing && count($ranking) > 0) {
                    $ranked[] = RankedFunction::of($function, $ranking);
                }
            }
        }

        return $history->withFunctions(...$ranked);
    }

    /** The function a ledger names by its file and its name; nameless where it names no file. */
    private static function functionAt(string $file, string $name): Enclosing|Nameless
    {
        return $file === '' ? Nameless::code() : Enclosing::of(Path::of($file), $name);
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
