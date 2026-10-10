<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Turbo\Answer;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Request;
use NightWorksIO\MutationGate\Core\Turbo\Sample;
use NightWorksIO\MutationGate\Core\Turbo\UnitKeysAsked;

$asked = static fn(): UnitKeysAsked => UnitKeysAsked::of(
    Digest::of('b4'),
    [
        [Path::of('src/Money.php'), '', Digest::of('r1'), [[3, 's1'], [7, 's2'], [9, 's1']]],
        [Path::of('src/Limit.php'), 'holds:src/Limit.php', Digest::of('r2'), []],
    ],
    ['s1' => ['b::t', 'a::t'], 's2' => ['a::t']],
);

$key = static fn(string $seed): string => hash('sha256', $seed);

it('writes every test id once, each set by place, and each unit\'s lines naming their set by place', function () use ($asked): void {
    $request = $asked()->request();

    expect($request)->toBeInstanceOf(Request::class)
        ->and(json_decode($request instanceof Request ? $request->text() : '', associative: true))->toBe([
            'protocol' => 1,
            'kind' => 'unit-keys',
            'format' => ContentKeys::FORMAT,
            'base' => 'b4',
            'tests' => ['b::t', 'a::t'],
            'sets' => [[0, 1], [1]],
            'units' => [
                ['path' => 'src/Money.php', 'judgedBy' => '', 'read' => 'r1', 'lines' => [[3, 0], [7, 1], [9, 0]]],
                ['path' => 'src/Limit.php', 'judgedBy' => 'holds:src/Limit.php', 'read' => 'r2', 'lines' => []],
            ],
        ]);
});

it('writes no request where a covered line names a set it was not given', function (): void {
    $asked = UnitKeysAsked::of(
        Digest::of('b'),
        [[Path::of('src/A.php'), '', Digest::of('r'), [[1, 's']]], [Path::of('src/B.php'), '', Digest::of('r'), [[1, 'gone']]]],
        ['s' => ['a::t']],
    );

    expect($asked->request())->toEqual(NotAccelerated::because('A covered line of src/B.php names a set of tests the request was not given.'));
});

it('writes no request where a test id is not UTF-8, which JSON cannot carry', function (): void {
    $asked = UnitKeysAsked::of(Digest::of('b'), [], ['s' => ["\xff::t"]]);

    expect($asked->request())->toEqual(NotAccelerated::because(
        'The paths or test ids are not all UTF-8, which the helper\'s JSON cannot carry.',
    ));
});

it('reads each unit\'s key from the answer, in the order asked, and passes on why there is none', function () use ($asked, $key): void {
    $answer = Answer::ofText((string) json_encode(['keys' => [$key('a'), $key('b')], 'protocol' => 1]));

    expect($asked()->keysIn($answer))->toEqual(
        Keys::none()->with(Path::of('src/Money.php'), Digest::of($key('a')))->with(Path::of('src/Limit.php'), Digest::of($key('b'))),
    )
        ->and($asked()->keysIn(NotAccelerated::because('turned off')))->toEqual(NotAccelerated::because('turned off'));
});

it('samples the units a run checks in PHP', function () use ($asked): void {
    expect($asked()->sample())->toEqual(Sample::of('b4', [Path::of('src/Money.php'), Path::of('src/Limit.php')]));
});
