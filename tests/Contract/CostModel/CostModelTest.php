<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;

// What every cost model answers: a cost of nothing or more for any unit, and a
// timing for every unit of a finished shard that together is no more than the
// time the shard spent. One line per implementation.

$models = [
    'the fake' => fn(): CostModel => new CostModelFake(Seconds::of(3.0)),
];

$mutantOf = static fn(string $file, int $line): Mutant => Mutant::of(
    MutantId::hash(Path::of($file), 'Plus', sprintf('-%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of($file), Line::of($line), Line::of($line)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line)),
    MutantStatus::Killed,
    Seconds::of(0.5),
);

it('costs any unit nothing or more, measured or not', function (CostModel $model): void {
    $learned = Timings::of(Timing::of(Path::of('src/Money.php'), Seconds::of(12.4)));

    expect($model->cost(Unit::file(Path::of('src/Money.php')), $learned)->seconds())->toBeGreaterThanOrEqual(0.0)
        ->and($model->cost(Unit::file(Path::of('src/Other.php')), $learned)->seconds())->toBeGreaterThanOrEqual(0.0);
})->with($models);

it('learns a timing for every unit of a shard, together no more than the shard spent', function (CostModel $model) use ($mutantOf): void {
    $units = Units::of(Unit::file(Path::of('src/Money.php')), Unit::file(Path::of('src/Limit.php')));
    $timings = $model->learn($units, Mutants::of($mutantOf('src/Money.php', 1), $mutantOf('src/Money.php', 2), $mutantOf('src/Limit.php', 1)), Seconds::of(60.0));
    $seconds = array_map(static fn(Timing $timing): float => $timing->seconds()->seconds(), iterator_to_array($timings, preserve_keys: true));

    expect(array_map(static fn(Timing $timing): string => $timing->unit()->value(), iterator_to_array($timings, preserve_keys: true)))->toEqualCanonicalizing(['src/Money.php', 'src/Limit.php'])
        ->and(array_sum($seconds))->toBeLessThanOrEqual(60.0)
        ->and(array_filter($seconds, static fn(float $share): bool => $share < 0.0))->toBe([]);
})->with($models);
