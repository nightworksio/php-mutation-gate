<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\Sarif;
use NightWorksIO\MutationGate\Core\Report\SourceRoot;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Secured;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes SARIF 2.1.0 as its schema describes it', function (string $verdict): void {
    $schema = Schema::at('tests/Fixtures/sarif-schema-2.1.0.json');

    expect(Schema::errors(Sarif::json(Verdicts::named($verdict)), $schema))->toBe([])
        ->and(Schema::errors(Sarif::rootedAt(Verdicts::named($verdict), SourceRoot::at('/work/gate')), $schema))->toBe([]);
})->with(['failing', 'passing', 'empty', 'clustered']);

it('names the root its paths are relative to only for an editor on this machine', function (): void {
    $root = static fn(string $sarif): mixed => Decoded::at($sarif, 'runs', 0, 'originalUriBaseIds', '%SRCROOT%');

    expect($root(Sarif::json(Verdicts::failing())))->toBe(['description' => ['text' => 'The repository root']])
        ->and($root(Sarif::rootedAt(Verdicts::failing(), SourceRoot::at('/work/gate'))))
        ->toBe(['uri' => 'file:///work/gate/', 'description' => ['text' => 'The repository root']]);
});

it('never reports a mutant the score leaves out', function (): void {
    expect(Decoded::column(Sarif::json(Verdicts::failing()), 'properties', 'runs', 0, 'results'))
        ->each->not->toMatchArray(['judgement' => 'equivalent']);
});

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

    expect(Decoded::column($sarif, 'ruleId', 'runs', 0, 'results'))->toBe(['survived', 'uncovered', 'flaky', 'unjudged', 'unjudged', 'survived', 'survived'])
        ->and(Decoded::column($sarif, 'ruleIndex', 'runs', 0, 'results'))->toBe([0, 1, 3, 2, 2, 0, 0])
        ->and(Decoded::column($sarif, 'level', 'runs', 0, 'results'))->toBe(['error', 'error', 'error', 'error', 'error', 'note', 'note']);
});

it('reports each ignored mutant as a note, suppressed with why: by the config externally, by a marker in source', function (): void {
    $sarif = Sarif::json(Verdicts::failing());
    $result = static fn(int $at, string|int ...$keys): mixed => Decoded::at($sarif, 'runs', 0, 'results', $at, ...$keys);

    expect($result(5, 'properties', 'judgement'))->toBe('ignored')
        ->and($result(5, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uri'))->toBe('src/Log.php')
        ->and($result(5, 'suppressions'))->toBe([['kind' => 'external', 'status' => 'accepted', 'justification' => 'Logging is asserted in the integration suite']])
        ->and($result(6, 'properties', 'judgement'))->toBe('ignored-by-marker')
        ->and($result(6, 'suppressions'))->toBe([['kind' => 'inSource', 'status' => 'accepted', 'justification' => 'ignored by a native marker']])
        ->and($result(0, 'suppressions'))->toBeNull();
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

it('keeps every member of a cluster a result of its own, naming its cluster', function (): void {
    $verdict = Clustered::verdict();
    [$expression] = iterator_to_array($verdict->trees()->clusters(), preserve_keys: false);
    $sarif = Sarif::json($verdict);

    expect(Decoded::at($sarif, 'runs', 0, 'results'))->toHaveCount(7)
        ->and(Decoded::at($sarif, 'runs', 0, 'results', 0, 'properties', 'cluster'))->toBe($expression->id()->value())
        ->and(Decoded::at($sarif, 'runs', 0, 'results', 6, 'locations', 0, 'physicalLocation', 'region', 'startLine'))->toBe(11)
        ->and(Decoded::at($sarif, 'runs', 0, 'results', 6, 'properties'))->not->toHaveKey('cluster');
});

it('keeps a message that holds a workflow command JSON-escaped, on no line of its own', function (): void {
    $mutant = Verdicts::mutant('src/Money.php:7', "Evil\n::error::injected,a:b%0A", MutatorFamily::None, Verdicts::BOUNDARY);
    $sarif = Sarif::json(Verdicts::of(Floor::of(80), JudgedMutant::of($mutant, MutantJudgement::Survived)));
    $lines = explode("\n", $sarif);

    expect(array_values(array_filter($lines, static fn(string $line): bool => str_starts_with(ltrim($line), '::'))))->toBe([])
        ->and(Decoded::at($sarif, 'runs', 0, 'results', 0, 'properties', 'mutator'))->toBe("Evil\n::error::injected,a:b%0A");
});

it('says a security mutant\'s result is one, and makes it an error where its security set failed', function (): void {
    $sarif = Sarif::json(Verdicts::secured());
    $results = Decoded::at($sarif, 'runs', 0, 'results');
    $security = array_values(array_filter(
        is_array($results) ? $results : [],
        static fn(mixed $result): bool => is_array($result) && is_array($result['properties'] ?? null) && ($result['properties']['security'] ?? false) === true,
    ));

    expect(count($security))->toBe(1)
        ->and($security[0]['level'] ?? null)->toBe('error')
        ->and($security[0]['properties']['id'] ?? null)->toBe(Secured::mutant(MutantJudgement::Survived, 3)->mutant()->id()->value())
        ->and(Schema::errors($sarif, Schema::at('tests/Fixtures/sarif-schema-2.1.0.json')))->toBe([]);
});
