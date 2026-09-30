<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Canonical;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

$affects = Effect::AffectsResults;
$judges = Effect::JudgesOrReportsOnly;

it('writes every object\'s keys in byte order', function () use ($affects): void {
    $written = Json::object(Member::of('b', 1), Member::of('a', 2), Member::of('B', 3));

    expect(Canonical::of(Json::object(Member::of('x', $written)), ['x' => $affects]))->toBe('{"x":{"B":3,"a":2,"b":1}}');
});

it('names an adapter chosen by an object that holds only its name by its name', function () use ($affects): void {
    $named = Json::object(Member::of('runner', Json::object(Member::of('use', 'pest'))));
    $withOptions = Json::object(
        Member::of('runner', Json::object(Member::of('use', 'pest'))->with(Member::of('with', Json::object(Member::of('a', 1))))),
    );

    expect(Canonical::of($named, ['runner' => $affects]))->toBe('{"runner":"pest"}')
        ->and(Canonical::of($withOptions, ['runner' => $affects]))->toBe('{"runner":{"use":"pest","with":{"a":1}}}');
});

it('drops a setting that only judges, inside one that affects results and beside it', function () use (
    $affects,
    $judges,
): void {
    $written = Json::object(Member::of('trees', Json::items(
        Json::object(Member::of('path', 'src'))->with(Member::of('reason', 'generated')),
    )))->with(Member::of('budget', '1h'));

    expect(Canonical::of($written, ['trees' => $affects, 'trees[].reason' => $judges, 'budget' => $judges]))
        ->toBe('{"trees":[{"path":"src"}]}');
});

it('keeps only the parts of a setting that affect results where the setting itself does not', function () use (
    $affects,
    $judges,
): void {
    $written = Json::object(Member::of('timeouts', Json::object(Member::of('seconds', 10))->with(Member::of('mode', 'x'))))
        ->with(Member::of('reports', Json::items('json')));

    expect(Canonical::of($written, ['timeouts.seconds' => $affects, 'timeouts.mode' => $judges, 'reports' => $judges]))
        ->toBe('{"timeouts":{"seconds":10}}');
});

it('keeps an empty object an object, and an empty list a list', function () use ($affects): void {
    expect(Canonical::of(Json::object(Member::of('x', Json::object())), ['x' => $affects]))->toBe('{"x":{}}')
        ->and(Canonical::of(Json::object(Member::of('x', Json::items())), ['x' => $affects]))->toBe('{"x":[]}')
        ->and(Canonical::of(Json::object(Member::of('x', Json::items(Json::object()))), ['x' => $affects]))
        ->toBe('{"x":[{}]}');
});

it('writes a key that reads as a number as the key it is', function () use ($affects): void {
    $written = Json::object(Member::of('x', Json::object(Member::of('12', 1), Member::of('a', 2))));

    expect(Canonical::of($written, ['x' => $affects]))->toBe('{"x":{"12":1,"a":2}}');
});
