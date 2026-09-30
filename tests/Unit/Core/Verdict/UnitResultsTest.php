<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

$result = static fn(string $path): UnitResult => UnitResult::of(Unit::file(Path::of($path)), Origin::Proved, Mutants::none());
$paths = static fn(UnitResults $results): array => array_map(static fn(UnitResult $result): string => $result->unit()->path()->value(), iterator_to_array($results, preserve_keys: true));

it('is a unit, where its result came from and its mutants', function (): void {
    $unit = Unit::file(Path::of('src/Money.php'));
    $mutants = Mutants::none();
    $result = UnitResult::of($unit, Origin::Carried, $mutants);

    expect($result->unit())->toBe($unit)
        ->and($result->origin())->toBe(Origin::Carried)
        ->and($result->mutants())->toBe($mutants);
});

it('holds nothing to begin with', function (): void {
    expect(UnitResults::none())->toHaveCount(0);
});

it('keeps results in the order they were added, numbered from nought', function () use ($result, $paths): void {
    expect($paths(UnitResults::of(...['b' => $result('b'), 'a' => $result('a')])))->toBe(['b', 'a']);
});

it('adds a result without changing the results it came from', function () use ($result, $paths): void {
    $results = UnitResults::of($result('a'));

    expect($paths($results->with($result('b'))))->toBe(['a', 'b'])
        ->and($results)->toHaveCount(1);
});
