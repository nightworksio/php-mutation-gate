<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Offsets;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('stands where a place\'s output files have grown to, and at nothing where there are none yet', function (): void {
    $seat = Seat::at(Scratch::directory(), 1);
    $none = Offsets::now($seat);
    file_put_contents($seat->out(), 'printed');
    file_put_contents($seat->err(), 'warned too');
    $now = Offsets::now($seat);

    expect([$none->out(), $none->err(), $now->out(), $now->err()])->toBe([0, 0, 7, 10]);
});

it('reads back the offsets it wrote, and none where none are written whole', function (): void {
    $file = sprintf('%s/1.42-1.started', Scratch::directory());
    $missing = Offsets::readFrom($file);
    Offsets::of(3, 4)->writtenTo($file);
    $read = Offsets::readFrom($file);
    file_put_contents($file, '{"out": 3');

    expect($missing)->toEqual(NotGiven::value())
        ->and($read)->toEqual(Offsets::of(3, 4))
        ->and(Offsets::readFrom($file))->toEqual(NotGiven::value());
});

it('reads no offsets from a file that holds other JSON', function (): void {
    $file = sprintf('%s/1.42-1.started', Scratch::directory());
    file_put_contents($file, '{"out": "three"}');

    expect(Offsets::readFrom($file))->toEqual(NotGiven::value());
});
