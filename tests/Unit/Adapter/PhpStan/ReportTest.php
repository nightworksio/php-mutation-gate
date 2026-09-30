<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\Report;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

it('cannot judge an analysis PHPStan did not finish, by an error of no file', function (): void {
    expect(Report::of(ChildProcess::exited(1, '{"totals": {}, "files": {}, "errors": ["Internal error: out of memory."]}', '')))
        ->toEqual(CannotJudge::because('PHPStan did not finish its analysis: Internal error: out of memory.'));
});

it('cannot judge from output that is no report, an exit that is no finished analysis, or a PHPStan that never ran', function (ChildProcess $phpstan): void {
    expect(Report::of($phpstan))->toEqual(CannotJudge::because(sprintf('PHPStan wrote no report (%s).', $phpstan->said())));
})->with([
    'no report' => [ChildProcess::exited(1, 'File passed to --tmp-file option does not exist', '')],
    'a crash' => [ChildProcess::exited(255, '{"totals": {}, "files": {}, "errors": []}', 'Fatal error')],
    'never ran' => [ChildProcess::neverStarted('No such directory.')],
]);
