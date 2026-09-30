<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\Mixed\MixedInDocBlock;

it('finds mixed wherever a doc block writes it in a type', function (string $docBlock): void {
    expect(MixedInDocBlock::in($docBlock))->toBeTrue();
})->with([
    'a parameter' => ['/** @param mixed $value */'],
    'a return' => ['/** @return mixed */'],
    'a variable' => ['/** @var mixed $value */'],
    'a generic argument' => ['/** @return array<string, mixed> */'],
    'a list' => ['/** @param list<mixed> $values */'],
    'a closure return' => ['/** @param Closure(int): mixed $read */'],
    'a closure parameter' => ['/** @param callable(mixed): bool $keep */'],
    'a shape field' => ['/** @return array{format: int, body: mixed} */'],
    'a union' => ['/** @param string|mixed $value */'],
    'however it is cased' => ['/** @param Mixed $value */'],
    'among other tags' => ["/**\n * Reads it.\n *\n * @param string \$at\n * @return array<mixed>\n */"],
]);

it('finds no mixed where a doc block types everything, or only says the word', function (string $docBlock): void {
    expect(MixedInDocBlock::in($docBlock))->toBeFalse();
})->with([
    'a typed parameter' => ['/** @param list<string> $values */'],
    'a typed return' => ['/** @return array<string, int> */'],
    'the word in prose' => ['/** A mixed bag of settings, read as they come. */'],
    'the word in a description' => ['/** @param string $value a mixed-case name */'],
]);
