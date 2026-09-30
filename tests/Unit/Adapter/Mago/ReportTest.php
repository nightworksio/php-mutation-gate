<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Report;
use NightWorksIO\MutationGate\Adapter\Mago\Started;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

it('cannot judge from an exit that is no finished analysis, or output that is no report', function (string $code): void {
    expect(Report::of(Started::run(Root::here(), Withheld::standard(), [PHP_BINARY, '-r', $code])))
        ->toBeInstanceOf(CannotJudge::class);
})->with([
    'a usage error' => ['echo \'{"issues": []}\'; exit(2);'],
    'no report' => ['echo "Mago panicked";'],
]);

it('says why of a command that never started', function (): void {
    $started = Started::run(Root::of('/nowhere/at/all'), Withheld::standard(), ['mago']);

    expect($started->said())->toStartWith('it did not run: ')
        ->and(Report::of($started))->toBeInstanceOf(CannotJudge::class);
});
