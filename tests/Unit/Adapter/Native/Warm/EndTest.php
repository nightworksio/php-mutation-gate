<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Warm\End;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Job;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Printed;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Workplace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A workplace whose worker in place 1 printed some, then a child printed more on both streams.
 *
 * @return array{Workplace, Printed}
 */
function endsWorkplace(): array
{
    $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
    $workplace->opened(Job::of('', NotGiven::value(), [], NotGiven::value(), []));
    file_put_contents($workplace->out(1), 'worker ');
    $from = Printed::from($workplace, 1);
    file_put_contents($workplace->out(1), 'said', FILE_APPEND);
    file_put_contents($workplace->err(1), '!', FILE_APPEND);

    return [$workplace, $from->untilNow($workplace)];
}

it('reads a child back as it exited, with what it alone printed on both streams and how long it ran', function (): void {
    [$workplace, $printed] = endsWorkplace();
    End::exited(3, 1.5, $printed)->writtenTo($workplace->end(0));

    expect(End::ranIn($workplace, 0))->toEqual(Ran::exited(3, 'said!')->took(Seconds::of(1.5)))
        ->and(is_file(sprintf('%s.writing', $workplace->end(0))))->toBeFalse();
});

it('reads a child back as a signal ended it, or as stopped at its limit', function (): void {
    [$workplace, $printed] = endsWorkplace();
    End::signalled(9, 2.0, $printed)->writtenTo($workplace->end(0));
    End::stopped(4.0, $printed)->writtenTo($workplace->end(1));

    expect(End::ranIn($workplace, 0))->toEqual(Ran::signalled(9, 'said!')->took(Seconds::of(2.0)))
        ->and(End::ranIn($workplace, 0) instanceof Ran && End::ranIn($workplace, 0)->endedBySignal())->toBeTrue()
        ->and(End::ranIn($workplace, 1))->toEqual(Ran::stopped('said!')->took(Seconds::of(4.0)));
});

it('reads nothing of a run that never ended, or whose end is not one', function (): void {
    [$workplace] = endsWorkplace();
    file_put_contents($workplace->end(1), '{"code": "three"}');

    expect(End::ranIn($workplace, 0))->toEqual(NotGiven::value())
        ->and(End::ranIn($workplace, 1))->toEqual(NotGiven::value());
});

it('writes a child stopped at its limit, or for its silence, marked so beside how long it ran and where its printing lies', function (): void {
    [$workplace, $printed] = endsWorkplace();
    End::stopped(4.0, $printed)->writtenTo($workplace->end(0));
    End::silenced(2.5, $printed)->writtenTo($workplace->end(1));

    expect([file_get_contents($workplace->end(0)), file_get_contents($workplace->end(1))])->toBe([
        '{"stopped":true,"seconds":4.0,"place":1,"out":[7,11],"err":[0,1]}',
        '{"silenced":true,"seconds":2.5,"place":1,"out":[7,11],"err":[0,1]}',
    ]);
});
