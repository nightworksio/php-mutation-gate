<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_put_contents;
use function htmlspecialchars;
use function implode;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/** Lists of tests as PHPUnit's `--list-tests-xml` writes them, for the fakes of a runner's shell. */
final readonly class TestLists
{
    /** How a listing command names the file it lists the tests into. */
    private const string INTO = '--list-tests-xml=';

    /**
     * A list of these tests of one class, and of these groups of them.
     *
     * @param list<string>               $tests  each test's id
     * @param array<string, list<string>> $groups each group's tests, by its name
     */
    public static function of(array $tests, array $groups): string
    {
        $methods = '';

        foreach ($tests as $test) {
            $methods .= sprintf('<testMethod id="%s" name="m"/>', self::escaped($test));
        }

        $listed = [];

        foreach ($groups as $name => $members) {
            $ids = '';

            foreach ($members as $member) {
                $ids .= sprintf('<test id="%s"/>', self::escaped($member));
            }

            $listed[] = sprintf('<group name="%s">%s</group>', self::escaped($name), $ids);
        }

        return sprintf(
            '<?xml version="1.0"?><testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>'
            . '<testClass name="T" file="t.php">%s</testClass></tests><groups>%s</groups></testSuite>',
            $methods,
            implode('', $listed),
        );
    }

    /**
     * Whether these arguments ask for a list of tests, the list written into the file they name where they do.
     *
     * @param list<string> $arguments
     */
    public static function wrote(array $arguments, string $list): bool
    {
        $wrote = false;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, self::INTO)) {
                file_put_contents(mb_substr($argument, mb_strlen(self::INTO)), $list);
                $wrote = true;
            }
        }

        return $wrote;
    }

    private static function escaped(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES);
    }
}
