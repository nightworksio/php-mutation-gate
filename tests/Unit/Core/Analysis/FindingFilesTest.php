<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

it('spells a file under the root as the project does, normalised', function (string $named, string $file): void {
    expect(FindingFiles::under(Root::of('/p'))->of($named))->toEqual(Path::of($file));
})->with([
    'absolute' => ['/p/src/Money.php', 'src/Money.php'],
    'with dots and doubled separators' => ['/p/src/./Shop//Cart/../Money.php', 'src/Shop/Money.php'],
    'already relative' => ['src/Money.php', 'src/Money.php'],
    'outside the root' => ['/elsewhere/lib/../Money.php', '/elsewhere/Money.php'],
    'beside the root, sharing its name\'s start' => ['/project/src/Money.php', '/project/src/Money.php'],
]);

it('names a finding in the mutant as one in the original it stands in for, however the mutant is spelt', function (string $mutant, string $named): void {
    $files = FindingFiles::under(Root::of('/p'))->substituting(MutantCheck::of(Path::of('src/Money.php'), Path::of($mutant)));

    expect($files->of($named))->toEqual(Path::of('src/Money.php'))
        ->and($files->of('/p/src/Wallet.php'))->toEqual(Path::of('src/Wallet.php'));
})->with([
    'under the root' => ['.mutation-gate/staticcheck/mutants/abc.php', '/p/.mutation-gate/staticcheck/mutants/abc.php'],
    'absolute, outside it' => ['/tmp/mutant.php', '/tmp/mutant.php'],
    'the original, as PHPStan names it' => ['.mutation-gate/staticcheck/mutants/abc.php', '/p/src/Money.php'],
]);

it('names the mutant\'s file as itself in a run over the originals, which substitutes nothing', function (): void {
    expect(FindingFiles::under(Root::of('/p'))->of('/p/.mutation-gate/staticcheck/mutants/abc.php'))
        ->toEqual(Path::of('.mutation-gate/staticcheck/mutants/abc.php'));
});

it('keeps every mutant it was told of, each named as its own original', function (): void {
    $files = FindingFiles::under(Root::of('/p'))
        ->substituting(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/money.php')))
        ->substituting(MutantCheck::of(Path::of('src/Wallet.php'), Path::of('/tmp/wallet.php')));

    expect($files->of('/tmp/money.php'))->toEqual(Path::of('src/Money.php'))
        ->and($files->of('/tmp/wallet.php'))->toEqual(Path::of('src/Wallet.php'));
});
