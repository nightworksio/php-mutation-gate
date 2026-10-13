<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_string;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function preg_match;
use function sprintf;
use function str_replace;

/**
 * What the `--filter` Pest builds for a mutant selects, from the tests that
 * cover the mutant, the way pest-plugin-mutate builds it: one alternative per
 * test, `<Class>::(.*)<name>`, unanchored on the left. A test id that does not
 * fit Pest's pattern is dropped from the filter, and a filter built from an id
 * with a repetition or attempt in it does not select that test.
 */
final readonly class Selection
{
    /** What Pest begins the method it makes of a test's description with. */
    public const string EVALUABLE = '__pest_evaluable_';
    /** pest-plugin-mutate's own pattern for a covering test's id. */
    private const string TEST = '/\\\\([a-zA-Z0-9]*)::(__pest_evaluable_)?([^#]*)"?/';

    /** A test's class, by its name within its namespace. */
    private const string CLASS_NAME = '/(?:^|\\\\)([^\\\\]+)::/';

    /** How pest-plugin-mutate's argument opens, before its quoted pattern. */
    private const string ARGUMENT = '--filter=';

    /**
     * @param list<string> $tests
     * @param string       $argument the `--filter` argument the tests make, built once
     * @param bool         $fits     whether the argument is passed, judged once
     */
    private function __construct(private array $tests, private string $argument, private bool $fits)
    {
    }

    /** The tests covering a mutant. */
    public static function of(TestIds $covering): self
    {
        $tests = array_map(static fn(TestId $test): string => $test->value(), [...$covering]);
        $pieces = array_unique(array_filter(array_map(self::pieceOf(...), $tests), is_string(...)));

        $argument = sprintf('%s"%s"', self::ARGUMENT, implode('|', $pieces));

        return new self($tests, $argument, self::passable($argument));
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

    /** The filter a `--filter` argument passes, quotes and all. */
    private static function filterIn(string $argument): Filter
    {
        return Filter::matching(mb_substr($argument, mb_strlen(self::ARGUMENT)));
    }

    /** The alternative pest-plugin-mutate adds to the filter for one test, or none where it drops the test. */
    private static function pieceOf(string $test): string|Unselectable
    {
        if (preg_match(self::TEST, $test, $parts) !== 1) {
            return Unselectable::Test;
        }

        $name = $parts[2] === self::EVALUABLE ? str_replace(['__', '_'], ['.{1,2}', '.'], $parts[3]) : $parts[3];

        return sprintf('%s::(.*)%s', $parts[1], $name);
    }

    /** Whether the filter built for a test selects it, as PHPUnit matches a quoted filter. */
    private static function selects(string $test): bool
    {
        $piece = self::pieceOf($test);

        return is_string($piece) && preg_match(sprintf('"%s"', $piece), explode('#', $test)[0]) === 1;
    }
}
