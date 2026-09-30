<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\Report;
use NightWorksIO\MutationGate\Adapter\PhpStan\Started;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/** What PHPStan's report reads as, where PHPStan printed this and exited so. */
function phpstanReported(string $printed, int $exit): mixed
{
    return Report::of(Started::run(Root::here(), Withheld::standard(), [
        PHP_BINARY,
        '-r',
        sprintf('echo %s; exit(%d);', var_export($printed, return: true), $exit),
    ]));
}

it('cannot judge an analysis PHPStan did not finish, by an error of no file', function (): void {
    expect(phpstanReported('{"totals": {}, "files": {}, "errors": ["Internal error: out of memory."]}', 1))
        ->toEqual(CannotJudge::because('PHPStan did not finish its analysis: Internal error: out of memory.'));
});

it('cannot judge from output that is no report, or an exit that is no finished analysis', function (): void {
    expect(phpstanReported('File passed to --tmp-file option does not exist', 1))->toBeInstanceOf(CannotJudge::class)
        ->and(phpstanReported('{"totals": {}, "files": {}, "errors": []}', 255))->toBeInstanceOf(CannotJudge::class);
});

it('says why of a command that never started', function (): void {
    $started = Started::run(Root::of('/nowhere/at/all'), Withheld::standard(), ['phpstan']);

    expect($started->said())->toStartWith('it did not run: ')
        ->and(Report::of($started))->toBeInstanceOf(CannotJudge::class);
});
