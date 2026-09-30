<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\HeldCoverage;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/** Fills that count how often they were asked to, each answering with the directory or, failing, why not. */
$fills = static fn(): object => new class {
    private int $count = 0;

    public function count(): int
    {
        return $this->count;
    }

    /** @return Closure(): (DiskPath|CannotJudge) */
    public function one(bool $failing = false): Closure
    {
        return function () use ($failing): DiskPath|CannotJudge {
            $this->count++;

            return $failing ? CannotJudge::because('failed') : DiskPath::of('/p/.gate/infection/coverage');
        };
    }
};

it('fills the directory once for the same run or map, and again for another', function () use ($fills): void {
    $held = new HeldCoverage();
    $fill = $fills();
    $run = Command::php('phpunit', '--group=holds:src/Money.php');

    $held->ranBy($run, $fill->one());
    $held->ranBy($run, $fill->one());
    $afterRun = $fill->count();
    $held->handedOn(Path::of('planned'), $fill->one());
    $held->handedOn(Path::of('planned'), $fill->one());
    $afterMap = $fill->count();
    $held->handedOn(Path::of('elsewhere'), $fill->one());
    $held->ranBy($run, $fill->one());
    $held->ranBy(Command::php('phpunit', '--group=holds:src/Held.php'), $fill->one());
    $held->ranBy($run->withholding(Withheld::of('DEPLOY_*')), $fill->one());

    expect([$afterRun, $afterMap, $fill->count()])->toBe([1, 2, 6]);
});

it('remembers no fill that failed', function () use ($fills): void {
    $held = new HeldCoverage();
    $fill = $fills();
    $run = Command::php('phpunit');

    $failed = $held->ranBy($run, $fill->one(failing: true));
    $filled = $held->ranBy($run, $fill->one());

    expect($failed)->toEqual(CannotJudge::because('failed'))
        ->and($filled)->toEqual(DiskPath::of('/p/.gate/infection/coverage'))
        ->and($fill->count())->toBe(2);
});

it('fills the directory afresh once it forgets what it held', function () use ($fills): void {
    $held = new HeldCoverage();
    $fill = $fills();
    $run = Command::php('phpunit');

    $held->ranBy($run, $fill->one());
    $held->forget();
    $held->ranBy($run, $fill->one());

    expect($fill->count())->toBe(2);
});
