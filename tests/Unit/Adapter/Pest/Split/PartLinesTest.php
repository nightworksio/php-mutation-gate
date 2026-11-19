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

/**
 * Part `$number` of two, ATest's tests in the first and BTest's in the
 * second, listing beside results in this directory, of these files covered so.
 *
 * @param list<SplFileInfo>                             $files
 * @param array<string, array<int, array<int, string>>> $covered
 */
function partLinesOf(int $number, string $directory, array $files = [], array $covered = []): PartLines
{
    return PartLines::cutBy(TestParts::cut(
        TestPart::of($number, 2),
        [
            'tests/ATest.php' => ['files' => ['tests/ATest.php'], 'tests' => ['A::a']],
            'tests/BTest.php' => ['files' => ['tests/BTest.php'], 'tests' => ['B::b']],
        ],
        ['A::a' => 2.0, 'B::b' => 1.0],
        StreamJudging::Parted,
    ), sprintf('%s/results.jsonl', $directory), $files, $covered);
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

it('makes the lines its part\'s tests cover and those no test covers where its file\'s fall to it, every line where that is each, none where it is none, and lists the files it made some lines of', function (): void {
    $directory = Scratch::directory();
    $mixed = partLinesFile($directory, 'Mixed');
    $own = partLinesFile($directory, 'Own');
    $others = partLinesFile($directory, 'Others');
    $covered = [2 => ['A::a'], 3 => ['B::b'], 4 => ['A::a', 'B::b'], 5 => ['B::b'], 6 => []];
    $files = [$mixed, $own, $others];
    $map = [
        strval($mixed->getRealPath()) => $covered,
        strval($own->getRealPath()) => array_fill(1, 6, ['A::a']),
        strval($others->getRealPath()) => array_fill(1, 6, ['B::b']),
    ];
    $first = partLinesOf(1, $directory, $files, $map);
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
        ->and(partLinesOf(2, $directory, $files, $map)->of($mixed, $covered))->toBe([3, 4, 5])
        ->and(PartLines::madeBeside(sprintf('%s/results.jsonl', $directory), Root::of($directory)))
        ->toEqual(MadeLines::whole()->with(partLinesMade('src/Mixed.php', [3, 5])));
});

it('spreads the lines no test covers of each file to one part alone, the file with the most first, to the part with the fewest so far', function (): void {
    $directory = Scratch::directory();
    $files = [partLinesFile($directory, 'Big'), partLinesFile($directory, 'Small'), partLinesFile($directory, 'Mid')];
    $map = [
        strval($files[0]->getRealPath()) => [],
        strval($files[1]->getRealPath()) => array_fill(1, 5, ['A::a']),
        strval($files[2]->getRealPath()) => array_fill(1, 3, ['B::b']),
    ];
    $made = static fn(int $number): array => array_map(
        static fn(SplFileInfo $file): array|false => partLinesOf($number, $directory, $files, $map)
            ->of($file, $map[strval($file->getRealPath())]),
        $files,
    );

    expect($made(1))->toBe([[], [1, 2, 3, 4, 5], false])
        ->and($made(2))->toBe([false, [6], []])
        ->and(partLinesOf(2, $directory, $files, $map)->parts()->ownsUncovered('m', strval($files[0]->getRealPath())))
        ->toBeFalse()
        ->and(partLinesOf(1, $directory, $files, $map)->parts()->ownsUncovered('m', strval($files[0]->getRealPath())))
        ->toBeTrue();
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

    expect(PartLines::ofRun([], []))->toBeInstanceOf(NotGiven::class);
})->with([
    'no streams' => [static fn(): array => [GateVariable::Split->value => '0']],
    'not cut' => [static fn(): array => [GateVariable::SplitPart->value => '']],
    'recording' => [static fn(): array => [GateVariable::SplitRecording->value => '/tmp/recorded']],
    'every mutant' => [static fn(): array => [GateVariable::SplitReachableOnly->value => '0']],
]);
