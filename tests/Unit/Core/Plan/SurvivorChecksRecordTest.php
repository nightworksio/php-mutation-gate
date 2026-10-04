<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Plan\SurvivorChecksRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('writes nothing of checks that came to nothing, and reads an absent record as none', function (): void {
    expect(SurvivorChecksRecord::of(SurvivorChecks::none()))->toBe([])
        ->and(SurvivorChecksRecord::read(Node::decode('{}')->field(SurvivorChecksRecord::SECTION)))->toEqual(SurvivorChecks::none());
});

it('writes each analyser\'s time and each survivor left, by why and its file', function (): void {
    $checks = SurvivorChecks::none()
        ->timing(AnalyserHistory::of('mago')->checked(Seconds::of(0.25)))
        ->leaving(UncheckedSurvivor::of(Unchecked::NoMutant, Path::of('src/A.php')));
    $timedOnly = SurvivorChecks::none()->timing(AnalyserHistory::of('mago')->checked(Seconds::of(0.25)));

    expect(json_encode(SurvivorChecksRecord::of($checks)))->toBe(
        '{"analysers":{"mago":{"checks":1,"seconds":0.25,"mutators":{}}},"unchecked":[{"why":"no-mutant","file":"src\/A.php"}]}',
    )
        ->and(SurvivorChecksRecord::read(Node::decode(sprintf('{"s": %s}', json_encode(SurvivorChecksRecord::of($timedOnly))))->field('s')))
        ->toEqual($timedOnly);
});

it('keeps the reason a check could not run with its survivor, and reads a record that keeps none as given none', function (): void {
    $checks = SurvivorChecks::none()
        ->timing(AnalyserHistory::of('psalm')->checked(Seconds::of(1.0)))
        ->leaving(UncheckedSurvivor::failed('Psalm\'s language server did not answer in 60s.', Path::of('src/A.php')))
        ->leaving(UncheckedSurvivor::of(Unchecked::Failed, Path::of('src/B.php')));
    $written = json_encode(SurvivorChecksRecord::of($checks));
    $older = '{"analysers": {}, "unchecked": [{"why": "failed", "file": "src/A.php"}, {"why": "no-mutant", "file": "src/B.php", "reason": "x"}]}';

    expect($written)->toBe(
        '{"analysers":{"psalm":{"checks":1,"seconds":1,"mutators":{}}},"unchecked":['
        . '{"why":"failed","file":"src\/A.php","reason":"Psalm\'s language server did not answer in 60s."},'
        . '{"why":"failed","file":"src\/B.php"}]}',
    )
        ->and(SurvivorChecksRecord::read(Node::decode(sprintf('{"s": %s}', $written))->field('s')))->toEqual($checks)
        ->and(SurvivorChecksRecord::read(Node::decode(sprintf('{"s": %s}', $older))->field('s')))->toEqual(
            SurvivorChecks::none()
                ->leaving(UncheckedSurvivor::of(Unchecked::Failed, Path::of('src/A.php')))
                ->leaving(UncheckedSurvivor::of(Unchecked::NoMutant, Path::of('src/B.php'))),
        );
});
