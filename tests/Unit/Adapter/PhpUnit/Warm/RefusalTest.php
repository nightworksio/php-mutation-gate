<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Refusal;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads back why a worker forked nothing, and whether the run warns of it', function (): void {
    $guarded = sprintf('%s/guarded', Scratch::directory());
    $unforkable = sprintf('%s/unforkable', Scratch::directory());
    Refusal::guarded('the boot left a socket open')->writtenTo($guarded);
    Refusal::unforkable('no pcntl')->writtenTo($unforkable);

    $read = Refusal::readFrom($guarded);
    $quiet = Refusal::readFrom($unforkable);

    expect($read)->toEqual(Refusal::guarded('the boot left a socket open'))
        ->and($quiet)->toEqual(Refusal::unforkable('no pcntl'))
        ->and($read instanceof Refusal ? [$read->reason(), $read->warns()] : [])->toBe(['the boot left a socket open', true])
        ->and($quiet instanceof Refusal ? $quiet->warns() : null)->toBeFalse();
});

it('reads no refusal where a worker wrote none, or wrote what is not one', function (): void {
    $garbled = sprintf('%s/garbled', Scratch::directory());
    file_put_contents($garbled, '{"reason": 3}');

    expect(Refusal::readFrom(sprintf('%s/none', Scratch::directory())))->toEqual(NotGiven::value())
        ->and(Refusal::readFrom($garbled))->toEqual(NotGiven::value());
});
