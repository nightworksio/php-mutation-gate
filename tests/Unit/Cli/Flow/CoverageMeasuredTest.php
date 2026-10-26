<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\CoverageMeasured;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

it('takes a run as it was asked for, against no kept map, until it is measured against its own scope\'s', function (): void {
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $asked = CoverageMeasured::asked($request);

    expect([$asked->request(), $asked->said(), $asked->isFromOwnScope(), $asked->fromOwnScope()->isFromOwnScope()])
        ->toEqual([$request, NotGiven::value(), false, true]);
});
