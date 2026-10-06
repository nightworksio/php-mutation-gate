<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Canonical;
use NightWorksIO\MutationGate\Core\Config\Definition;
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

/**
 * A config that writes one setting, by its path, and nothing else: `trees[].floor` writes a list of one object.
 */
$writing = static function (string $path, string $value): Json {
    $wrapped = static fn(string $key, Json|string $inside): Json => str_ends_with($key, '[]')
        ? Json::object(Member::of(substr($key, 0, -2), Json::items($inside)))
        : Json::object(Member::of($key, $inside));
    $keys = array_reverse(explode('.', $path));
    $written = $wrapped(array_shift($keys), $value);

    foreach ($keys as $key) {
        $written = $wrapped($key, $written);
    }

    return $written;
};

it('keeps a setting in the comparison of what decides how the gate runs unless it only judges or reports, and in the key only where it affects results', function (string $setting, Effect $effect) use ($writing): void {
    $effects = Definition::effects();
    $none = Json::object();
    $written = $writing($setting, 'changed');

    expect(Canonical::deciding($written, $effects) !== Canonical::deciding($none, $effects))
        ->toBe($effect !== Effect::JudgesOrReportsOnly)
        ->and(Canonical::of($written, $effects) !== Canonical::of($none, $effects))
        ->toBe($effect === Effect::AffectsResults);
})->with(static function (): iterable {
    foreach (Definition::effects() as $setting => $effect) {
        yield $setting => [$setting, $effect];
    }
});

it('keeps a key no setting declares, wherever no declared setting holds it, in the key and in what decides how the gate runs', function (Json $written) use ($affects, $judges): void {
    $effects = ['trees[].path' => $affects, 'shards.max' => $judges, 'reports' => $judges];

    expect(Canonical::of($written, $effects))->not->toBe('{}')
        ->and(Canonical::deciding($written, $effects))->not->toBe('{}');
})->with([
    'at the top' => [fn(): Json => Json::object(Member::of('unknown', 1))],
    'in a section' => [fn(): Json => Json::at('shards.unknown', 1)],
    'in a list\'s entry' => [fn(): Json => Json::object(Member::of('trees', Json::items(Json::object(Member::of('unknown', 1)))))],
]);

it('leaves out what a setting that only reports holds, an adapter\'s options among it, and keeps an option of an adapter that affects results', function () use ($affects, $judges): void {
    $effects = ['runner' => $affects, 'reports' => $judges];
    $reports = static fn(int $depth): Json => Json::object(Member::of('reports', Json::items(
        Json::object(Member::of('use', 'acme'), Member::of('with', Json::object(Member::of('depth', $depth)))),
    )));
    $runner = static fn(int $depth): Json => Json::object(Member::of(
        'runner',
        Json::object(Member::of('use', 'acme'), Member::of('with', Json::object(Member::of('depth', $depth)))),
    ));

    expect(Canonical::deciding($reports(1), $effects))->toBe(Canonical::deciding($reports(2), $effects))
        ->and(Canonical::deciding($runner(1), $effects))->not->toBe(Canonical::deciding($runner(2), $effects));
});

it('leaves a setting that decides how the gate runs out of the key, inside one that affects results', function () use ($affects): void {
    $effects = ['runner' => $affects, 'runner.store' => Effect::DecidesHowTheGateRuns];
    $written = Json::object(Member::of('runner', Json::object(Member::of('use', 'pest'), Member::of('store', 'elsewhere'))));

    expect(Canonical::of($written, $effects))->toBe('{"runner":"pest"}')
        ->and(Canonical::deciding($written, $effects))->toBe('{"runner":{"store":"elsewhere","use":"pest"}}');
});
