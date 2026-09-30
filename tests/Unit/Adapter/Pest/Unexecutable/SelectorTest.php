<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Choice;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Selector;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;
use NightWorksIO\MutationGate\Core\Php\Unnamed;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
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

it('chooses the test files that read a value, and those that cover the mutant\'s file to fall back on', function (): void {
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

it('reads only the files still there, and falls back on every test where Pest\'s filter cannot name them', function (): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, []);
    $long = sprintf('P\Tests\InternalSpec::%s', str_repeat('x', 100_000));
    CoverageMaps::write(
        Recorder::coverageBeside($results),
        sprintf('%s/', $at->root()),
        ['src/Money.php' => [10 => [0]], 'src/Gone.php' => [3 => [0]]],
        [$long],
        [$long => 0.1],
    );
    $selector = Selector::over($at, selectorMap($results), Paths::none());
    $every = selectorTests('InternalSpec', 'MoneySpec', 'OtherSpec');

    expect($selector->choose(Symbol::constant('App\Money', 'INTERNAL'), Path::of('src/Money.php')))
        ->toEqual(Choice::of($every, $every, ambiguous: false));
});
