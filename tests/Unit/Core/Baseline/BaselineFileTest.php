<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;

$baseline = Baseline::of(
    Entry::of(Path::of('app/Http'), Floor::of(83.41)),
    Entry::of(Path::of('app/Domain'), Floor::of(100)),
    Entry::of(Path::of('app/Legacy'), Floor::of(61.2))
        ->lowered(Lowered::from(Floor::of(64.5), 'The export feature and its tests were removed together')),
);

$file = <<<'JSON'
    {
        "format": 1,
        "trees": {
            "app/Domain": { "floor": 100 },
            "app/Http": { "floor": 83.41 },
            "app/Legacy": {
                "floor": 61.2,
                "lowered": { "from": 64.5, "reason": "The export feature and its tests were removed together" }
            }
        }
    }

    JSON;

it('writes each tree in byte order, each floor on a line of its own', function () use ($baseline, $file): void {
    expect(BaselineFile::encode($baseline))->toBe($file);
});

it('writes an empty baseline as no trees', function (): void {
    expect(BaselineFile::encode(Baseline::none()))->toBe("{\n    \"format\": 1,\n    \"trees\": {}\n}\n");
});

it('reads back the baseline it wrote', function () use ($baseline, $file): void {
    expect(BaselineFile::decode($file, Path::of('mutation-gate.baseline.json')))->toEqual($baseline)
        ->and(BaselineFile::decode(BaselineFile::encode(Baseline::none()), Path::of('b.json')))->toEqual(Baseline::none());
});

it('writes a floor as a whole number where it is one, and with no trailing zero otherwise', function (float $floor, string $written): void {
    expect(BaselineFile::number(Floor::of($floor)))->toBe($written);
})->with([
    [100, '100'],
    [0, '0'],
    [83.41, '83.41'],
    [61.2, '61.2'],
    [7.05, '7.05'],
    [0.5, '0.5'],
]);

it('writes a tree path and a reason as JSON strings', function (): void {
    $written = BaselineFile::encode(Baseline::of(
        Entry::of(Path::of('app/"Odd"'), Floor::of(1))->lowered(Lowered::from(Floor::of(2), 'Crème "brûlée"')),
    ));

    expect($written)->toContain('"app/\"Odd\"": {')
        ->and($written)->toContain('"reason": "Crème \"brûlée\""');
});

it('refuses what is not a baseline, naming the file and where it went wrong', function (string $json, string $where): void {
    expect(BaselineFile::decode($json, Path::of('mutation-gate.baseline.json')))->toEqual(CannotJudge::because(sprintf(
        'The baseline mutation-gate.baseline.json cannot be read: %s Fix it, or run mutation-gate baseline --write.',
        $where,
    )));
})->with([
    'not JSON' => ['not a baseline', 'the file.format is missing.'],
    'another format' => ['{"format": 2, "trees": {}}', 'the file.format is not format 1.'],
    'trees that are not a map' => ['{"format": 1, "trees": 3}', 'the file.trees is not a map.'],
    'a floor that is not a number' => ['{"format": 1, "trees": {"app": {"floor": "high"}}}', 'the file.trees.app.floor is not a number.'],
    'a floor above 100' => ['{"format": 1, "trees": {"app": {"floor": 100.01}}}', 'the file.trees.app.floor is not a floor from 0 to 100.'],
    'a floor below 0' => ['{"format": 1, "trees": {"app": {"floor": -0.01}}}', 'the file.trees.app.floor is not a floor from 0 to 100.'],
    'no floor' => ['{"format": 1, "trees": {"app": {}}}', 'the file.trees.app.floor is missing.'],
    'a lowered with no reason' => ['{"format": 1, "trees": {"app": {"floor": 1, "lowered": {"from": 2}}}}', 'the file.trees.app.lowered.reason is missing.'],
    'a lowered from no floor' => ['{"format": 1, "trees": {"app": {"floor": 1, "lowered": {"reason": "x"}}}}', 'the file.trees.app.lowered.from is missing.'],
]);

it('reads floors at the ends of the range', function (): void {
    $read = BaselineFile::decode('{"format": 1, "trees": {"a": {"floor": 0}, "b": {"floor": 100}}}', Path::of('b.json'));

    expect($read)->toEqual(Baseline::of(Entry::of(Path::of('a'), Floor::of(0)), Entry::of(Path::of('b'), Floor::of(100))));
});

it('reads back a tree whose path reads as a number', function (): void {
    $numeric = Baseline::of(Entry::of(Path::of('12'), Floor::of(40)));

    expect(BaselineFile::decode(BaselineFile::encode($numeric), Path::of('b.json')))->toEqual($numeric);
});

it('writes each package\'s security floor after the trees, in byte order, and reads it back', function (): void {
    $secured = Baseline::of(Entry::of(Path::of('app'), Floor::of(90)))->withSecurity(
        Entry::of(Path::of('packages/billing'), Floor::of(80))->lowered(Lowered::from(Floor::of(85), 'The refund flow left.')),
        Entry::of(Path::root(), Floor::of(97.5)),
    );
    $written = <<<'JSON'
        {
            "format": 1,
            "trees": {
                "app": { "floor": 90 }
            },
            "security": {
                ".": { "floor": 97.5 },
                "packages/billing": {
                    "floor": 80,
                    "lowered": { "from": 85, "reason": "The refund flow left." }
                }
            }
        }

        JSON;

    expect(BaselineFile::encode($secured))->toBe($written)
        ->and(BaselineFile::decode($written, Path::of('b.json')))->toEqual($secured)
        ->and(BaselineFile::decode('{"format": 1, "trees": {}, "security": {"packages/a": {"floor": "high"}}}', Path::of('b.json')))
        ->toEqual(CannotJudge::because(
            'The baseline b.json cannot be read: the file.security.packages/a.floor is not a number. Fix it, or run mutation-gate baseline --write.',
        ));
});
