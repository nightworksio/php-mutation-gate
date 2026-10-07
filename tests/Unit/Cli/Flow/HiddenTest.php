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
use NightWorksIO\MutationGate\Tests\Support\Flows;

$mutants = Flows::mutantsOf('src/Money.php');
$killed = array_values(array_filter([...$mutants], static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed));
$others = array_values(array_filter([...$mutants], static fn(Mutant $mutant): bool => $mutant->status() !== MutantStatus::Killed));
$first = $killed[0];
$second = $others[0];

it('hides each secret in what a kill\'s process printed, its code, signal and prefix kept', function () use ($mutants, $first): void {
    $evidence = Evidences::none()->with(
        $first->id(),
        Evidence::none()->withPrefix(Prefix::at(1))->withEnded(Ended::of(255, signalled: false, printed: 'key hunter2hunter2 leaked')),
    );
    $hidden = Hidden::in($evidence, $mutants, Secrets::of('hunter2hunter2'))->of($first->id());
    $ended = $hidden->ended();

    expect($ended instanceof Ended ? [$ended->code(), $ended->signalled(), $ended->tail()] : $ended)
        ->toBe([255, false, 'key *** leaked'])
        ->and($hidden->prefix())->toEqual(Prefix::at(1));
});

it('hides a secret a long print would cut at its edge, before the cut', function () use ($mutants, $first): void {
    $secret = str_repeat('s', 40);
    $evidence = Evidences::none()->with(
        $first->id(),
        Evidence::none()->withEnded(Ended::of(1, signalled: false, printed: sprintf('%s%s%s', $secret, str_repeat('y', 2040), 'end'))),
    );
    $ended = Hidden::in($evidence, $mutants, Secrets::of($secret))->of($first->id())->ended();

    expect($ended instanceof Ended ? str_contains($ended->tail(), 's') : $ended)->toBeFalse();
});

it('keeps a prefix alone as it was, and the evidence of no mutant the shard does not hold or does not end killed', function () use ($first, $second): void {
    $evidence = Evidences::none()
        ->with($first->id(), Evidence::none()->withPrefix(Prefix::at(4)))
        ->with($second->id(), Evidence::none()->withPrefix(Prefix::at(2)));

    expect(Hidden::in($evidence, Mutants::of($first), Secrets::none())->of($first->id()))->toEqual(Evidence::none()->withPrefix(Prefix::at(4)))
        ->and(Hidden::in($evidence, Mutants::of($first), Secrets::none()))->toHaveCount(1)
        ->and(Hidden::in($evidence, Mutants::of($first, $second), Secrets::none()))->toHaveCount(1);
});
