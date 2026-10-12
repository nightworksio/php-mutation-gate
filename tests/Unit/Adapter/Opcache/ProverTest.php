<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Opcodes;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Tests\Support\ProverPrograms;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('proves none where there is nothing to check', function (): void {
    expect(Prover::of(PHP_BINARY, Root::of(Scratch::directory())->at(Path::of('equivalence')), ProcessCount::of(1), disabled: false)->proven([]))
        ->toBe([]);
});

it('proves no mutant whose literal reads as the name it gives the program\'s own path', function (
    string $original,
    string $mutant,
): void {
    $prover = Prover::of(PHP_BINARY, Root::of(Scratch::directory())->at(Path::of('equivalence')), ProcessCount::of(1), disabled: false);

    expect($prover->proven([['forged', Contents::of($original), Contents::of($mutant)]]))->toBe([]);
})->with([
    'its file, as plain text named it' => [
        "<?php\n\nfunction ownFile(): string\n{\n    return __FILE__;\n}\n",
        "<?php\n\nfunction ownFile(): string\n{\n    return '<file>';\n}\n",
    ],
    'its directory, as plain text named it' => [
        "<?php\n\nfunction ownDirectory(): string\n{\n    return __DIR__;\n}\n",
        "<?php\n\nfunction ownDirectory(): string\n{\n    return '<directory>';\n}\n",
    ],
    'its file, as it is named' => [
        "<?php\n\nfunction ownFile(): string\n{\n    return __FILE__;\n}\n",
        fn(): string => sprintf("<?php\n\nfunction ownFile(): string\n{\n    return \"%s\";\n}\n", addcslashes(Opcodes::FILE, "\0..\37")),
    ],
    'its directory, as it is named' => [
        "<?php\n\nfunction ownDirectory(): string\n{\n    return __DIR__;\n}\n",
        fn(): string => sprintf("<?php\n\nfunction ownDirectory(): string\n{\n    return \"%s\";\n}\n", addcslashes(Opcodes::DIRECTORY, "\0..\37")),
    ],
]);

it('proves no mutant whose code stands on other lines than its original\'s, since a program can read its lines', function (
    string $original,
    string $mutant,
): void {
    $prover = Prover::of(PHP_BINARY, Root::of(Scratch::directory())->at(Path::of('equivalence')), ProcessCount::of(1), disabled: false);

    expect($prover->proven([['moved', Contents::of($original), Contents::of($mutant)]]))->toBe([]);
})->with([
    'a method that ends a line later, as what it throws does' => [
        ProverPrograms::PROVED,
        fn(): string => str_replace('return $a * 2;', "return \$a\n            * 2;", ProverPrograms::PROVED),
    ],
    'a closure a line further down, whose name holds its line' => [
        "<?php\n\nfunction made(): Closure\n{\n    \$made = fn (): int => 1;\n\n    return \$made;\n}\n",
        "<?php\n\nfunction made(): Closure\n{\n\n    \$made = fn (): int => 1;\n    return \$made;\n}\n",
    ],
]);
