<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Proof\KillHistoryFile;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('writes its format, the tests it names and its killers, and reads them back', function (): void {
    $mutant = MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+b", 0);
    $history = KillHistory::none()
        ->withMutant($mutant, Ranking::of(Kills::of(TestId::of('MoneyTest::adds'), 2)))
        ->withFunction(Enclosing::named(Path::of('src/Money.php'), 'add'), Ranking::of(Kills::of(TestId::of('b'), 1)));
    $written = KillHistoryFile::encode($history);

    expect($written)->toBe(sprintf(
        '{"format":1,"tests":["MoneyTest::adds","b"],"killers":{"mutants":{"%s":[[0,2]]},%s}}',
        $mutant->value(),
        '"functions":{"src/Money.php":{"add":[[1,1]]}}',
    ))
        ->and(KillHistoryFile::decode($written))->toEqual($history)
        ->and(KillHistoryFile::decode(KillHistoryFile::encode(KillHistory::none())))->toEqual(KillHistory::none());
});

it('cannot read what is not a kill history of its format, saying where it went wrong', function (
    string $json,
    string $where,
): void {
    expect(KillHistoryFile::decode($json))
        ->toEqual(CannotJudge::because(sprintf('A kill history cannot be read: %s', $where)));
})->with([
    'another format' => ['{"format": 2, "tests": [], "killers": {}}', 'the file.format is not format 1.'],
    'no tests' => ['{"format": 1, "killers": {}}', 'the file.tests is missing.'],
    'not JSON' => ['{"format": 1,', 'the file.format is missing.'],
]);
