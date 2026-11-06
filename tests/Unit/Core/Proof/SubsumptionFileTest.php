<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Proof\SubsumptionFile;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;

it('reads back which mutant subsumes which as it wrote it, beside the coverage map', function (): void {
    $of = MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+b\n", 0);
    $by = MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+c\n", 0);
    $held = Subsumption::none()->with(Path::of('src/Money.php'), $of, $by);

    expect(SubsumptionFile::decode(SubsumptionFile::encode($held)))->toEqual($held)
        ->and(SubsumptionFile::decode(SubsumptionFile::encode(Subsumption::none())))->toEqual(Subsumption::none())
        ->and(SubsumptionFile::in(Path::of('.mutation-gate/coverage/shard-1'))->value())
        ->toBe('.mutation-gate/coverage/shard-1/subsumption.json');
});

it('cannot read a file of another format, or no file of this one', function (): void {
    expect(SubsumptionFile::decode('{"format": 2, "subsumption": {}}'))->toEqual(CannotJudge::because(
        'What subsumes what cannot be read: the file.format is not format 1.',
    ))
        ->and(SubsumptionFile::decode('[]'))->toBeInstanceOf(CannotJudge::class);
});
