<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\OneHome\Written;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\NullType;

it('writes a value the same way for every constant that holds it', function (): void {
    expect(Written::of(new ConstantStringType('.php')))->toBe("'.php'")
        ->and(Written::of(new ConstantIntegerType(20)))->toBe('20')
        ->and(Written::of(new ConstantFloatType(0.2)))->toBe('0.2');
});

it('finds too plain to have a home: nothing, zero, one, a boolean, a single character, an empty array', function (string $written): void {
    expect(Written::isPlain($written))->toBeTrue();
})->with([
    'nothing' => [fn(): string => Written::of(new ConstantStringType(''))],
    'zero' => [fn(): string => Written::of(new ConstantIntegerType(0))],
    'one' => [fn(): string => Written::of(new ConstantIntegerType(1))],
    'a whole float one' => [fn(): string => Written::of(new ConstantFloatType(1.0))],
    'true' => [fn(): string => Written::of(new ConstantBooleanType(value: true))],
    'false' => [fn(): string => Written::of(new ConstantBooleanType(value: false))],
    'null' => [fn(): string => Written::of(new NullType())],
    'a single character' => [fn(): string => Written::of(new ConstantStringType('/'))],
    'an empty array' => ['array{}'],
]);

it('finds a value a reader would look up worth one home', function (string $written): void {
    expect(Written::isPlain($written))->toBeFalse();
})->with([
    'two characters' => [fn(): string => Written::of(new ConstantStringType('ok'))],
    'a name' => [fn(): string => Written::of(new ConstantStringType('composer.json'))],
    'a number' => [fn(): string => Written::of(new ConstantIntegerType(20))],
]);
