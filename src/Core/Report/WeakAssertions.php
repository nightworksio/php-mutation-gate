<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function array_values;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Assertion\WeakTest;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;
use function str_starts_with;

/**
 * The `tests` report's third section: each weak test that let survivors
 * through, what it asserts, those survivors, and the assertion of value
 * that would kill them, in the test's own style, on the result of the
 * function around the first of them (ADR-0025, decisions 6 and 7).
 *
 * @phpstan-type WeakEntry array{test: string, assertions: list<string>, survivors: list<string>, assert: string}
 */
final readonly class WeakAssertions
{
    public const string HEADING = 'Asserts only existence or shape';

    /** What the section says of itself. */
    public const string MEANS
        = 'Each asserts only that something is there, or its shape, and let through what a value would kill.';

    /** How Pest's expectations are written, which tells a Pest test from a PHPUnit one. */
    private const string PEST_MARK = '->';

    private const string PEST = 'expect(%s)->toBe(<expected>)';

    private const string PHPUNIT = '$this->assertSame(<expected>, %s)';

    private const string CALLED = '%s(…)';

    /** What stands for a result where no function is around the survivor. */
    private const string RESULT = '…';

    /** @return list<WeakEntry> each weak test, its rows folded in, in the order its first survivor was reported */
    public static function of(Verdict $verdict): array
    {
        $entries = [];

        foreach ($verdict->trees()->mutants() as $judged) {
            $finding = $judged instanceof JudgedMutant ? $judged->finding() : NoFinding::survivor();

            if (! $finding instanceof WeaklyAsserted) {
                continue;
            }

            foreach ($finding->tests() as $weak) {
                $name = $weak->name()->value();
                $entries[$name] = array_key_exists($name, $entries) ? $entries[$name] : self::entry($weak, $finding);
                $entries[$name]['survivors'][] = $judged->mutant()->id()->value();
            }
        }

        return array_values($entries);
    }

    /** @return list<string> the section as Markdown: its heading and count, what it means, and a table */
    public static function markdown(Verdict $verdict): array
    {
        $entries = self::of($verdict);
        $rows = ['| Test | It asserts | Survivors it let through | Assert instead |', '|---|---|---|---|'];

        foreach ($entries as $entry) {
            $rows[] = sprintf(
                '| %s | %s | %s | %s |',
                Escape::code($entry['test']),
                implode(' ', self::coded($entry['assertions'])),
                implode(' ', self::coded($entry['survivors'])),
                Escape::code($entry['assert']),
            );
        }

        return $entries === []
            ? [sprintf('## %s (0)', self::HEADING), 'None.']
            : [sprintf('## %s (%d)', self::HEADING, count($entries)), self::MEANS, implode("\n", $rows)];
    }

    /** @return list<string> the section as the console prints it, each test on a plain line, indented */
    public static function text(Verdict $verdict): array
    {
        $entries = self::of($verdict);
        $lines = ['', sprintf('%s (%d)', self::HEADING, count($entries))];

        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '%s%s  asserts %s  let through %s  assert instead %s',
                TestsText::INDENT,
                Fit::plain($entry['test']),
                Fit::plain(implode(', ', $entry['assertions'])),
                implode(', ', $entry['survivors']),
                Fit::plain($entry['assert']),
            );
        }

        return $entries === [] ? [...$lines, sprintf('%sNone.', TestsText::INDENT)] : $lines;
    }

    /** @return WeakEntry */
    private static function entry(WeakTest $weak, WeaklyAsserted $finding): array
    {
        $written = [];
        $pest = false;

        foreach ($weak->assertions() as $assertion) {
            $written[] = $assertion->written();
            $pest = $pest || str_starts_with($assertion->written(), self::PEST_MARK);
        }

        $function = $finding->function();
        $result = $function instanceof Nameless ? self::RESULT : sprintf(self::CALLED, $function);

        return [
            'test' => $weak->name()->value(),
            'assertions' => $written,
            'survivors' => [],
            'assert' => sprintf($pest ? self::PEST : self::PHPUNIT, $result),
        ];
    }

    /**
     * @param  list<string> $texts
     * @return list<string>
     */
    private static function coded(array $texts): array
    {
        $coded = [];

        foreach ($texts as $text) {
            $coded[] = Escape::code($text);
        }

        return $coded;
    }
}
