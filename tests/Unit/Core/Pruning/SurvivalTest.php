<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Outcome;
use NightWorksIO\MutationGate\Core\Pruning\Survival;
use NightWorksIO\MutationGate\Core\Pruning\Window;
use NightWorksIO\MutationGate\Tests\Support\PruningCases;

it('prunes the mutators of a runner whose window is full and clean, less those never pruned', function (): void {
    $pest = Name::of('pest');
    $survival = Survival::none()->after(
        $pest,
        Window::of(10),
        Outcome::killed('Plus', 'a'),
        Outcome::killed('Minus', 'c'),
        Outcome::killed('Plus', 'b'),
        Outcome::through('Minus', 'd'),
        Outcome::killed('Short', 'e'),
        Outcome::killed('Secure', 'f'),
        Outcome::killed('Secure', 'g'),
    );

    expect([...$survival->pruned($pest, Window::of(2), MutatorNames::of('Secure'))])->toBe(['Plus'])
        ->and([...$survival->pruned(Name::of('infection'), Window::of(2), MutatorNames::none())])->toBe([])
        ->and($survival->of($pest, RunnerMutatorName::of('Minus'))->last())->toBe('d')
        ->and($survival->of($pest, RunnerMutatorName::of('Minus'))->outcomes())->toBe('01');
});

it('learns after what it held, keeping each window to the newest it is told to', function (): void {
    $pest = Name::of('pest');
    $survival = Survival::none()
        ->after($pest, Window::of(2), Outcome::through('Plus', 'a'))
        ->after($pest, Window::of(2), Outcome::killed('Plus', 'b'), Outcome::killed('Plus', 'c'));

    expect($survival->of($pest, RunnerMutatorName::of('Plus'))->outcomes())->toBe('00')
        ->and($survival->of($pest, RunnerMutatorName::of('Plus'))->last())->toBe('a')
        ->and([...$survival->pruned($pest, Window::of(2), MutatorNames::none())])->toBe(['Plus']);
});

it('reads its own windows before another ledger\'s, taking the other\'s only for mutators it never learned of', function (): void {
    $pest = Name::of('pest');
    $own = Survival::none()->with($pest, PruningCases::window('Plus', '1', 'mine'));
    $other = Survival::none()
        ->with($pest, PruningCases::window('Plus', '0'))
        ->with($pest, PruningCases::window('Minus', '0'))
        ->with(Name::of('infection'), PruningCases::window('Plus', '0'));

    $read = $own->and($other);

    expect($read->of($pest, RunnerMutatorName::of('Plus'))->last())->toBe('mine')
        ->and($read->of($pest, RunnerMutatorName::of('Minus'))->outcomes())->toBe('0')
        ->and($read->of(Name::of('infection'), RunnerMutatorName::of('Plus'))->outcomes())->toBe('0')
        ->and($read->of(Name::of('phpunit'), RunnerMutatorName::of('Plus'))->outcomes())->toBe('');
});

it('lists mutator names sorted and once each', function (): void {
    $names = MutatorNames::of('Plus', 'Minus', 'Plus');

    expect([...$names])->toBe(['Minus', 'Plus'])
        ->and(count($names))->toBe(2)
        ->and($names->has(RunnerMutatorName::of('Plus')))->toBeTrue()
        ->and($names->has(RunnerMutatorName::of('Times')))->toBeFalse();
});
