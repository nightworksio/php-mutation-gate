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
    $original = Findings::of(Finding::error(Path::of('src/Money.php'), 'return.type', 'Method add() should return int but returns string.'));

    expect(Findings::of(Finding::error(Path::of('src/Money.php'), 'binaryOp.invalid', 'Binary operation "." between int and int.'))
        ->rejects($original))->toBeTrue()
        ->and(Findings::of(Finding::error(Path::of('src/Money.php'), 'return.type', 'Method sub() should return int but returns string.'))
            ->rejects($original))->toBeTrue();
});

it('leaves a mutant whose errors its original has, by code and message, in whatever file', function (): void {
    $known = Finding::error(Path::of('src/Money.php'), 'return.type', 'Method add() should return int but returns string.');
    $elsewhere = Finding::error(Path::of('src/Wallet.php'), 'return.type', 'Method add() should return int but returns string.');

    expect(Findings::of($known)->rejects(Findings::of($known)))->toBeFalse()
        ->and(Findings::of($elsewhere)->rejects(Findings::of($known)))->toBeFalse()
        ->and(Findings::of($known)->rejects(Findings::of(
            Finding::lesser(Path::of('src/Money.php'), 'return.type', 'Method add() should return int but returns string.'),
        )))->toBeFalse()
        ->and(Findings::none()->rejects(Findings::of($known)))->toBeFalse();
});

it('leaves a mutant for what is below an error, however new', function (): void {
    expect(Findings::of(Finding::lesser(Path::of('src/Money.php'), 'deadCode.unreachable', 'Unreachable statement.'))->rejects(Findings::none()))
        ->toBeFalse();
});

it('keeps each finding as the analyser gave it, in its file and its order', function (): void {
    $findings = Findings::of(Finding::error(Path::of('src/Money.php'), 'a.b', 'First.'), Finding::lesser(Path::of('src/Wallet.php'), 'c.d', 'Second.'));

    expect($findings)->toHaveCount(2)
        ->and(array_map(
            static fn(Finding $finding): array => [$finding->file()->value(), $finding->code(), $finding->message(), $finding->isError()],
            [...$findings],
        ))->toBe([['src/Money.php', 'a.b', 'First.', true], ['src/Wallet.php', 'c.d', 'Second.', false]]);
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

it('names the new errors a mutant has, in its order, and none that its original has or that are lesser', function (): void {
    $known = Finding::error(Path::of('src/Money.php'), 'return.type', 'Method add() should return int but returns string.');
    $first = Finding::error(Path::of('src/Money.php'), 'binaryOp.invalid', 'Binary operation "." between int and int.');
    $second = Finding::error(Path::of('src/Money.php'), 'argument.type', 'Parameter #1 expects int, string given.');
    $mutant = Findings::of($known, $first, Finding::lesser(Path::of('src/Money.php'), 'deadCode.unreachable', 'Unreachable statement.'), $second);

    expect([...$mutant->newErrors(Findings::of($known))])->toBe([$first, $second])
        ->and([...Findings::of($known)->newErrors(Findings::of($known))])->toBe([]);
});

it('knows the same findings, as many of each and whatever their order, by code and message', function (): void {
    $a = Finding::error(Path::of('src/Money.php'), 'a.b', 'First.');
    $b = Finding::lesser(Path::of('src/Money.php'), 'c.d', 'Second.');

    expect(Findings::of($a, $b)->same(Findings::of($b, Finding::lesser(Path::of('src/Money.php'), 'a.b', 'First.'))))->toBeTrue()
        ->and(Findings::none()->same(Findings::none()))->toBeTrue()
        ->and(Findings::of($a, $b)->same(Findings::of($a)))->toBeFalse()
        ->and(Findings::of($a, $a)->same(Findings::of($a, $b)))->toBeFalse()
        ->and(Findings::of($a, $a, $b)->same(Findings::of($a, $b)))->toBeFalse()
        ->and(Findings::of($a, $b)->same(Findings::of($b, $a, $a)))->toBeFalse()
        ->and(Findings::of($a)->same(Findings::of(Finding::error(Path::of('src/Money.php'), 'a.b', 'Other.'))))->toBeFalse()
        ->and(Findings::of($a)->same(Findings::of(Finding::error(Path::of('src/Money.php'), 'a.c', 'First.'))))->toBeFalse();
});
