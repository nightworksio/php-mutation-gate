<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

it('rejects a mutant by an error its original does not have', function (): void {
    $original = Findings::of(Finding::error('return.type', 'Method add() should return int but returns string.'));

    expect(Findings::of(Finding::error('binaryOp.invalid', 'Binary operation "." between int and int.'))
        ->rejects($original))->toBeTrue()
        ->and(Findings::of(Finding::error('return.type', 'Method sub() should return int but returns string.'))
            ->rejects($original))->toBeTrue();
});

it('leaves a mutant whose errors its original has, by code and message', function (): void {
    $known = Finding::error('return.type', 'Method add() should return int but returns string.');

    expect(Findings::of($known)->rejects(Findings::of($known)))->toBeFalse()
        ->and(Findings::of($known)->rejects(Findings::of(
            Finding::lesser('return.type', 'Method add() should return int but returns string.'),
        )))->toBeFalse()
        ->and(Findings::none()->rejects(Findings::of($known)))->toBeFalse();
});

it('leaves a mutant for what is below an error, however new', function (): void {
    expect(Findings::of(Finding::lesser('deadCode.unreachable', 'Unreachable statement.'))->rejects(Findings::none()))
        ->toBeFalse();
});

it('keeps each finding as the analyser gave it, in its order', function (): void {
    $findings = Findings::of(Finding::error('a.b', 'First.'), Finding::lesser('c.d', 'Second.'));

    expect($findings)->toHaveCount(2)
        ->and(array_map(
            static fn(Finding $finding): array => [$finding->code(), $finding->message(), $finding->isError()],
            [...$findings],
        ))->toBe([['a.b', 'First.', true], ['c.d', 'Second.', false]]);
});

it('names an analyser by its name, its version and its config\'s digest', function (): void {
    $identity = AnalyserIdentity::of('phpstan', '2.2.16', Digest::sha256Of('includes: []'));

    expect($identity->analyser())->toBe('phpstan')
        ->and($identity->version())->toBe('2.2.16')
        ->and($identity->config())->toEqual(Digest::sha256Of('includes: []'));
});

it('asks about a mutant alone, without what every run withholds, unless told more', function (): void {
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'));
    $told = $check->withDependents(Paths::of(Path::of('src/Wallet.php')))->withholding(Withheld::of('DEPLOY_*'));

    expect([$check->original(), $check->mutant(), $check->dependents(), $check->withheld()])
        ->toEqual([Path::of('src/Money.php'), Path::of('/tmp/mutant.php'), Paths::none(), Withheld::standard()])
        ->and([$told->original(), $told->mutant(), $told->dependents(), $told->withheld()])->toEqual([
            Path::of('src/Money.php'),
            Path::of('/tmp/mutant.php'),
            Paths::of(Path::of('src/Wallet.php')),
            Withheld::of('DEPLOY_*'),
        ]);
});
