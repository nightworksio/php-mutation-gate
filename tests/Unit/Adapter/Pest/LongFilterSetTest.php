<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\LongFilterSet;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$long = static fn(): string => sprintf('--filter=%s', str_repeat('a', 100000));

/**
 * What a mutant's run is started with, and whether it selects exactly, under these variables.
 *
 * @param  list<string>          $covering
 * @param  array<string, string> $variables
 * @return ArrayObject<int, list<string>|bool>
 */
function longFilterSet(array $covering, string $filter, array $variables): ArrayObject
{
    return Environment::during(
        [GateVariable::LongFilter->value => null, GateVariable::Results->value => null, ...$variables],
        static fn(): ArrayObject => new ArrayObject([LongFilterSet::arguments($covering, $filter), LongFilterSet::selects($covering, $filter)]),
    );
}

it('passes a filter that fits as it is', function (): void {
    expect(longFilterSet(['T::a'], '--filter=a', [])->getArrayCopy())->toBe([['--filter=a'], true]);
});

it('names the covering tests of a filter too long to pass in a file beside the results, one id a line, under set', function () use ($long): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    [$arguments, $selects] = longFilterSet(['T::a', 'T::b'], $long(), [
        GateVariable::LongFilter->value => LongFilter::Set->value,
        GateVariable::Results->value => $results,
    ])->getArrayCopy();
    $file = is_array($arguments) && is_string($arguments[0] ?? null) ? explode('=', $arguments[0], 2) : [];

    expect($file[0] ?? '')->toBe(PhpUnitOption::TestIdFilterFile->value)
        ->and(dirname($file[1] ?? ''))->toBe(dirname($results))
        ->and(file_get_contents($file[1] ?? ''))->toBe("T::a\nT::b\n")
        ->and($selects)->toBeTrue();
});

it('selects nothing exactly for a filter too long to pass where set is off, no results are kept, or an id would not read back', function (LongFilter $mode, string $results, string $id) use ($long): void {
    $variables = [GateVariable::LongFilter->value => $mode->value, GateVariable::Results->value => $results];

    expect(longFilterSet($id === '' ? [] : [$id], $long(), $variables)->getArrayCopy())->toBe([[], false]);
})->with([
    'files' => [LongFilter::Files, '/tmp/r.jsonl', 'T::a'],
    'no results' => [LongFilter::Set, '', 'T::a'],
    'no covering test' => [LongFilter::Set, '/tmp/r.jsonl', ''],
    'an id over two lines' => [LongFilter::Set, '/tmp/r.jsonl', "T::a\nb"],
]);
