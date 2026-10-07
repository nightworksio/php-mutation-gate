<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Hidden;
use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Flows;

$mutants = Flows::mutantsOf('src/Money.php');
$killed = array_values(array_filter([...$mutants], static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed));
$others = array_values(array_filter([...$mutants], static fn(Mutant $mutant): bool => $mutant->status() !== MutantStatus::Killed));
$first = $killed[0];
$second = $others[0];

it('keeps nothing of what a kill\'s process printed where a secret is in it, its code, signal and prefix kept', function () use ($mutants, $first): void {
    $evidence = Evidences::none()->with(
        $first->id(),
        Evidence::none()->withPrefix(Prefix::at(1))->withEnded(Ended::of(255, signalled: false, printed: 'key hunter2hunter2 leaked')),
    );
    $hidden = Hidden::in($evidence, $mutants, Secrets::of('hunter2hunter2'))->of($first->id());
    $ended = $hidden->ended();

    expect($ended instanceof Ended ? [$ended->code(), $ended->signalled(), $ended->tail()] : $ended)
        ->toEqual([255, false, NotGiven::value()])
        ->and($hidden->prefix())->toEqual(Prefix::at(1));
});

it('keeps what a kill\'s process printed where no secret is in it, as text a terminal shows safely', function () use ($mutants, $first): void {
    $evidence = Evidences::none()->with($first->id(), Evidence::none()->withEnded(Ended::of(1, signalled: false, printed: "fatal\x1b[2J error\n")));
    $ended = Hidden::in($evidence, $mutants, Secrets::of('hunter2hunter2'))->of($first->id())->ended();

    expect($ended instanceof Ended ? $ended->tail() : $ended)->toBe("fatal[2J error\n");
});

it('drops what a cut left of a secret at the start of a long print', function () use ($mutants, $first): void {
    $secret = sprintf('%s-withheld', str_repeat('s', 40));
    // The process kept the last 8 KiB: the secret's last 20 bytes, control characters, and the end.
    $printed = sprintf('%s%s%s%s', str_repeat('x', 100), $secret, str_repeat("\x00", 8165), 'the end');
    $evidence = Evidences::none()->with($first->id(), Evidence::none()->withEnded(Ended::of(1, signalled: false, printed: $printed)));
    $ended = Hidden::in($evidence, $mutants, Secrets::of($secret))->of($first->id())->ended();

    expect($ended instanceof Ended ? $ended->tail() : $ended)->toBe('the end');
});

it('keeps nothing where the tail\'s cut would fall inside a secret', function () use ($mutants, $first): void {
    $secret = 'withheld-value-across-the-cut';
    $printed = sprintf('%s%s%s', str_repeat('x', 100), $secret, str_repeat('y', 2040));
    $evidence = Evidences::none()->with($first->id(), Evidence::none()->withEnded(Ended::of(1, signalled: false, printed: $printed)));
    $ended = Hidden::in($evidence, $mutants, Secrets::of($secret))->of($first->id())->ended();

    expect($ended instanceof Ended ? $ended->tail() : $ended)->toEqual(NotGiven::value());
});

it('keeps an ending with nothing printed as it was', function () use ($mutants, $first): void {
    $evidence = Evidences::none()->with($first->id(), Evidence::none()->withEnded(Ended::unprinted(2, signalled: false)));

    expect(Hidden::in($evidence, $mutants, Secrets::of('hunter2hunter2'))->of($first->id())->ended())->toEqual(Ended::unprinted(2, signalled: false));
});

it('keeps a prefix alone as it was, and the evidence of no mutant the shard does not hold or does not end killed', function () use ($first, $second): void {
    $evidence = Evidences::none()
        ->with($first->id(), Evidence::none()->withPrefix(Prefix::at(4)))
        ->with($second->id(), Evidence::none()->withPrefix(Prefix::at(2)));

    expect(Hidden::in($evidence, Mutants::of($first), Secrets::none())->of($first->id()))->toEqual(Evidence::none()->withPrefix(Prefix::at(4)))
        ->and(Hidden::in($evidence, Mutants::of($first), Secrets::none()))->toHaveCount(1)
        ->and(Hidden::in($evidence, Mutants::of($first, $second), Secrets::none()))->toHaveCount(1);
});
