<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_string;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

use function preg_match;
use function sprintf;

/**
 * What the `--filter` Pest builds for a mutant selects, from the tests that
 * cover the mutant, the way pest-plugin-mutate builds it: one alternative per
 * test, `<Class>::(.*)<name>`, unanchored on the left, which selects every
 * row of a test's data set. A test id that does not fit Pest's pattern is
 * dropped from the filter, and a filter built from an id with a repetition
 * or attempt in it does not select that test. Built to select exactly the
 * tests named, as a patched Pest's mutant run and every control select them,
 * it holds one alternative per class (see Alternatives).
 */
final readonly class Selection
{
    /** The delimiter the quoted pattern is read with, which a row's name escapes. */
    public const string DELIMITER = '"';
    /** A test's class, by its name within its namespace. */
    private const string CLASS_NAME = '/(?:^|\\\\)([^\\\\]+)::/';

    /** How pest-plugin-mutate's argument opens, before its quoted pattern. */
    private const string ARGUMENT = '--filter=';

    /**
     * @param list<string> $tests
     * @param list<string> $pieces   the alternatives of the filter, one for each test it can name
     * @param string       $argument the `--filter` argument the tests make, built once
     * @param bool         $fits     whether the argument is passed, judged once
     */
    private function __construct(
        private array $tests,
        private array $pieces,
        private string $argument,
        private bool $fits,
    ) {
    }

    /** The tests covering a mutant. */
    public static function of(TestIds $covering): self
    {
        return self::built($covering, Rows::Every, Suite::none());
    }

    /**
     * Exactly these tests, a row of a data set alone where an id names one;
     * a class, or a data set, named whole where the tests hold every test of
     * it the suite names, and the tests of one class named after it once.
     */
    public static function exactly(TestIds $tests, Suite $suite): self
    {
        return self::built($tests, Rows::Named, $suite);
    }

    /**
     * Exactly the tests covering a mutant, by their ids as the coverage map
     * Pest loaded names them: what a patched Pest's mutant run selects.
     *
     * @param list<string> $covering
     */
    public static function covering(array $covering, Suite $suite): self
    {
        return self::built($covering, Rows::Named, $suite);
    }

    /**
     * Whether pest-plugin-mutate's `--filter` argument reaches a mutant's own
     * run: short enough, in bytes, to start a process with (see Ceiling), and
     * a pattern PCRE compiles, without which PHPUnit selects no test.
     */
    public static function passable(string $argument): bool
    {
        return Ceiling::admits($argument) && self::filterIn($argument)->compiles();
    }

    public function count(): int
    {
        return count($this->tests);
    }

    /** The filter, as pest-plugin-mutate passes it, quotes and all, for a run that selects as a mutant's does. */
    public function filter(): Filter
    {
        return self::filterIn($this->argument);
    }

    /** The `--filter` argument pest-plugin-mutate starts the mutant's process with. */
    public function argument(): string
    {
        return $this->argument;
    }

    /** Whether the `--filter` argument is passed, as the patch judges it (see passable). */
    public function fits(): bool
    {
        return $this->fits;
    }

    /**
     * The tests among these the filter selects, as PHPUnit names them: none
     * where it has no alternative, and every one where its argument is not
     * passed, as the run then selects every test it loads.
     */
    public function among(TestIds $tests): TestIds
    {
        $pattern = $this->filter()->pattern();

        return match (true) {
            $this->pieces === [] => TestIds::none(),
            ! $this->fits => $tests,
            default => TestIds::of(...array_filter(
                [...$tests],
                static fn(TestId $test): bool => preg_match($pattern, self::nameOf($test->value())) === 1,
            )),
        };
    }

    /** The covering tests the filter does not select. */
    public function unselected(): TestIds
    {
        $unselected = array_filter($this->tests, static fn(string $test): bool => ! self::selects($test));

        return TestIds::of(...array_map(TestId::of(...), $unselected));
    }

    /** @return list<string> the class of each covering test that names one, by its name within its namespace */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->tests as $test) {
            if (preg_match(self::CLASS_NAME, $test, $parts) === 1) {
                $classes[$parts[1]] = $parts[1];
            }
        }

        return array_values($classes);
    }

    /**
     * The selection of these tests, each alternative selecting these rows of
     * its data set.
     *
     * @param TestIds|list<string> $tests
     */
    private static function built(TestIds|array $tests, Rows $rows, Suite $suite): self
    {
        $ids = $tests instanceof TestIds ? self::idsOf($tests) : $tests;
        $pieces = $rows === Rows::Every ? Alternatives::everyRow($ids) : Alternatives::exact($ids, $suite);

        $argument = sprintf('%s%s%s%s', self::ARGUMENT, self::DELIMITER, implode('|', $pieces), self::DELIMITER);

        return new self($ids, $pieces, $argument, self::passable($argument));
    }

    /** A test's name as a filter reads it, its row's included; the id itself where it names no method. */
    private static function nameOf(string $test): string
    {
        $method = TestMethod::of(TestId::of($test));

        return $method instanceof TestMethod ? $method->named() : $test;
    }

    /** The filter a `--filter` argument passes, quotes and all. */
    private static function filterIn(string $argument): Filter
    {
        return Filter::matching(mb_substr($argument, mb_strlen(self::ARGUMENT)));
    }

    /** @return list<string> each test's id */
    private static function idsOf(TestIds $tests): array
    {
        return array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
    }

    /**
     * Whether the filter built for a test selects it, as PHPUnit matches a
     * quoted filter against its name, its row's included, which the row it
     * is narrowed to always matches.
     */
    private static function selects(string $test): bool
    {
        $piece = Alternatives::pieceOf($test);

        return is_string($piece)
            && preg_match(sprintf('%s%s%s', self::DELIMITER, $piece, self::DELIMITER), self::nameOf($test)) === 1;
    }
}
