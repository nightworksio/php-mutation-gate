<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

it('counts the mutants that start on each line of a file, a line given twice holding two', function (): void {
    $file = Path::of('src/Money.php');
    $sites = MutantSites::inFile($file, Line::of(7), Line::of(3), Line::of(7));

    expect($sites->linesOf($file))->toEqual(Lines::of(Line::of(3), Line::of(7)))
        ->and($sites->countAt($file, Line::of(7)))->toBe(2)
        ->and($sites->countAt($file, Line::of(3)))->toBe(1)
        ->and($sites->countAt($file, Line::of(4)))->toBe(0)
        ->and($sites->count())->toBe(3);
});

it('holds a file it counted with no mutant, and not a file it never counted', function (): void {
    $empty = Path::of('src/Empty.php');
    $sites = MutantSites::inFile($empty);

    expect($sites->has($empty))->toBeTrue()
        ->and($sites->linesOf($empty))->toEqual(Lines::none())
        ->and($sites->has(Path::of('src/Other.php')))->toBeFalse()
        ->and($sites->countAt(Path::of('src/Other.php'), Line::of(1)))->toBe(0)
        ->and(MutantSites::none()->files())->toEqual(Paths::none())
        ->and(MutantSites::none()->count())->toBe(0);
});

it('adds the files another counted, the other\'s count of a file both counted standing', function (): void {
    $money = Path::of('src/Money.php');
    $ledger = Path::of('src/Ledger.php');
    $sites = MutantSites::inFile($money, Line::of(1))
        ->and(MutantSites::inFile($ledger, Line::of(2)))
        ->and(MutantSites::inFile($money, Line::of(5), Line::of(5)));

    expect($sites->files())->toEqual(Paths::of($money, $ledger))
        ->and($sites->first())->toEqual($money)
        ->and(MutantSites::none()->first())->toEqual(NotGiven::value())
        ->and($sites->countAt($money, Line::of(1)))->toBe(0)
        ->and($sites->countAt($money, Line::of(5)))->toBe(2)
        ->and($sites->count())->toBe(3);
});
