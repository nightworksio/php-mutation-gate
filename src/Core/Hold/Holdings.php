<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function array_any;
use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * Every declaration that some tests hold a path, from the groups a runner
 * lists and the `#[Holds]` a test file writes, and the units they make: one
 * per held path, judged by the tests that hold it alone.
 */
final readonly class Holdings
{
    /** Why a held path cannot be judged when it names nothing the gate mutates. */
    private const string MISSPELT = <<<'SAID'
        %s names %s, which is not a tree, or a file or directory inside one,
        spelt as the repository spells it. A misspelt path would otherwise be mutated
        against the whole suite, correct and slow, with no sign the declaration was never read.
        SAID;

    /** Why a held path cannot be judged when a group and `#[Holds]` both hold it. */
    private const string TWICE = <<<'SAID'
        %1$s is held both by the group holds:%1$s and by #[Holds].
        A held path is judged by one set of tests, so declare it one way.
        SAID;

    /** Why two held paths cannot be judged when one is inside the other. */
    private const string NESTED = <<<'SAID'
        %s is held, and so is %s inside it, so a mutant there would be judged twice.
        Hold one or the other.
        SAID;

    /** @param list<Holding> $holdings */
    private function __construct(private array $holdings)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** Every group of a suite whose name says which path it holds. */
    public static function inGroups(Groups $groups): self
    {
        $holdings = self::none();

        foreach ($groups as $group) {
            $holdings = $group->isHolding()
                ? $holdings->with(Holding::byGroup($group->held(), $group))
                : $holdings;
        }

        return $holdings;
    }

    public function with(Holding $holding): self
    {
        return new self([...$this->holdings, $holding]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->holdings, ...$other->holdings]);
    }

    /** Whether any of these is a `#[Holds]`, which a runner that selects held tests by group alone refuses. */
    public function anyByAttribute(): bool
    {
        return array_any($this->holdings, static fn(Holding $holding): bool => $holding->by() instanceof Holder);
    }

    /**
     * One unit per held path, judged by the tests that hold it. A path that is
     * not a tree or an existing file or directory inside one, a path held both
     * by a group and by `#[Holds]`, and a held path inside another cannot be
     * judged, unless both are held only from the holding suites: those holds
     * add judges and narrow nothing, so a mutant is judged once whatever they
     * hold.
     */
    public function units(Trees $trees, Fingerprints $files, Additions $additions): Units|CannotJudge
    {
        $units = [];

        foreach ($this->holdings as $holding) {
            $unit = $this->unitOf($holding, $trees, $files);

            if ($unit instanceof CannotJudge) {
                return $unit;
            }

            $units[$unit->path()->value()] = $unit;
        }

        return $this->apart($units, $additions->paths());
    }

    /**
     * The holds written only in the suites `tests.holding` lists, each with
     * the tests that hold its path there: a holding the suites that judge
     * every unit list no test of, and the holding suites list some
     * (ADR-0005, decision 9). Each path must be one `units()` accepts.
     */
    public function additions(TestListing $judging, TestListing $holdingSuites): Additions
    {
        $additions = Additions::none();

        foreach ($this->holdings as $holding) {
            $tests = $holding->testsIn($holdingSuites);
            $adds = count($holding->testsIn($judging)) === 0 && count($tests) > 0;
            $additions = $adds
                ? $additions->with(Addition::of(Path::of($holding->declared()), $tests, $holding->written()))
                : $additions;
        }

        return $additions;
    }

    private function unitOf(Holding $holding, Trees $trees, Fingerprints $files): Unit|CannotJudge
    {
        $path = Path::of($holding->declared());

        if ($path->value() !== $holding->declared() || ! Holdable::in($trees, $files)->has($path)) {
            return CannotJudge::because(sprintf(self::MISSPELT, $holding->written(), $holding->declared()));
        }

        $judge = $this->judgeOf($holding);

        return $judge instanceof CannotJudge ? $judge : Unit::held($path, $judge);
    }

    private function judgeOf(Holding $holding): Group|Filter|CannotJudge
    {
        $by = $holding->by();
        $holders = $this->holdersOf($holding->declared());

        if ($holders !== [] && $this->isGrouped($holding->declared())) {
            return CannotJudge::because(sprintf(self::TWICE, $holding->declared()));
        }

        return $by instanceof Group ? $by : Filter::matching($this->filterFor($holders));
    }

    /**
     * The classes and methods `#[Holds]` marks as holding a path.
     *
     * @return list<Holder>
     */
    private function holdersOf(string $declared): array
    {
        $holders = [];

        foreach ($this->holdings as $holding) {
            $by = $holding->by();
            $holders = $by instanceof Holder && $holding->declared() === $declared ? [...$holders, $by] : $holders;
        }

        return $holders;
    }

    private function isGrouped(string $declared): bool
    {
        return array_any(
            $this->holdings,
            static fn(Holding $holding): bool => $holding->by() instanceof Group && $holding->declared() === $declared,
        );
    }

    /**
     * A PHPUnit `--filter` that selects every test of a holding class and each
     * holding method, and nothing else.
     *
     * @param list<Holder> $holders
     */
    private function filterFor(array $holders): string
    {
        $patterns = array_map(static fn(Holder $holder): string => $holder->filtered(), $holders);

        return sprintf('/^(?:%s)/', implode('|', $patterns));
    }

    /** @param array<string, Unit> $units by path */
    private function apart(array $units, Paths $added): Units|CannotJudge
    {
        foreach ($units as $outer) {
            foreach ($units as $inner) {
                if (self::clash($outer->path(), $inner->path(), $added)) {
                    return CannotJudge::because(
                        sprintf(self::NESTED, $outer->path()->value(), $inner->path()->value()),
                    );
                }
            }
        }

        return Units::of(...$units);
    }

    /** Whether one held path lies inside another where a mutant there would be judged twice. */
    private static function clash(Path $outer, Path $inner, Paths $added): bool
    {
        $nested = ! $inner->equals($outer) && $inner->within($outer);

        return $nested && ! ($added->has($inner) && $added->has($outer));
    }
}
