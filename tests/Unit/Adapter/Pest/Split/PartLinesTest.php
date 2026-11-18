<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Split\PartLines;
use NightWorksIO\MutationGate\Adapter\Pest\Split\TestParts;
use NightWorksIO\MutationGate\Core\Config\StreamJudging;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\LineSpan;
use NightWorksIO\MutationGate\Core\Mutant\MadeFile;
use NightWorksIO\MutationGate\Core\Mutant\MadeLines;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();

    foreach ([GateVariable::Split, GateVariable::Schemata, GateVariable::SplitPart, GateVariable::SplitRecording, GateVariable::SplitReachableOnly, GateVariable::Results] as $variable) {
        putenv($variable->value);
    }
});

/** Part `$number` of two, ATest's tests in the first and BTest's in the second, listing beside results in this directory. */
function partLinesOf(int $number, string $directory): PartLines
{
    return PartLines::cutBy(TestParts::cut(
        TestPart::of($number, 2),
        [
            'tests/ATest.php' => ['files' => ['tests/ATest.php'], 'tests' => ['A::a']],
            'tests/BTest.php' => ['files' => ['tests/BTest.php'], 'tests' => ['B::b']],
        ],
        ['A::a' => 2.0, 'B::b' => 1.0],
        StreamJudging::Parted,
    ), sprintf('%s/results.jsonl', $directory));
}

/**
 * A file of six lines made on these spans alone, each a first and last line.
 *
 * @param array{int, int} ...$spans
 */
function partLinesMade(string $path, array ...$spans): MadeFile
{
    return MadeFile::of(
        Path::of($path),
        Line::of(6),
        ...array_map(static fn(array $span): LineSpan => LineSpan::of(Line::of($span[0]), Line::of($span[1])), $spans),
    );
}

/** A file of six lines in this directory. */
function partLinesFile(string $directory, string $name): SplFileInfo
{
    Scratch::write($directory, sprintf('src/%s.php', $name), "<?php\n\n\n\n\n");

    return new SplFileInfo(sprintf('%s/src/%s.php', $directory, $name));
}

it('makes the lines its part\'s tests cover and those no test covers, every line where that is each, none where it is none, and lists the files it made some lines of', function (): void {
    $directory = Scratch::directory();
    $mixed = partLinesFile($directory, 'Mixed');
    $own = partLinesFile($directory, 'Own');
    $others = partLinesFile($directory, 'Others');
    $covered = [2 => ['A::a'], 3 => ['B::b'], 4 => ['A::a', 'B::b'], 5 => ['B::b'], 6 => []];
    $first = partLinesOf(1, $directory);
    $lines = [
        $first->of($mixed, $covered),
        $first->of($own, array_fill(1, 6, ['A::a'])),
        $first->of($others, array_fill(1, 6, ['B::b'])),
    ];
    $read = PartLines::madeBeside(sprintf('%s/results.jsonl', $directory), Root::of($directory));

    expect($lines)->toBe([[1, 2, 4, 6], [], false])
        ->and($read)->toEqual(MadeLines::whole()
            ->with(partLinesMade('src/Mixed.php', [1, 2], [4, 4], [6, 6]))
            ->with(partLinesMade('src/Others.php')))
        ->and(partLinesOf(2, $directory)->of($mixed, $covered))->toBe([1, 3, 4, 5, 6])
        ->and(PartLines::madeBeside(sprintf('%s/results.jsonl', $directory), Root::of($directory)))
        ->toEqual(MadeLines::whole()->with(partLinesMade('src/Mixed.php', [1, 1], [3, 6])));
});

it('reads every file whole where the run listed none, or what it listed cannot be read, and clears a list before a run', function (string $listed): void {
    $directory = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $directory);

    if ($listed !== '') {
        Scratch::write($directory, 'results.jsonl.made', $listed);
    }

    $read = PartLines::madeBeside($results, Root::of($directory));
    PartLines::cleared($results);

    expect($read)->toEqual(MadeLines::whole())
        ->and(is_file(sprintf('%s.made', $results)))->toBeFalse();
})->with([
    'none listed' => [''],
    'not a list' => ["{\"file\": 1}\n"],
]);

it('makes every mutant where the run splits no streams, is not cut, records each test\'s lines or makes every mutant by its setting', function (Closure $told): void {
    /** @var array<string, string> $set */
    $set = $told();
    $variables = [
        GateVariable::Split->value => '1',
        GateVariable::Schemata->value => '1',
        GateVariable::SplitPart->value => '1/2',
        GateVariable::Results->value => sprintf('%s/results.jsonl', Scratch::directory()),
        ...$set,
    ];

    foreach ($variables as $name => $value) {
        putenv(sprintf('%s=%s', $name, $value));
    }

    expect(PartLines::ofRun())->toBeInstanceOf(NotGiven::class);
})->with([
    'no streams' => [static fn(): array => [GateVariable::Split->value => '0']],
    'not cut' => [static fn(): array => [GateVariable::SplitPart->value => '']],
    'recording' => [static fn(): array => [GateVariable::SplitRecording->value => '/tmp/recorded']],
    'every mutant' => [static fn(): array => [GateVariable::SplitReachableOnly->value => '0']],
]);
