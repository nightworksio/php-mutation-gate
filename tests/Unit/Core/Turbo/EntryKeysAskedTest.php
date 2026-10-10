<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Turbo\Answer;
use NightWorksIO\MutationGate\Core\Turbo\EntryKeysAsked;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Request;
use NightWorksIO\MutationGate\Core\Turbo\Sample;

$paths = static fn(string ...$paths): Paths => Paths::of(...array_map(Path::of(...), $paths));

$asked = static fn(): EntryKeysAsked => EntryKeysAsked::of(
    Digest::of('b4'),
    ['src/Money.php' => ['src/Currency.php'], 'src/Currency.php' => ['src/Money.php']],
    Fingerprints::of(
        Fingerprint::of(Path::of('src/Money.php'), Digest::of('m')),
        Fingerprint::of(Path::of('src/Currency.php'), Digest::of('c')),
        Fingerprint::of(Path::of('tests/MoneyTest.php'), Digest::of('t')),
    ),
    $paths('tests/Pest.php'),
    [
        [Path::of('tests/MoneyTest.php'), $paths('src/Money.php')],
        [Path::of('tests/OtherTest.php'), $paths()],
    ],
);

$key = static fn(string $seed): string => hash('sha256', $seed);

it('writes every path once with its digest, the graph and each entry\'s start by place', function () use ($asked): void {
    $request = $asked()->request();

    expect($request)->toBeInstanceOf(Request::class)
        ->and(json_decode($request instanceof Request ? $request->text() : '', associative: true))->toBe([
            'protocol' => 1,
            'kind' => 'entry-keys',
            'format' => 'mutation-gate coverage entry 1',
            'base' => 'b4',
            'paths' => ['src/Money.php', 'src/Currency.php', 'tests/Pest.php', 'tests/MoneyTest.php', 'tests/OtherTest.php'],
            'digests' => ['m', 'c', 'missing', 't', 'missing'],
            'edges' => [[1], [0], [], [], []],
            'always' => [2],
            'entries' => [[3, 0], [4]],
        ])
        ->and($asked()->testFiles())->toEqual(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/OtherTest.php')));
});

it('writes no request where a path is not UTF-8, which JSON cannot carry', function () use ($paths): void {
    $asked = EntryKeysAsked::of(Digest::of('b4'), [], Fingerprints::none(), $paths("src/\xff.php"), []);

    expect($asked->request())->toEqual(NotAccelerated::because(
        'The paths or digests are not all UTF-8, which the helper\'s JSON cannot carry.',
    ));
});

it('reads each test file\'s key from the answer, in the order asked', function () use ($asked, $key): void {
    $answer = Answer::ofText(sprintf("%s\n", json_encode(['keys' => [$key('a'), $key('b')], 'protocol' => 1])));

    expect($asked()->keysIn($answer))->toEqual(
        EntryKeys::none()
            ->with(Path::of('tests/MoneyTest.php'), Digest::of($key('a')))
            ->with(Path::of('tests/OtherTest.php'), Digest::of($key('b'))),
    );
});

it('passes on why there is no answer or it is not read', function () use ($asked): void {
    expect($asked()->keysIn(NotAccelerated::because('turned off')))->toEqual(NotAccelerated::because('turned off'))
        ->and($asked()->keysIn(Answer::ofText('{"protocol":1,"keys":[]}')))->toBeInstanceOf(NotAccelerated::class);
});

it('samples the test files a run checks in PHP', function () use ($asked): void {
    expect($asked()->sample())->toEqual(Sample::of('b4', [Path::of('tests/MoneyTest.php'), Path::of('tests/OtherTest.php')]));
});
