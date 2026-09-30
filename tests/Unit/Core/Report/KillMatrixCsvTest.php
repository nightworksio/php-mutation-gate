<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Report\KillMatrixCsv;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes a header, then a record per mutant and covering test with what the test did', function (MatrixKind $kind, string $afterTheKiller): void {
    $survivor = Verdicts::survivor()->mutant()->id()->value();
    $killed = Verdicts::killed()->id()->value();
    $records = iterator_to_array(KillMatrixCsv::records(Verdicts::failing()->withMatrix(Verdicts::matrix($kind))), preserve_keys: false);

    expect($records)->toBe([
        "mutant,file,line,mutator,status,source,test,outcome,matrix\r\n",
        sprintf("%s,src/Money.php,7,%s,survived,run,tests/Unit/MoneyTest.php::it fits,passed,%s\r\n", $survivor, Verdicts::LESS, $kind->value),
        sprintf("%s,src/Money.php,7,%s,survived,run,\"tests/Unit/MoneyTest.php::it fits with data set \"\"over\"\"\",passed,%s\r\n", $survivor, Verdicts::LESS, $kind->value),
        sprintf("%s,src/Money.php,7,%s,survived,run,PriceTest::adds,passed,%s\r\n", $survivor, Verdicts::LESS, $kind->value),
        sprintf("%s,src/Money.php,9,TrueValue,killed,run,tests/Unit/MoneyTest.php::it fits,killed,%s\r\n", $killed, $kind->value),
        sprintf("%s,src/Money.php,9,TrueValue,killed,run,PriceTest::adds,%s,%s\r\n", $killed, $afterTheKiller, $kind->value),
    ]);
})->with([
    'first killers' => [MatrixKind::FirstKiller, 'not-run'],
    'a full matrix' => [MatrixKind::Full, 'passed'],
]);

it('says whether the unit of each mutant was run, proved or carried, and run where the verdict lists no unit', function (): void {
    $test = TestId::of('OrderTest::saves');
    $matrix = KillMatrix::of(MatrixKind::FirstKiller, CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(7), $test)
        ->covered(Path::of('src/Order.php'), Line::of(3), $test)
        ->covered(Path::of('src/Log.php'), Line::of(4), $test));
    $sources = static fn(Verdict $verdict): array => array_map(
        static fn(string $record): string => explode(',', $record)[5],
        array_slice(iterator_to_array(KillMatrixCsv::records($verdict->withMatrix($matrix)), preserve_keys: false), 1),
    );

    expect($sources(Verdicts::failing()))->toBe(['run', 'run', 'proved', 'carried'])
        ->and($sources(Verdicts::of(Floor::of(80), Verdicts::survivor())))->toBe(['run']);
});

it('writes only the header for a verdict whose mutants no test covers', function (): void {
    expect(iterator_to_array(KillMatrixCsv::records(Verdicts::passing()), preserve_keys: false))
        ->toBe(["mutant,file,line,mutator,status,source,test,outcome,matrix\r\n"]);
});
