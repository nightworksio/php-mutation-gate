<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Key\Judging;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('holds a unit and the test files that can judge it, or why none can be named', function (): void {
    $judging = Judging::of(Unit::file(Path::of('src/A.php')), Paths::of(Path::of('tests/ATest.php')));
    $unnamed = Judging::of(Unit::file(Path::of('src/B.php')), CannotJudge::because('No map.'));

    expect($judging->unit())->toEqual(Unit::file(Path::of('src/A.php')))
        ->and($judging->judges())->toEqual(Paths::of(Path::of('tests/ATest.php')))
        ->and($unnamed->judges())->toEqual(CannotJudge::because('No map.'));
});

it('reads what the paths entries matching a unit set into its key, and nothing where they set nothing', function (): void {
    $judging = Judging::of(Unit::file(Path::of('src/A.php')), Paths::of(Path::of('tests/ATest.php')));

    expect($judging->ruling())->toBe([])
        ->and($judging->ruledBy('')->ruling())->toBe([])
        ->and($judging->ruledBy('{"timeouts":{"seconds":60}}')->ruling())->toBe(['paths', '{"timeouts":{"seconds":60}}'])
        ->and($judging->ruledBy('{"timeouts":{"seconds":60}}')->judges())->toEqual($judging->judges());
});
