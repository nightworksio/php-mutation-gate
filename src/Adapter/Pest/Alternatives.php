<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_string;

use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\TestRow;

use function preg_match;
use function preg_quote;
use function sprintf;
use function str_replace;
use function strtr;

/**
 * The alternatives of the `--filter` that selects some tests (see Selection):
 * as pest-plugin-mutate builds them, one per test, `<Class>::(.*)<name>`,
 * which selects every row of a test's data set; or exactly, one per class:
 * the class alone where the tests hold it whole, and otherwise its tests'
 * names, each narrowed to the rows of a data set the ids name, matched to
 * the end of the name, unless the tests hold every row of it.
 */
final readonly class Alternatives
{
    /** pest-plugin-mutate's own pattern for a covering test's id. */
    private const string TEST = '/\\\\([a-zA-Z0-9]*)::(__pest_evaluable_)?([^#]*)"?/';

    /** The alternative for tests of a class: the class by its name within its namespace, then their names. */
    private const string PIECE = '%s::(.*)%s';

    /** The alternative for every test of a class. */
    private const string WHOLE_CLASS = '%s::';

    /** The names of several tests of one class, any of them. */
    private const string ANY = '(?:%s)';

    /** The rows of a data set a test's name is narrowed to, any of them, ending the name. */
    private const string NAMED_ROWS = '(?:%s)$';

    /**
     * Each escape a row's quoted name spells another way: Pest cuts a
     * `--filter` argument's value at a second `=`, so an `=` is spelled by
     * its code.
     */
    private const array UNCUT = ['\\=' => '\\x3D'];

    /**
     * pest-plugin-mutate's own alternatives, one for each test it can name.
     *
     * @param  list<string> $tests the tests, by their ids
     * @return list<string>
     */
    public static function everyRow(array $tests): array
    {
        return array_values(array_unique(array_filter(array_map(self::pieceOf(...), $tests), is_string(...))));
    }

    /**
     * The alternatives that select exactly these tests, one per class.
     *
     * @param  list<string> $tests the tests, by their ids
     * @return list<string>
     */
    public static function exact(array $tests, Suite $suite): array
    {
        $wholes = $suite->holding($tests);
        $whole = [];
        $names = [];

        foreach ($tests as $test) {
            $parts = self::partsOf($test);

            if ($parts instanceof Unselectable) {
                continue;
            }

            [$class, $name] = $parts;
            $whole += $wholes->holdClassOf($test) ? [$class => true] : [];
            $names[$class][$name][] = $wholes->rowOf($test);
        }

        return array_map(
            static fn(string $class): string => array_key_exists($class, $whole)
                ? sprintf(self::WHOLE_CLASS, $class)
                : self::ofClass($class, $names[$class]),
            array_keys($names),
        );
    }

    /** The alternative pest-plugin-mutate adds to the filter for one test, or none where it drops the test. */
    public static function pieceOf(string $test): string|Unselectable
    {
        $parts = self::partsOf($test);

        return $parts instanceof Unselectable ? $parts : sprintf(self::PIECE, ...$parts);
    }

    /**
     * A class's alternative of some of its tests: each test's name narrowed
     * to its rows, any of them.
     *
     * @param array<string, list<string>> $rows each test's rows, by its name, empty for every row
     */
    private static function ofClass(string $class, array $rows): string
    {
        $narrowed = [];

        foreach ($rows as $name => $named) {
            $narrowed[] = self::narrowed($name, array_unique($named));
        }

        $names = count($narrowed) === 1 ? $narrowed[0] : sprintf(self::ANY, implode('|', $narrowed));

        return sprintf(self::PIECE, $class, $names);
    }

    /**
     * A test's name narrowed to these rows of its data set; the whole test,
     * every row of it, where one of them is the whole test.
     *
     * @param array<string> $rows each row as PHPUnit names it, empty for the whole test
     */
    private static function narrowed(string $name, array $rows): string
    {
        if (in_array('', $rows, strict: true)) {
            return $name;
        }

        $quoted = array_map(
            static fn(string $row): string => strtr(preg_quote($row, Selection::DELIMITER), self::UNCUT),
            $rows,
        );

        return sprintf(TestRow::NAMED, $name, sprintf(self::NAMED_ROWS, implode('|', $quoted)));
    }

    /**
     * A test's class, by its name within its namespace, and its name as
     * pest-plugin-mutate's filter spells it; none where it drops the test.
     *
     * @return array{string, string}|Unselectable
     */
    private static function partsOf(string $test): array|Unselectable
    {
        if (preg_match(self::TEST, $test, $parts) !== 1) {
            return Unselectable::Test;
        }

        $name = $parts[2] === TestMethod::EVALUABLE ? str_replace(['__', '_'], ['.{1,2}', '.'], $parts[3]) : $parts[3];

        return [$parts[1], $name];
    }
}
