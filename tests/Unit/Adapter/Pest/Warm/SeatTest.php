<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;

it('names each file of a place, apart from every other place\'s, in the directory the workers share', function (): void {
    $seat = Seat::at('/run/warm', 2);

    expect([
        $seat->directory(),
        $seat->place(),
        $seat->stop(),
        Seat::stopIn('/run/warm'),
        $seat->pid(),
        $seat->request(),
        $seat->started('42-1'),
        $seat->ended('42-1'),
        $seat->out(),
        $seat->err(),
        $seat->report(),
        $seat->warned(),
    ])->toBe([
        '/run/warm',
        2,
        '/run/warm/stop',
        '/run/warm/stop',
        '/run/warm/2.pid',
        '/run/warm/2.request',
        '/run/warm/2.42-1.started',
        '/run/warm/2.42-1.ended',
        '/run/warm/2.out',
        '/run/warm/2.err',
        '/run/warm/2.database',
        '/run/warm/2.warned',
    ]);
});
