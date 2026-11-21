<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\LineSpan;
use NightWorksIO\MutationGate\Core\Mutant\MadeFile;
use NightWorksIO\MutationGate\Core\Mutant\MadeLines;

/** src/Money.php of 20 lines, made on lines 3 to 5 alone. */
function madeLinesMoney(): MadeFile
{
    return MadeFile::of(Path::of('src/Money.php'), Line::of(20), LineSpan::of(Line::of(3), Line::of(5)));
}

it('makes the mutants touching a line it made of a file it lists, and every mutant of a file it lists none of', function (int $first, int $last, bool $makes): void {
    $made = MadeLines::whole()->with(madeLinesMoney());

    expect($made->makes(Path::of('src/Money.php'), Line::of($first), Line::of($last)))->toBe($makes)
        ->and($made->makes(Path::of('src/Tax.php'), Line::of($first), Line::of($last)))->toBeTrue()
        ->and($made->isWhole())->toBeFalse()
        ->and(MadeLines::whole()->isWhole())->toBeTrue();
})->with([
    'ending at its first' => [1, 3, true],
    'starting at its last' => [5, 9, true],
    'before it' => [1, 2, false],
    'after it' => [6, 6, false],
]);

it('takes a later run\'s account of a file both list, and keeps every other', function (): void {
    $later = MadeFile::of(Path::of('src/Money.php'), Line::of(20), LineSpan::of(Line::of(10), Line::of(12)));
    $tax = MadeFile::of(Path::of('src/Tax.php'), Line::of(4));
    $made = MadeLines::whole()->with(madeLinesMoney())->with($tax)->and(MadeLines::whole()->with($later));

    expect([...$made])->toEqual([$later, $tax])
        ->and($made->makes(Path::of('src/Money.php'), Line::of(4), Line::of(4)))->toBeFalse()
        ->and($made->makes(Path::of('src/Money.php'), Line::of(11), Line::of(11)))->toBeTrue();
});

it('lists the files inside a unit as within answers: its own file, or each file inside its held path, in their order', function (string $unit, array $inside): void {
    $made = MadeLines::whole()
        ->with(MadeFile::of(Path::of('src/Kernel/Deep/B.php'), Line::of(9)))
        ->with(MadeFile::of(Path::of('src/KernelX.php'), Line::of(4)))
        ->with(MadeFile::of(Path::of('src/Kernel/A.php'), Line::of(7)))
        ->with(MadeFile::of(Path::of('123'), Line::of(2)));

    expect(array_map(static fn(MadeFile $file): string => $file->path()->value(), [...$made->inside(Path::of($unit))]))->toBe($inside);
})->with([
    'its own file' => ['src/KernelX.php', ['src/KernelX.php']],
    'a file named as a number' => ['123', ['123']],
    'a held path' => ['src/Kernel', ['src/Kernel/Deep/B.php', 'src/Kernel/A.php']],
    'the root' => ['.', ['src/Kernel/Deep/B.php', 'src/KernelX.php', 'src/Kernel/A.php', '123']],
    'a file it made whole' => ['src/Tax.php', []],
]);
