<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\MultipleVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('tells a patched runner\'s process the multiple its bounds allow, learned or written, and reads it back as told, telling nothing of the standard', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0));
    $told = MultipleVariable::of($bounds->multipliedBy(Learned::Learned)->learning(Multiple::of(4.25)));

    expect($told)->toBe([ChildVariable::MutantMultiple->value => '4.250000'])
        ->and(MultipleVariable::read($told[ChildVariable::MutantMultiple->value]))->toEqual(Multiple::of(4.25))
        ->and(MultipleVariable::of($bounds))->toBe([])
        ->and(MultipleVariable::of($bounds->multipliedBy(Multiple::of(3.0))))->toBe([]);
});

it('reads the standard three where a process was told nothing, or no positive number', function (string|false $told): void {
    expect(MultipleVariable::read($told))->toEqual(Multiple::standard());
})->with([
    'nothing told' => [false],
    'empty' => [''],
    'no number' => ['many'],
    'none' => ['0'],
    'less than none' => ['-2'],
]);
