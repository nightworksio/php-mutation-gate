<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use function array_map;
use function explode;
use function implode;
use function in_array;
use function mb_ucfirst;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Mutant\SourcePin;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\HoldsReader;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;

use function rtrim;
use function sprintf;
use function str_replace;

/**
 * One failing test for a mutant or a cluster, in a style (ADR-0015, decision
 * 4): comments that hold each mutant's heading and diff, its hint and the
 * call to the function around it, one assertion scaffold for each family, or
 * for a mutator pinned by source a call and a read of the function's file
 * (ADR-0021, decision 19), an assertion of value a weak test could make
 * (ADR-0025, decision 6); then the
 * line that fails until the test is filled in; then the `ignores.entries`
 * item that is the other way out (decision 5). A test for a held unit holds
 * it too, so the tests that hold it still cover it (ADR-0005, decision 9): a
 * Pest test joins its holding group, and a PHPUnit test carries `#[Holds]`,
 * with PHPUnit's `#[Group]` beside it where the runner reads each `#[Holds]`
 * as its test files load, since that runner cannot add a group to a class it
 * did not build.
 */
final readonly class StubText
{
    private const string COMMENT = '// %s';

    private const string FAILS = '%s, %s: %s Fill this test in.';

    private const string WEAK = 'Or make %s assert a value, not only an existence or a shape:';

    private const string IGNORE = 'Where no test can kill it, leave it out in ignores.entries instead, with a reason:';

    /** What ends a single-line comment in PHP besides the line, written so it does not. */
    private const string CLOSE_TAG = '?>';

    private const string OPEN_CLOSE_TAG = '? >';

    /** An attribute on a test method, by its class's name and its one argument. */
    private const string ATTRIBUTE = "#[\\%s(%s)]\n";

    /** The test, in a style, its ignore written as a config in this format writes one, for this runner to run. */
    public static function of(Subject $subject, AssertionStyle $style, Format $config, RunnerBehaviour $runner): string
    {
        $body = [
            ...self::comments(self::described($subject, $style)),
            self::failing($subject, $style),
            ...self::comments([self::IGNORE, ...self::ignores($subject, $config)]),
        ];

        return $style === AssertionStyle::Pest
            ? self::pest($subject, $body)
            : self::phpUnit($subject, $body, $runner);
    }

    /** What the test kills: `mutant 49e02fb39669`, or `cluster c1de8298b6e2`. */
    public static function named(Subject $subject): string
    {
        $cluster = $subject->cluster();

        return $cluster instanceof Cluster
            ? sprintf('cluster %s', $cluster->id()->value())
            : sprintf('mutant %s', $subject->first()->mutant()->id()->value());
    }

    /**
     * What the comments above the failing line say.
     *
     * @return list<string>
     */
    private static function described(Subject $subject, AssertionStyle $style): array
    {
        $first = $subject->first();
        $function = $subject->function();
        $lines = [];
        $families = [];

        foreach ($subject->members() as $member) {
            $block = MutantText::indented(MutantText::heading($member), ...MutantText::diff($member->mutant()));
            $lines = [...$lines, ...explode("\n", $block)];
        }

        $lines[] = $first->hint()->text();
        $lines = $function instanceof Enclosing ? [...$lines, sprintf('%s;', $function->call())] : $lines;

        $pin = $subject->pin();
        $lines = [...$lines, ...$pin instanceof SourcePin ? Scaffold::pinned($pin, $function, $style) : []];
        $pinned = $pin instanceof SourcePin ? $first->mutant()->mutator() : '';

        foreach ($subject->members() as $member) {
            $family = $member->mutant()->mutation()->family();
            $scaffold = in_array($family, $families, strict: true) || $member->mutant()->mutator() === $pinned
                ? []
                : Scaffold::of($member->mutant(), $function, $style);
            $lines = [...$lines, ...$scaffold];
            $families[] = $family;
        }

        return [...$lines, ...self::strengthened($first->finding())];
    }

    /**
     * The assertion of value a weak test that let the mutant through could make, in that test's style.
     *
     * @return list<string>
     */
    private static function strengthened(WeaklyAsserted|Removable|NoFinding $finding): array
    {
        return $finding instanceof WeaklyAsserted
            ? [
                sprintf(self::WEAK, $finding->first()->name()->value()),
                sprintf('%s;', $finding->first()->style()->suggestion($finding)),
            ]
            : [];
    }

    /**
     * The `ignores.entries` item for each mutant, its reason left for a person to write.
     *
     * @return list<string>
     */
    private static function ignores(Subject $subject, Format $config): array
    {
        $items = [];

        foreach ($subject->members() as $member) {
            $ignored = IgnoredMutant::of($member->mutant()->id(), '', Absent::setting());
            $items[] = $config === Format::Php
                ? sprintf('%s,', $ignored->php(ProjectRoot::origin()))
                : $ignored->written(ProjectRoot::origin())->line();
        }

        return $items;
    }

    /** The line that fails until the test is filled in, naming the mutant and its hint. */
    private static function failing(Subject $subject, AssertionStyle $style): string
    {
        $first = $subject->first();
        $message = PhpCalls::literal(sprintf(
            self::FAILS,
            mb_ucfirst(self::named($subject)),
            Label::of($first->judgement()),
            $first->hint()->text(),
        ));

        return $style === AssertionStyle::Pest
            ? sprintf('expect(true)->toBeFalse(%s);', $message)
            : sprintf('$this->fail(%s);', $message);
    }

    /**
     * Lines as single-line comments, each with no character that ends one
     * early: no control character, and no close tag.
     *
     * @param  list<string> $lines
     * @return list<string>
     */
    private static function comments(array $lines): array
    {
        return array_map(
            static fn(string $line): string => rtrim(sprintf(
                self::COMMENT,
                str_replace(self::CLOSE_TAG, self::OPEN_CLOSE_TAG, Fit::verbatim($line)),
            )),
            $lines,
        );
    }

    /** @param list<string> $body */
    private static function pest(Subject $subject, array $body): string
    {
        $unit = $subject->unit();

        return sprintf(
            "it(%s, function (): void {\n%s\n})%s;",
            PhpCalls::literal(sprintf('kills %s', self::named($subject))),
            self::indented($body, MutantText::INDENT),
            $unit->isHeld() ? sprintf('->group(%s)', PhpCalls::literal(self::holding($unit))) : '',
        );
    }

    /** @param list<string> $body */
    private static function phpUnit(Subject $subject, array $body, RunnerBehaviour $runner): string
    {
        $unit = $subject->unit();
        $holds = $unit->isHeld()
            ? sprintf(self::ATTRIBUTE, HoldsReader::HOLDS, PhpCalls::literal($unit->path()->value()))
            : '';
        $group = $unit->isHeld() && $runner->holdsAsLoaded()
            ? sprintf(self::ATTRIBUTE, HoldsReader::GROUP, PhpCalls::literal(self::holding($unit)))
            : '';

        return sprintf(
            "%s%spublic function testKills%s(): void\n{\n%s\n}",
            $holds,
            $group,
            str_replace(' ', '', mb_ucfirst(self::named($subject))),
            self::indented($body, MutantText::INDENT),
        );
    }

    /** The name of the group of the tests that hold a unit. */
    private static function holding(Unit $unit): string
    {
        return Group::holding($unit->path()->value())->name();
    }

    /** @param list<string> $lines */
    private static function indented(array $lines, string $indent): string
    {
        return implode("\n", array_map(static fn(string $line): string => sprintf('%s%s', $indent, $line), $lines));
    }
}
