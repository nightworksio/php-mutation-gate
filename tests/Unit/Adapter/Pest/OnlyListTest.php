<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\OnlyList;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('hands over more ids than one environment string holds on Linux, and reads every one back', function (): void {
    $ids = array_map(static fn(int $n): string => hash('xxh3', (string) $n), range(1, 8000));
    $file = OnlyList::write(OnlyList::beside(sprintf('%s/results.jsonl', Scratch::directory())), ...$ids);
    $read = OnlyList::in($file);

    expect($file)->toEndWith('/results.jsonl.only')
        ->and(filesize($file))->toBeGreaterThan(131072)
        ->and(array_map(strval(...), array_keys($read)))->toBe($ids)
        // PHP keys an id of digits alone, as the 2,674th is, as the number, and finds it by its text.
        ->and(array_key_exists('9288083398254234', $read))->toBeTrue()
        ->and(array_key_exists($ids[7999], $read) && array_key_exists($ids[0], $read))->toBeTrue()
        ->and(array_key_exists('not-listed', $read))->toBeFalse();
});

it('reads no list where none is named, none is there or it is empty, so the run makes every mutant', function (): void {
    $empty = OnlyList::write(sprintf('%s/empty.only', Scratch::directory()));
    set_error_handler(static fn(): bool => true);
    $unwritten = OnlyList::write(sprintf('%s/missing/results.jsonl.only', Scratch::directory()), 'n1');
    restore_error_handler();

    expect(OnlyList::in(''))->toBe([])
        ->and(OnlyList::in(sprintf('%s/nowhere.only', Scratch::directory())))->toBe([])
        ->and(OnlyList::in($empty))->toBe([])
        ->and(OnlyList::in($unwritten))->toBe([]);
});
