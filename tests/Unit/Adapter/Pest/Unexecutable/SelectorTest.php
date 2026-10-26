<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Choice;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Original;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Selector;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\PlacedLine;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;
use NightWorksIO\MutationGate\Core\Php\Unnamed;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\MatchHeads;
use NightWorksIO\MutationGate\Tests\Support\PestPrint;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Unexecutables;

afterEach(function (): void {
    Scratch::sweep();
});

/** The map a run left beside its results. */
function selectorMap(string $results): CoverageFile
{
    $coverage = CoverageFile::at(Recorder::coverageBeside($results));

    return $coverage instanceof CoverageFile ? $coverage : throw new RuntimeException('The run left no map.');
}

/** The selector over the project's run, whose map is the one the run left. */
function selectorOver(Project $at): Selector
{
    return Selector::over($at, selectorMap(Unexecutables::run($at, [])), Paths::of(Path::of('src/Money.php')));
}

/** Test files by their names under tests/. */
function selectorTests(string ...$names): Paths
{
    return Paths::of(...array_map(static fn(string $name): Path => Path::of(sprintf('tests/%s.php', $name)), $names));
}

it('chooses the test files that read a value, and those that cover the mutant\'s file to fall back on, never a file the suite runs no test of', function (): void {
    $selector = selectorOver(Unexecutables::project());
    $money = Path::of('src/Money.php');
    $covering = selectorTests('InternalSpec', 'OtherSpec');

    expect($selector->choose(Symbol::constant('App\Money', 'RATE'), $money))
        ->toEqual(Choice::of(selectorTests('MoneySpec'), $covering, ambiguous: false))
        ->and($selector->choose(Symbol::constant('App\Money', 'INTERNAL'), $money))
        ->toEqual(Choice::of(selectorTests('InternalSpec'), $covering, ambiguous: false))
        ->and($selector->choose(Symbol::constant('App\Money', 'UNREAD'), $money))
        ->toEqual(Choice::of(Paths::none(), $covering, ambiguous: false))
        ->and($selector->choose(Unnamed::of(SymbolKind::AttributeArgument), $money))
        ->toEqual(Choice::of(Paths::none(), $covering, ambiguous: true));
});

it('reads only the files still there, and selects the files that hold a covering test, never one whose name only ends in its class', function (): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, []);
    Scratch::write($at->root(), 'tests/Support/NotInternalSpec.php', "<?php\nnamespace Tests\\Support;\nfinal class NotInternalSpec { const RATE = \\App\\Money::INTERNAL; }\n");
    $long = sprintf('P\Tests\InternalSpec::%s', str_repeat('x', 100_000));
    CoverageMaps::write(
        Recorder::coverageBeside($results),
        sprintf('%s/', $at->root()),
        ['src/Money.php' => [10 => [0]], 'src/Gone.php' => [3 => [0]]],
        [$long],
        [$long => 0.1],
    );
    $selector = Selector::over($at, selectorMap($results), Paths::none());
    $run = selectorTests('InternalSpec');

    expect($selector->choose(Symbol::constant('App\Money', 'INTERNAL'), Path::of('src/Money.php')))
        ->toEqual(Choice::of($run, $run, ambiguous: false));
});

it('scans each mutated file, though the map covers no line of it', function (): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, []);
    $reads = 'P\Tests\MoneySpec::__pest_evaluable_it_reads';
    CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Other.php' => [1 => [0]]], [$reads], [$reads => 0.1]);
    $money = Path::of('src/Money.php');

    $read = Selector::over($at, selectorMap($results), Paths::of($money))->readCoverage(Paths::of($money), CoverageMap::empty());

    expect(array_map(static fn(TestId $test): string => $test->value(), [...$read->testsCovering($money, Line::of(5), Line::of(5))]))
        ->toBe([$reads]);
});

it('judges a mutant of a match\'s head by the tests that run its arms alone, with no fallback and nothing ambiguous', function (): void {
    $at = MatchHeads::project();
    $results = MatchHeads::run($at);
    $band = Path::of('src/Band.php');
    $selector = Selector::over($at, selectorMap($results), Paths::of($band));
    $original = $selector->original($band);
    $copy = Contents::of((string) file_get_contents(Recorder::mutantBeside($results, 'head')));

    expect($original instanceof Original ? $selector->judging($original->source(), $original->source()->changedAt($original->printed(), $copy)) : $original)
        ->toEqual(Choice::of(selectorTests('BandSpec'), Paths::none(), ambiguous: false));
});

it('judges a mutant of a statement\'s first line by the tests that run its other lines, though no match is in it', function (): void {
    $at = MatchHeads::project();
    $results = MatchHeads::run($at);
    $band = Path::of('src/Band.php');
    $selector = Selector::over($at, selectorMap($results), Paths::of($band));
    $original = $selector->original($band);
    $copy = Contents::of(PestPrint::of(str_replace('return strtoupper(', 'return strtolower(', MatchHeads::BAND)));

    expect($original instanceof Original ? $selector->judging($original->source(), $original->source()->changedAt($original->printed(), $copy)) : $original)
        ->toEqual(Choice::of(selectorTests('BandSpec'), Paths::none(), ambiguous: false));
});

it('covers the lines of each value one run can judge with the tests of the files that read it and of its fallback, each timed, and leaves the rest as they were', function (): void {
    $money = Path::of('src/Money.php');
    $onto = CoverageMap::empty()->covered($money, Line::of(10), TestId::of('P\Tests\InternalSpec::__pest_evaluable_it_runs'))
        ->timed(TestId::of('P\Tests\InternalSpec::__pest_evaluable_it_runs'), Seconds::of(9.0));
    $read = selectorOver(Unexecutables::project())->readCoverage(Paths::of($money), $onto);
    $tests = static fn(int $line): array => array_map(
        static fn(TestId $test): string => $test->value(),
        [...$read->testsCovering($money, Line::of($line), Line::of($line))],
    );
    $seconds = static fn(string $test): float => ($read->durationOf(TestId::of($test)) instanceof Seconds ? $read->durationOf(TestId::of($test)) : Seconds::of(-1.0))->seconds();

    expect([$tests(5), $tests(6), $tests(7), $tests(10)])->toEqualCanonicalizing([
        ['P\Tests\MoneySpec::__pest_evaluable_it_reads', 'P\Tests\InternalSpec::__pest_evaluable_it_runs', 'P\Tests\OtherSpec::__pest_evaluable_it_runs_the_other'],
        [],
        ['P\Tests\InternalSpec::__pest_evaluable_it_runs', 'P\Tests\OtherSpec::__pest_evaluable_it_runs_the_other'],
        ['P\Tests\InternalSpec::__pest_evaluable_it_runs'],
    ])
        ->and([$seconds('P\Tests\MoneySpec::__pest_evaluable_it_reads'), $seconds('P\Tests\InternalSpec::__pest_evaluable_it_runs'), $seconds('P\Tests\OtherSpec::__pest_evaluable_it_runs_the_other')])
        ->toBe([0.1, 9.0, 0.3])
        ->and(array_map(static fn(PlacedLine $line): string => sprintf('%s:%d', $line->file()->value(), $line->line()), [...$read->placedLines()]))
        ->not->toContain('src/Money.php:6')
        ->and(selectorOver(Unexecutables::project())->readCoverage(Paths::of(Path::of('src/Gone.php')), $onto))->toEqual($onto);
});
