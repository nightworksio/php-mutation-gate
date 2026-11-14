<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\StepsOverlap;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PendingControls;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

it('stops the controls it started, and nothing where it started none', function (): void {
    $stopped = 0;
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $pending = PendingControls::taking(
        static fn(): ControlRuns => ControlRuns::none(),
        static function () use (&$stopped): void {
            $stopped++;
        },
    );
    StepsOverlap::none($request)->stop();
    $before = $stopped;
    StepsOverlap::started($pending, new DateTimeImmutable(), 1, $request)->stop();

    expect($before)->toBe(0)
        ->and($stopped)->toBe(1);
});
