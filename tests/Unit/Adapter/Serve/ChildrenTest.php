<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\Children;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\InlineForking;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;

afterEach(function (): void {
    ServedProjects::left();
    Scratch::sweep();
});

/** A request to run a watch. */
function childRequest(): ServeRequest
{
    return ServeRequest::running(ServeIdentity::of('/p', '1.0.0', 'lock', 'config'), ['gate', 'watch'], [], '', decorated: false);
}

it('runs a request in a child that sends its exit code and closes its end, and reaps it once, once it has ended', function (): void {
    ServedProjects::entered();
    [, $client, $daemon] = ServedProjects::connectedPair();
    $children = new Children(new InlineForking());
    $forked = $children->forked(childRequest(), $daemon, static fn(): int => 3);
    $exit = ServeFrame::readFrom(ServedProjects::lineOf($client));
    $began = hrtime(as_number: true);
    $after = $client->waitingAtMost(2)->line();
    $took = (hrtime(as_number: true) - $began) / Seconds::NANOSECONDS;

    expect($forked)->toBeTrue()
        ->and($exit instanceof ServeFrame ? $exit->code() : -1)->toBe(3)
        ->and($after)->toEqual(NotGiven::value())
        ->and($took)->toBeLessThan(1.0)
        ->and([$children->reaped(), $children->reaped(), $children->areNone()])->toBe([true, false, true]);
});

it('forks no child, and keeps none, where none can be forked', function (): void {
    ServedProjects::entered();
    [, , $daemon] = ServedProjects::connectedPair();
    $children = new Children(InlineForking::failing());

    expect($children->forked(childRequest(), $daemon, static fn(): int => 0))->toBeFalse()
        ->and($children->connections())->toBe([]);
});

it('stops every child it runs, and keeps each running child\'s connection', function (): void {
    ServedProjects::entered();
    [, , $daemon] = ServedProjects::connectedPair();
    $forking = InlineForking::holding();
    $children = new Children($forking);
    $children->forked(childRequest(), $daemon, static fn(): int => 0);
    $children->forked(childRequest(), $daemon, static fn(): int => 0);
    $kept = array_keys($children->connections());
    $children->allStopped();

    expect($kept)->toBe([1000, 1001])
        ->and($forking->stopped)->toBe([1000, 1001])
        ->and($children->reaped())->toBeTrue()
        ->and($children->areNone())->toBeTrue();
});
