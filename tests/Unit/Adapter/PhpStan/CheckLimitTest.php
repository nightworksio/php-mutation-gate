<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\CheckLimit;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('cannot judge a check stopped at its limit, and passes any other run on', function (): void {
    $stopped = ChildProcess::stopped('', '');
    $exited = ChildProcess::exited(0, '{}', '');

    expect(new CheckLimit(Seconds::of(45))->judged($stopped))->toEqual(CannotJudge::because('PHPStan did not finish a check in 45s.'))
        ->and(new CheckLimit()->judged($stopped))->toBe($stopped)
        ->and(new CheckLimit(Seconds::of(45))->judged($exited))->toBe($exited);
});
