<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Columns;
use NightWorksIO\MutationGate\Core\Report\FileColumns;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('reads the columns of each file the mutants are in from what the file holds', function (): void {
    $sources = Sources::none()->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY));
    $columns = FileColumns::of(Verdicts::everyJudgement(), $sources);

    expect($columns->in(Path::of('src/Money.php')))->toEqual(Columns::in(Contents::of(Verdicts::MONEY)))
        ->and($columns->in(Path::of('src/Order.php')))->toEqual(Columns::in(Contents::of('')));
});

it('reads the columns of an empty file for a file none of the mutants is in', function (): void {
    $sources = Sources::none()->with(Path::of('src/Other.php'), Contents::of('<?php return 1;'));

    expect(FileColumns::of(JudgedMutants::none(), $sources)->in(Path::of('src/Other.php')))->toEqual(Columns::in(Contents::of('')));
});
