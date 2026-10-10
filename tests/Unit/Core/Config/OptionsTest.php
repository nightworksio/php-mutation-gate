<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
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
    'path' => static fn(Options $options, Key $key): object => $options->path($key),
    'paths' => static fn(Options $options, Key $key): object => $options->paths($key),
    'texts' => static fn(Options $options, Key $key): object => $options->texts($key),
    'object' => static fn(Options $options, Key $key): object => $options->object($key),
];

it('answers not given for an option left out', function () use ($options, $asks): void {
    foreach (array_keys($asks) as $ask) {
        expect($asks[$ask]($options(), Key::of('missing')))->toEqual(NotGiven::value());
    }
});

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
    'a path' => ['path', 'workers', 'expected a path, got 4'],
    'paths' => ['paths', 'channel', 'expected a list of paths, got "#ci"'],
    'texts' => ['texts', 'colors', 'expected a list of text, got an object'],
    'an object' => ['object', 'channel', 'expected an object, got "#ci"'],
]);

it('names an item of a list that is not text at its index', function (): void {
    $read = Configs::options('{"tests": ["tests", 3]}');

    expect($read->paths(Key::of('tests')))->toEqual(Problem::at('tests[1]', 'expected a path, got 3'))
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

it('names each path from where the layer that writes the options is', function (): void {
    $ci = ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project'));
    $read = Options::at(Configs::options('{"cache": "../cache", "tests": ["tests", "../spec"], "under": {"a": "b"}}')->written(), $ci);
    $under = $read->object(Key::of('under'));

    expect($read->path(Key::of('cache')))->toEqual(Path::of('cache'))
        ->and($read->paths(Key::of('tests')))->toEqual(Paths::of(Path::of('ci/tests'), Path::of('spec')))
        ->and($under instanceof Options ? $under->path(Key::of('a')) : $under)->toEqual(Path::of('ci/b'))
        ->and(Configs::options('{"cache": "./cache/"}')->path(Key::of('cache')))->toEqual(Path::of('cache'));
});

it('refuses a path that lands outside the project, but one the command line names by its absolute path', function (
    PathOrigin $origin,
    string $written,
    Path|Problem $read,
): void {
    $options = Options::at(Configs::options(sprintf('{"cache": %1$s, "tests": [%1$s]}', $written))->written(), $origin);

    expect($options->path(Key::of('cache')))->toEqual($read)
        ->and($options->paths(Key::of('tests')))->toEqual($read instanceof Path ? Paths::of($read) : Problem::at(
            'tests[0]',
            $read->message(),
        ));
})->with([
    'up, from a file' => [
        fn(): ConfigFile => ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project')),
        '"../../x"',
        fn(): Problem => Problem::at('cache', 'expected a path inside the project, got "../../x"'),
    ],
    'absolute, from a file' => [
        fn(): ConfigFile => ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project')),
        '"/tmp/x"',
        fn(): Problem => Problem::at('cache', 'expected a path inside the project, got "/tmp/x"'),
    ],
    'absolute, from a preset' => [
        fn(): ProjectRoot => ProjectRoot::origin(),
        '"/tmp/x"',
        fn(): Problem => Problem::at('cache', 'expected a path inside the project, got "/tmp/x"'),
    ],
    'absolute, from the command line' => [fn(): ProjectRoot => ProjectRoot::commandLine(), '"/tmp/x"', fn(): Path => Path::of('/tmp/x')],
]);

it('lays options beneath its own, keeping where they are written', function (): void {
    $ci = ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project'));
    $laid = Options::at(Configs::options('{"cache": "../cache"}')->written(), $ci)->over(Configs::options('{"cache": "x", "level": 3}')->written());

    expect($laid->written()->line())->toBe('{"cache":"../cache","level":3}')
        ->and($laid->path(Key::of('cache')))->toEqual(Path::of('cache'));
});

it('lays a path from the project beneath its own, spelt as the layer that writes them spells it', function (): void {
    $ci = ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project'));
    $laid = Options::at(Configs::options('{"level": 3}')->written(), $ci)->overPath(Key::of('path'), Path::of('build/x'));
    $kept = Options::at(Configs::options('{"path": "out"}')->written(), $ci)->overPath(Key::of('path'), Path::of('build/x'));

    expect($laid->written()->line())->toBe('{"path":"../build/x","level":3}')
        ->and($laid->path(Key::of('path')))->toEqual(Path::of('build/x'))
        ->and($kept->path(Key::of('path')))->toEqual(Path::of('ci/out'));
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
