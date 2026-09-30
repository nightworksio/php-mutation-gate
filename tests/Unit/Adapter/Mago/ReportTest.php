<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Report;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

it('cannot judge from an exit that is no finished analysis, output that is no report, or a Mago that never ran', function (ChildProcess $mago): void {
    expect(Report::of($mago))->toEqual(CannotJudge::because(sprintf('Mago wrote no report (%s).', $mago->said())));
})->with([
    'a usage error' => [ChildProcess::exited(2, '{"issues": []}', 'ERROR --substitute: no such file')],
    'no report' => [ChildProcess::exited(1, 'Mago panicked', '')],
    'never ran' => [ChildProcess::neverStarted('No such directory.')],
]);
