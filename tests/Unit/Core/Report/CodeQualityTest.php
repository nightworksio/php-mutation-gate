<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\CodeQuality;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes an issue for every mutant counted as not killed, under the rule SARIF reports it by', function (): void {
    $report = CodeQuality::json(Verdicts::failing());

    expect(Decoded::column($report, 'check_name'))->toBe(['survived', 'uncovered', 'flaky', 'unjudged', 'unjudged'])
        ->and(Decoded::column($report, 'severity'))->toBe(['major', 'major', 'major', 'major', 'major']);
});

it('places an issue at its mutant\'s first line, fingerprinted by the gate\'s id', function (): void {
    $survivor = Verdicts::survivor();

    expect(Decoded::at(CodeQuality::json(Verdicts::failing()), 0))->toBe([
        'description' => MutantText::message($survivor),
        'check_name' => 'survived',
        'fingerprint' => $survivor->mutant()->id()->value(),
        'severity' => 'major',
        'location' => ['path' => 'src/Money.php', 'lines' => ['begin' => 7]],
    ]);
});

it('rates a mutant in a set that passed as minor', function (): void {
    expect(Decoded::at(CodeQuality::json(Verdicts::of(Floor::of(0), Verdicts::survivor())), 0, 'severity'))->toBe('minor');
});

it('writes a verdict with nothing counted as not killed as an empty list', function (string $verdict): void {
    expect(CodeQuality::json(Verdicts::named($verdict)))->toBe('[]');
})->with(['passing', 'empty']);
