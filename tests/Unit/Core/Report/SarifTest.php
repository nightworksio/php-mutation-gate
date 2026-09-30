<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Sarif;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes SARIF 2.1.0 as its schema describes it', function (string $verdict): void {
    expect(Schema::errors(Sarif::json(Verdicts::named($verdict)), Schema::at('tests/Fixtures/sarif-schema-2.1.0.json')))->toBe([]);
})->with(['failing', 'passing', 'empty']);

it('writes one run of the tool with its four rules', function (): void {
    $sarif = Sarif::json(Verdicts::passing());

    expect(Decoded::at($sarif, 'version'))->toBe('2.1.0')
        ->and(Decoded::at($sarif, 'runs'))->toHaveCount(1)
        ->and(Decoded::at($sarif, 'runs', 0, 'tool', 'driver', 'name'))->toBe('mutation-gate')
        ->and(Decoded::column($sarif, 'id', 'runs', 0, 'tool', 'driver', 'rules'))->toBe(['survived', 'uncovered', 'unjudged', 'flaky'])
        ->and(Decoded::at($sarif, 'runs', 0, 'results'))->toBe([]);
});

it('reports every mutant counted as not killed under its rule, an error in a set that failed', function (): void {
    $sarif = Sarif::json(Verdicts::failing());

    expect(Decoded::column($sarif, 'ruleId', 'runs', 0, 'results'))->toBe(['survived', 'uncovered', 'flaky', 'unjudged', 'unjudged'])
        ->and(Decoded::column($sarif, 'ruleIndex', 'runs', 0, 'results'))->toBe([0, 1, 3, 2, 2])
        ->and(Decoded::column($sarif, 'level', 'runs', 0, 'results'))->toBe(['error', 'error', 'error', 'error', 'error']);
});

it('places a result at the mutant, fingerprinted by the gate\'s id', function (): void {
    $survivor = Verdicts::survivor();
    $id = $survivor->mutant()->id()->value();
    $sarif = Sarif::json(Verdicts::failing());
    $result = static fn(string|int ...$keys): mixed => Decoded::at($sarif, 'runs', 0, 'results', 0, ...$keys);

    expect($result('locations', 0, 'physicalLocation'))->toBe([
        'artifactLocation' => ['uri' => 'src/Money.php', 'uriBaseId' => '%SRCROOT%'],
        'region' => ['startLine' => 7, 'endLine' => 7],
    ])
        ->and($result('partialFingerprints'))->toBe(['primaryLocationLineHash' => $id])
        ->and($result('message', 'text'))->toBe(sprintf(
            'Mutant survived: LessToLessOrEqual. %s Reproduce: vendor/bin/mutation-gate reproduce %s',
            $survivor->hint()->text(),
            $id,
        ))
        ->and($result('properties'))->toMatchArray(['id' => $id, 'mutator' => Verdicts::LESS, 'judgement' => 'survived']);
});

it('warns of a mutant in a set that passed', function (): void {
    $verdict = Verdicts::of(Floor::of(0), Verdicts::survivor());

    expect(Decoded::at(Sarif::json($verdict), 'runs', 0, 'results', 0, 'level'))->toBe('warning');
});
