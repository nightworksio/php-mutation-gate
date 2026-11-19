<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\CoverageEntries;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\QuickCoverage;
use NightWorksIO\MutationGate\Cli\Flow\QuickRound;
use NightWorksIO\MutationGate\Config\Coverage;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Local\LocalLoop;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\AnsweringGraph;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\MisbehavingRunner;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Which tests run each line of a file a map covers, by line.
 *
 * @return array<int, list<string>>
 */
function quickLines(CoverageMap $map, string $file, int $last): array
{
    $lines = [];

    foreach (range(1, $last) as $line) {
        $lines[$line] = array_map(static fn(TestId $test): string => $test->value(), [...$map->testsCovering(Path::of($file), Line::of($line), Line::of($line))]);
    }

    return $lines;
}

it('covers a changed source file wholly by its tests and those the graph says reach it, and each file a changed test file the graph places reaches by its tests', function (): void {
    $files = [...Flows::FILES, 'tests/HeldTest.php' => "<?php\n\nit('doubles', fn () => expect(2)->toBe(2));\n"];
    $project = Flows::project();
    Scratch::write($project, 'tests/HeldTest.php', $files['tests/HeldTest.php']);
    $changes = Changes::of(
        Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))),
        Change::modified(Path::of('tests/MoneyTest.php'), Lines::of(Line::of(3))),
        Change::modified(Path::of('tests/HeldTest.php'), Lines::of(Line::of(3))),
    );
    $checkout = new ChangeSourceFake(Revision::ref(Flows::HEAD), $changes, [Revision::workingTree()->name() => $files, Flows::HEAD => $files]);
    $graph = new AnsweringGraph(GraphAnswer::of(
        ['src/Money.php' => Paths::of(Path::of('tests/HeldTest.php'))],
        ['tests/MoneyTest.php' => Paths::of(Path::of('src/Held.php'))],
        [],
        Paths::none(),
        [],
    ));
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $settings = Flows::settings(Coverage::incremental());
    $adapters = Flows::adapters($project, [], $runner, $checkout, $graph)->looping(ProcessCount::of(1), LocalLoop::of(LocalWork::of())->quickly());
    $inventory = Inventory::of($adapters, $settings);
    $entries = new KeptCoverage($adapters, $settings, Flows::setup())->entries($inventory);

    expect($inventory)->toBeInstanceOf(Inventory::class)
        ->and($entries)->toBeInstanceOf(CoverageEntries::class);

    if (! $inventory instanceof Inventory || ! $entries instanceof CoverageEntries) {
        return;
    }

    $quick = QuickCoverage::of($adapters, $settings, $inventory->suite, $entries, QuickRound::after($changes));
    $map = $quick->over(Flows::map(), $entries, $adapters);
    $none = QuickCoverage::of($adapters, $settings, $inventory->suite, $entries, QuickRound::after(Changes::none()));

    expect([$quick->sources(), $quick->measures(Path::of('tests/MoneyTest.php'), kept: true), $quick->measures(Path::of('tests/MoneyTest.php'), kept: false), $quick->measures(Path::of('tests/HeldTest.php'), kept: true)])
        ->toBe([1, false, true, true])
        ->and([$none->sources(), $none->measures(Path::of('tests/MoneyTest.php'), kept: true)])->toBe([0, false])
        ->and($map instanceof CoverageMap ? quickLines($map, 'src/Money.php', 6) : $map)
        ->toBe(array_fill(1, 6, ['MoneyTest::adds', 'HeldTest::doubles']))
        ->and($map instanceof CoverageMap ? quickLines($map, 'src/Held.php', 6) : $map)
        ->toBe(array_fill(1, 6, ['MoneyTest::adds']));
});

it('cannot cover a quick round where the runner cannot tell the tests of a file the graph places or a changed test file', function (bool $changedTest): void {
    $files = [...Flows::FILES, 'tests/HeldTest.php' => "<?php\n\nit('doubles', fn () => expect(2)->toBe(2));\n"];
    $project = Flows::project();
    Scratch::write($project, 'tests/HeldTest.php', $files['tests/HeldTest.php']);
    $changes = Changes::of($changedTest
        ? Change::modified(Path::of('tests/MoneyTest.php'), Lines::of(Line::of(3)))
        : Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))));
    $checkout = new ChangeSourceFake(Revision::ref(Flows::HEAD), $changes, [Revision::workingTree()->name() => $files, Flows::HEAD => $files]);
    $graph = new AnsweringGraph(GraphAnswer::of(
        ['src/Money.php' => Paths::of(Path::of('tests/HeldTest.php'))],
        ['tests/MoneyTest.php' => Paths::of(Path::of('src/Held.php'))],
        [],
        Paths::none(),
        [],
    ));
    $refusing = new ArrayObject();
    $runner = new MisbehavingRunner(
        new CoverageAsked(ScriptedRunner::fixture(), Flows::map()),
        testsIn: static fn(Paths $asked, CoverageMap $map): TestIds|CannotJudge => $refusing->count() > 0 ? CannotJudge::because('The runner cannot list them.') : ScriptedRunner::fixture()->testsIn($asked, $map),
    );
    $settings = Flows::settings(Coverage::incremental());
    $adapters = Flows::adapters($project, [], $runner, $checkout, $graph)->looping(ProcessCount::of(1), LocalLoop::of(LocalWork::of())->quickly());
    $inventory = Inventory::of($adapters, $settings);
    $entries = new KeptCoverage($adapters, $settings, Flows::setup())->entries($inventory);

    expect($inventory)->toBeInstanceOf(Inventory::class)
        ->and($entries)->toBeInstanceOf(CoverageEntries::class);

    if (! $inventory instanceof Inventory || ! $entries instanceof CoverageEntries) {
        return;
    }

    $quick = QuickCoverage::of($adapters, $settings, $inventory->suite, $entries, QuickRound::after($changes));
    $refusing->append('refusing');

    expect($quick->over(Flows::map(), $entries, $adapters))->toEqual(CannotJudge::because('The runner cannot list them.'));
})->with(['a source the graph places' => [false], 'a changed test file' => [true]]);
