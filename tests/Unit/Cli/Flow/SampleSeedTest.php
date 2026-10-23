<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\ChangeBase;
use NightWorksIO\MutationGate\Cli\Flow\SampleSeed;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Sampling;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$head = static fn(): Revision => Revision::ref(Flows::HEAD);
$fullRun = static fn(): CannotTell => CannotTell::because('A full run considers every unit.');
$checkout = static fn(): ChangeSourceFake => Flows::checkout()->resolving(Revision::ref('origin/main'), 'c0ffee');

it('draws from the seed the config sets, whatever the run', function () use ($head, $checkout): void {
    $adapters = Flows::adapters(Flows::project(), [], $checkout());

    expect(SampleSeed::of($adapters, Sampling::of(seed: 'release'), $head(), ChangeBase::since(Revision::ref('origin/main'))))
        ->toBe('release');
});

it('draws a change-scoped run from the commit its base ref is at, and from the ref where git cannot say', function () use ($head, $checkout): void {
    $since = ChangeBase::since(Revision::ref('origin/main'));
    $resolved = Flows::adapters(Flows::project(), [], $checkout());
    $unknown = Flows::adapters(Flows::project(), [], $checkout()->notHaving(Revision::ref('origin/main')));

    expect(SampleSeed::of($resolved, Sampling::of(), $head(), $since))->toBe('c0ffee')
        ->and(SampleSeed::of($unknown, Sampling::of(), $head(), $since))->toBe('origin/main');
});

it('draws any other run from the commit its plan is made on', function () use ($head, $fullRun, $checkout): void {
    expect(SampleSeed::of(Flows::adapters(Flows::project(), [], $checkout()), Sampling::of(), $head(), $fullRun()))->toBe(Flows::HEAD);
});
