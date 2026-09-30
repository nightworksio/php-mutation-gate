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

use NightWorksIO\MutationGate\Core\Format\Bytes;

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
    /** The longest `--filter` argument, in bytes, a mutant's process is started with once `pest:patch` is applied. */
    public const int CEILING = 100000;

    /** pest-plugin-mutate's own pattern for a covering test's id. */
    private const string TEST = '/\\\\([a-zA-Z0-9]*)::(__pest_evaluable_)?([^#]*)"?/';

    /** A test's class, by its name within its namespace. */
    private const string CLASS_NAME = '/(?:^|\\\\)([^\\\\]*)::/';

    private const string EVALUABLE = '__pest_evaluable_';

    /** @param list<string> $tests */
    private function __construct(private array $tests)
    {
    }

    /** @param list<string> $tests the ids of the tests covering a mutant */
    public static function of(array $tests): self
    {
        return new self($tests);
    }

    public function count(): int
    {
        return count($this->tests);
    }

    /** The `--filter` argument pest-plugin-mutate starts the mutant's process with. */
    public function argument(): string
    {
        $pieces = array_unique(array_filter(
            array_map(self::pieceOf(...), $this->tests),
            static fn(string $piece): bool => $piece !== '',
        ));

        return sprintf('--filter="%s"', implode('|', $pieces));
    }

    /** Whether the `--filter` argument is short enough to be passed, in bytes, as the patch counts it. */
    public function fits(): bool
    {
        return Bytes::length($this->argument()) < self::CEILING;
    }

    /** @return list<string> the covering tests the filter does not select */
    public function unselected(): array
    {
        return array_values(array_filter($this->tests, static fn(string $test): bool => ! self::selects($test)));
    }

    /** @return list<string> the class of each covering test, by its name within its namespace */
    public function classes(): array
    {
        $classes = array_map(
            static fn(string $test): string => preg_match(self::CLASS_NAME, $test, $parts) === 1 ? $parts[1] : '',
            $this->tests,
        );

        return array_values(array_unique(array_filter($classes, static fn(string $class): bool => $class !== '')));
    }

    /** The alternative pest-plugin-mutate adds to the filter for one test, or nothing where it drops the test. */
    private static function pieceOf(string $test): string
    {
        if (preg_match(self::TEST, $test, $parts) !== 1) {
            return '';
        }

        $name = $parts[2] === self::EVALUABLE ? str_replace(['__', '_'], ['.{1,2}', '.'], $parts[3]) : $parts[3];

        return sprintf('%s::(.*)%s', $parts[1], $name);
    }

    /** Whether the filter built for a test selects it, as PHPUnit matches a quoted filter. */
    private static function selects(string $test): bool
    {
        $piece = self::pieceOf($test);

        return $piece !== '' && preg_match(sprintf('"%s"', $piece), explode('#', $test)[0]) === 1;
    }
}
