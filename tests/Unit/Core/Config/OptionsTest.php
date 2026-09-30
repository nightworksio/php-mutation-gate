<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$options = static fn(): Options => Configs::options(
    '{"channel": "#ci", "loud": true, "workers": 4, "share": 0.5, "tests": ["tests/", "spec"], '
    . '"names": ["a", "b"], "colors": {"green": 90}, "empty": [], "none": {}}',
);

it('answers each option as the type it holds', function () use ($options): void {
    $read = $options();

    expect($read->text(Key::of('channel')))->toBe('#ci')
        ->and($read->flag(Key::of('loud')))->toBeTrue()
        ->and($read->integer(Key::of('workers')))->toBe(4)
        ->and($read->number(Key::of('share')))->toBe(0.5)
        ->and($read->number(Key::of('workers')))->toBe(4.0)
        ->and($read->paths(Key::of('tests')))->toEqual(Paths::of(Path::of('tests'), Path::of('spec')))
        ->and($read->texts(Key::of('names')))->toEqual(Listed::of('a', 'b'))
        ->and($read->paths(Key::of('empty')))->toEqual(Paths::none())
        ->and($read->texts(Key::of('empty')))->toEqual(Listed::of());
});

/** Each way to ask for an option, by what it asks for. */
$asks = [
    'text' => static fn(Options $options, Key $key): object|string => $options->text($key),
    'flag' => static fn(Options $options, Key $key): object|bool => $options->flag($key),
    'integer' => static fn(Options $options, Key $key): object|int => $options->integer($key),
    'number' => static fn(Options $options, Key $key): object|float => $options->number($key),
    'paths' => static fn(Options $options, Key $key): object => $options->paths($key),
    'texts' => static fn(Options $options, Key $key): object => $options->texts($key),
    'object' => static fn(Options $options, Key $key): object => $options->object($key),
];

it('answers not given for an option left out', function (string $ask) use ($options, $asks): void {
    expect($asks[$ask]($options(), Key::of('missing')))->toEqual(NotGiven::value());
})->with(array_keys($asks));

it('answers the problem at its path for an option that holds something else', function (
    string $ask,
    string $key,
    string $message,
) use ($options, $asks): void {
    expect($asks[$ask]($options(), Key::of($key)))->toEqual(Problem::at($key, $message));
})->with([
    'text' => ['text', 'workers', 'expected text, got 4'],
    'flag' => ['flag', 'channel', 'expected true or false, got "#ci"'],
    'a whole number' => ['integer', 'share', 'expected a whole number, got 0.5'],
    'a number' => ['number', 'loud', 'expected a number, got true'],
    'paths' => ['paths', 'channel', 'expected a list of paths, got "#ci"'],
    'texts' => ['texts', 'colors', 'expected a list of text, got an object'],
]);

it('names an item of a list that is not text at its index', function (): void {
    $read = Configs::options('{"tests": ["tests", 3]}');

    expect($read->paths(Key::of('tests')))->toEqual(Problem::at('tests[1]', 'expected text, got 3'))
        ->and($read->texts(Key::of('tests')))->toEqual(Problem::at('tests[1]', 'expected text, got 3'));
});

it('reads the options under a key, each named under it', function () use ($options): void {
    $colors = $options()->object(Key::of('colors'));
    $none = $options()->object(Key::of('none'));

    expect($colors instanceof Options ? $colors->number(Key::of('green')) : $colors)->toBe(90.0)
        ->and($colors instanceof Options ? $colors->text(Key::of('green')) : $colors)
        ->toEqual(Problem::at('colors.green', 'expected text, got 90'))
        ->and($colors instanceof Options ? [...$colors->problems()] : $colors)->toBe([])
        ->and($none instanceof Options ? $none->written()->line() : $none)->toBe('{}');
});

it('holds the problem of options under a key that is not an object', function () use ($options): void {
    $under = $options()->object(Key::of('channel'));

    expect($under instanceof Options ? [...$under->problems()] : $under)
        ->toEqual([Problem::at('channel', 'expected an object, got "#ci"')])
        ->and($under instanceof Options ? $under->written() : $under)->toEqual(Json::object());
});

it('refuses options that are not an object, at their own path', function (): void {
    expect([...Options::of(Json::items('a'))->problems()])->toEqual([Problem::at('', 'expected an object, got a list')]);
});

it('keys each option in the order it is written, and writes them as they are', function (): void {
    $read = Configs::options('{"b": 1, "a": {"c": 2}}');

    expect(array_map(static fn(Key $key): string => $key->value(), [...$read]))->toBe(['b', 'a'])
        ->and($read->written()->line())->toBe('{"b":1,"a":{"c":2}}')
        ->and(Options::none()->written()->line())->toBe('{}');
});
