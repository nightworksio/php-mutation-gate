<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Finish;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Offsets;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('reads back a run that exited, with where the output files stood', function (): void {
    $file = sprintf('%s/1.42-1.ended', Scratch::directory());
    Finish::exited(3, Offsets::of(10, 20))->writtenTo($file);
    $read = Finish::readFrom($file);

    expect($read)->toEqual(Finish::exited(3, Offsets::of(10, 20)))
        ->and($read instanceof Finish ? [$read->code(), $read->signal(), $read->isRefused(), $read->to()] : [])
        ->toEqual([3, NotGiven::value(), false, Offsets::of(10, 20)]);
});

it('reads back a run a signal ended', function (): void {
    $file = sprintf('%s/1.42-1.ended', Scratch::directory());
    Finish::signalled(15, Offsets::of(1, 2))->writtenTo($file);
    $read = Finish::readFrom($file);

    expect($read)->toEqual(Finish::signalled(15, Offsets::of(1, 2)))
        ->and($read instanceof Finish ? [$read->signal(), $read->code()] : [])->toEqual([15, NotGiven::value()]);
});

it('reads back a run the worker forked no child for', function (): void {
    $file = sprintf('%s/1.42-1.ended', Scratch::directory());
    Finish::refused('The warm worker could not fork, so a mutant ran fresh.')->writtenTo($file);
    $read = Finish::readFrom($file);

    expect($read instanceof Finish && $read->isRefused())->toBeTrue();
});

it('reads no finish where none is written whole', function (): void {
    $file = sprintf('%s/1.42-1.ended', Scratch::directory());

    expect(Finish::readFrom($file))->toEqual(NotGiven::value());

    file_put_contents($file, '{"code":');

    expect(Finish::readFrom($file))->toEqual(NotGiven::value());
});

it('reads no finish from a file that holds other JSON', function (): void {
    $file = sprintf('%s/1.42-1.ended', Scratch::directory());
    file_put_contents($file, '[3]');

    expect(Finish::readFrom($file))->toEqual(NotGiven::value());
});
