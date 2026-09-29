<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
use NightWorksIO\MutationGate\Core\Config\Definition\MisreadSetting;
use NightWorksIO\MutationGate\Core\File\Path;

it('refuses to read a setting as a type its definition does not make', function (Closure $read, string $message): void {
    expect($read)->toThrow(MisreadSetting::class, $message);
})->with([
    'an integer' => [
        static fn(): int => new Fields(['a' => '1'])->int('a'),
        'The setting "a" was read as an integer, which its definition does not make.',
    ],
    'a number' => [static fn(): float => new Fields(['a' => 1])->float('a'), 'read as a number'],
    'a boolean' => [static fn(): bool => new Fields(['a' => 1])->bool('a'), 'read as a boolean'],
    'a string' => [static fn(): string => new Fields(['a' => 1])->string('a'), 'read as a string'],
    'an object' => [static fn(): object => new Fields(['a' => 'src'])->object('a', Path::class), Path::class],
    'an optional object' => [
        static fn(): object => new Fields(['a' => 'src'])->optional('a', Path::class),
        Path::class,
    ],
    'objects' => [static fn(): array => new Fields(['a' => ['src']])->objects('a', Path::class), Path::class],
    'strings' => [static fn(): array => new Fields(['a' => [1]])->strings('a'), 'read as a string'],
    'a list' => [static fn(): array => new Fields(['a' => 'src'])->strings('a'), 'read as a list'],
    'the settings of an object' => [static fn(): Fields => new Fields(['a' => []])->fields('a'), Fields::class],
]);

it('reads each setting as its definition makes it', function (): void {
    $fields = new Fields([
        'int' => 1,
        'float' => 1.5,
        'bool' => false,
        'string' => 'text',
        'path' => Path::of('src'),
        'absent' => Absent::setting(),
        'paths' => [Path::of('a'), Path::of('b')],
        'strings' => ['a', 'b'],
    ]);

    expect($fields->int('int'))->toBe(1)
        ->and($fields->float('float'))->toBe(1.5)
        ->and($fields->bool('bool'))->toBeFalse()
        ->and($fields->string('string'))->toBe('text')
        ->and($fields->object('path', Path::class))->toEqual(Path::of('src'))
        ->and($fields->optional('path', Path::class))->toEqual(Path::of('src'))
        ->and($fields->optional('absent', Path::class))->toEqual(Absent::setting())
        ->and($fields->objects('paths', Path::class))->toEqual([Path::of('a'), Path::of('b')])
        ->and($fields->strings('strings'))->toBe(['a', 'b'])
        ->and($fields->has('int'))->toBeTrue()
        ->and($fields->has('absent'))->toBeFalse();
});
