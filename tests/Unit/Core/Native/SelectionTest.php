<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Native\Selection;

it('lists exactly the tests it selects, one to a line, in its file, read through its option', function (): void {
    $selection = Selection::exactly('ids.txt', ['T::a', 'T::b'], '--ids=%s');

    expect([$selection->file(), $selection->lines(), $selection->contents(), $selection->option('/g/ids.txt'), $selection->isExact()])
        ->toBe(['ids.txt', ['T::a', 'T::b'], "T::a\nT::b\n", '--ids=/g/ids.txt', true]);
});

it('lists the files that hold the tests it selects, which select more than those', function (): void {
    $selection = Selection::byFile('files.txt', ['/p/tests/ATest.php'], '--files=%s');

    expect([$selection->file(), $selection->contents(), $selection->option('/g/files.txt'), $selection->isExact()])
        ->toBe(['files.txt', "/p/tests/ATest.php\n", '--files=/g/files.txt', false]);
});

it('lists no test as a line end alone', function (): void {
    expect(Selection::exactly('ids.txt', [], '--ids=%s')->contents())->toBe("\n");
});
