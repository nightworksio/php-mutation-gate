<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ClassNameEndings;

/**
 * The places whose names end in one of the endings, as reading every name
 * would find them.
 *
 * @param  list<string> $names
 * @param  list<string> $endings
 * @return list<int>
 */
function placesReadOneByOne(array $names, array $endings): array
{
    $places = [];

    foreach ($names as $place => $name) {
        foreach ($endings as $ending) {
            if (str_ends_with($name, $ending)) {
                $places[] = $place;

                break;
            }
        }
    }

    return $places;
}

/** @return list<string> names some of which end in others */
function endingNames(): array
{
    return [
        'MoneyTest',
        'HeldTest',
        'BigMoneyTest',
        'Money',
        'ATest',
        'MoneyTest',
        'ZTest',
        'Test',
        'oneyTest',
        'Zebra',
        'ÅngströmÅTest',
        'BÅTest',
    ];
}

it('finds every name that ends in an ending, each once, in the order the names came in', function (string ...$endings): void {
    $asked = array_values($endings);

    expect(ClassNameEndings::of(endingNames())->placesEndingIn($asked))
        ->toBe(placesReadOneByOne(endingNames(), $asked));
})->with([
    'one ending many names share' => ['MoneyTest'],
    'an ending inside a name' => ['oneyTest'],
    'two endings that overlap' => ['MoneyTest', 'Test'],
    'the ending every test shares' => ['Test'],
    'the name that sorts first backwards' => ['Zebra'],
    'the name that sorts last backwards' => ['ATest'],
    'a whole name and no more' => ['Money'],
    'an ending longer than every name' => ['TheVeryBigMoneyTest'],
    'an ending no name has' => ['Nothing'],
    'an ending that sorts before every name backwards' => ['0'],
    'an ending that sorts after every name backwards' => ['~'],
    'the empty ending' => [''],
    'an ending of letters wider than a byte' => ['ÅTest'],
    'no ending' => [],
]);

it('finds the one name it holds, and none where it holds no name', function (): void {
    expect(ClassNameEndings::of(['MoneyTest'])->placesEndingIn(['Test']))->toBe([0])
        ->and(ClassNameEndings::of(['MoneyTest'])->placesEndingIn(['Held']))->toBe([])
        ->and(ClassNameEndings::of([])->placesEndingIn(['Test']))->toBe([]);
});

it('finds the names of a long list at either end and in its middle, as reading each would', function (): void {
    $many = array_map(static fn(int $at): string => sprintf('Case%dTest', $at), range(0, 99));

    foreach (['Case0Test', 'Case99Test', 'Case50Test', '9Test', '0Test', 'Case1Test'] as $ending) {
        expect(ClassNameEndings::of($many)->placesEndingIn([$ending]))->toBe(placesReadOneByOne($many, [$ending]));
    }
});
