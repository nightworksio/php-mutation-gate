<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\Sonar;
use NightWorksIO\MutationGate\Core\Report\SonarRule;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$sources = static fn(): Sources => Sources::none()->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY));
$report = static fn(string $verdict): string => Sonar::json(Verdicts::named($verdict), $sources(), Guide::unreleased());
$mutantAt = static fn(string $file, Line $start, Line|Unreported $end, string $diff): Mutant => Mutant::of(
    MutantId::hash(Path::of($file), 'Swap', $diff, $start->number()),
    sprintf('native-%d', $start->number()),
    Location::of(Path::of($file), $start, $end),
    Mutation::of('Swap', MutatorFamily::None, $diff),
    MutantStatus::Survived,
    Unmeasured::duration(),
);

it('writes only the fields SonarQube documents, and every one it requires', function (string $verdict) use ($report): void {
    $json = $report($verdict);

    expect(Schema::errors($json, Schema::at('tests/Fixtures/sonar-generic-issues.schema.json')))->toBe([]);

    foreach (Decoded::column($json, 'ruleId', 'issues') as $rule) {
        expect(Decoded::column($json, 'id', 'rules'))->toContain($rule);
    }
})->with(['failing', 'passing', 'empty', 'clustered', 'proved']);

it('is refused by the schema of the fields SonarQube documents where it leaves one out or adds another', function (): void {
    $schema = Schema::at('tests/Fixtures/sonar-generic-issues.schema.json');

    expect(Schema::errors('{"rules": [], "issues": [{"ruleId": "survived", "severity": "HIGH", "primaryLocation": {"message": "m", "filePath": "a.php"}}]}', $schema))->not->toBe([])
        ->and(Schema::errors('{"rules": [{"id": "r", "name": "n", "description": "d", "engineId": "e", "cleanCodeAttribute": "TESTED", "impacts": []}], "issues": []}', $schema))->not->toBe([]);
});

it('lists five rules of the engine mutation-gate, for code that lacks tests', function () use ($report): void {
    $json = $report('passing');

    expect(Decoded::column($json, 'id', 'rules'))->toBe(['survived', 'uncovered', 'unjudged', 'flaky', 'survived-security'])
        ->and(Decoded::column($json, 'engineId', 'rules'))->toBe(array_fill(0, 5, 'mutation-gate'))
        ->and(Decoded::column($json, 'cleanCodeAttribute', 'rules'))->toBe(array_fill(0, 5, 'TESTED'))
        ->and(Decoded::at($json, 'rules', 0))->toBe([
            'id' => 'survived',
            'name' => 'Surviving mutant',
            'description' => sprintf('A mutant no test fails on. See %s', 'https://github.com/nightworksio/php-mutation-gate/blob/main/.docs/guide/troubleshooting.md#survived'),
            'engineId' => 'mutation-gate',
            'cleanCodeAttribute' => 'TESTED',
            'impacts' => [['softwareQuality' => 'RELIABILITY', 'severity' => 'MEDIUM']],
        ])
        ->and(Decoded::at($json, 'rules', 4, 'impacts'))->toBe([['softwareQuality' => 'SECURITY', 'severity' => 'MEDIUM']])
        ->and(Decoded::at($json, 'issues'))->toBe([]);
});

it('links each rule into the troubleshooting guide of the release that wrote it', function () use ($sources): void {
    $json = Sonar::json(Verdicts::passing(), $sources(), Guide::ofInstalled('1.2.3'));

    expect(Decoded::at($json, 'rules', 2, 'description'))->toEndWith('/blob/v1.2.3/.docs/guide/troubleshooting.md#unjudged');
});

it('raises an issue for every mutant counted as not killed, under the rule SARIF reports it by', function () use ($report): void {
    expect(Decoded::column($report('failing'), 'ruleId', 'issues'))->toBe(['survived', 'uncovered', 'flaky', 'unjudged', 'unjudged']);
});

it('places an issue at the columns of its change, counted from 0 and ending before the last, with SARIF\'s message', function () use ($report): void {
    expect(Decoded::at($report('failing'), 'issues', 0))->toBe([
        'ruleId' => 'survived',
        'primaryLocation' => [
            'message' => MutantText::message(Verdicts::survivor()),
            'filePath' => 'src/Money.php',
            'textRange' => ['startLine' => 7, 'startColumn' => 20, 'endLine' => 7, 'endColumn' => 21],
        ],
    ]);
});

it('places an issue whose change its tokens do not show on its lines alone, which SonarQube marks whole', function () use ($report): void {
    expect(Decoded::at($report('failing'), 'issues', 1, 'primaryLocation', 'textRange'))->toBe(['startLine' => 12, 'endLine' => 12])
        ->and(Decoded::at($report('failing'), 'issues', 2, 'primaryLocation', 'textRange'))->toBe(['startLine' => 3, 'endLine' => 3]);
});

it('places an issue on its first line alone where the mutant names no last', function () use ($sources, $mutantAt): void {
    $mutant = $mutantAt('src/Money.php', Line::of(12), Unreported::line(), Verdicts::diff('return false;', 'return true;'));
    $json = Sonar::json(Verdicts::of(Floor::of(80), JudgedMutant::of($mutant, MutantJudgement::Survived)), $sources(), Guide::unreleased());

    expect(Decoded::at($json, 'issues', 0, 'primaryLocation', 'textRange'))->toBe(['startLine' => 12]);
});

it('spans a change that runs onto the next line from its first token to the end of its last', function () use ($mutantAt): void {
    $mutant = $mutantAt('src/Either.php', Line::of(2), Line::of(3), "@@ @@\n-return \$a\n-    && \$b;\n+return \$b && \$a;\n");
    $json = Sonar::json(
        Verdicts::of(Floor::of(80), JudgedMutant::of($mutant, MutantJudgement::Survived)),
        Sources::none()->with(Path::of('src/Either.php'), Contents::of("<?php\nreturn \$a\n    && \$b;\n")),
        Guide::unreleased(),
    );

    expect(Decoded::at($json, 'issues', 0, 'primaryLocation', 'textRange'))->toBe(['startLine' => 2, 'startColumn' => 7, 'endLine' => 3, 'endColumn' => 9]);
});

it('raises one issue for each member of a cluster', function () use ($report): void {
    expect(Decoded::at($report('clustered'), 'issues'))->toHaveCount(7);
});

it('rates every issue alike, whether its set failed or passed', function () use ($sources): void {
    $passed = Sonar::json(Verdicts::of(Floor::of(0), Verdicts::survivor()), $sources(), Guide::unreleased());

    expect(Decoded::at($passed, 'issues', 0, 'ruleId'))->toBe('survived')
        ->and(Decoded::at($passed, 'rules'))->toBe(Decoded::at(Sonar::json(Verdicts::failing(), $sources(), Guide::unreleased()), 'rules'));
});

it('keeps a message that holds a line break JSON-escaped', function () use ($sources): void {
    $mutant = Verdicts::mutant('src/Money.php:7', "Evil\n::error::injected", MutatorFamily::None, Verdicts::BOUNDARY);
    $json = Sonar::json(Verdicts::of(Floor::of(80), JudgedMutant::of($mutant, MutantJudgement::Survived)), $sources(), Guide::unreleased());

    expect(array_filter(explode("\n", $json), static fn(string $line): bool => str_starts_with(ltrim($line), '::')))->toBe([])
        ->and(Decoded::at($json, 'issues', 0, 'primaryLocation', 'message'))->toContain("Evil\n::error::injected");
});

it('counts the issues under each directory at the root, in the order their first issue comes', function (): void {
    $at = static fn(string $place): JudgedMutant => JudgedMutant::of(
        Verdicts::mutant($place, 'TrueValue', MutatorFamily::Literal, Verdicts::diff('return true;', 'return false;')),
        MutantJudgement::Survived,
    );
    $verdict = Verdicts::of(Floor::of(80), $at('src/Money.php:7'), $at('app/Legacy/Old.php:3'), $at('src/Order.php:5'), $at('index.php:2'));

    expect(Sonar::tally($verdict))->toBe('Issues by top directory: 2 under src, 1 under app, 1 under ..')
        ->and(Sonar::tally(Verdicts::failing()))->toBe('Issues by top directory: 5 under src.')
        ->and(Sonar::tally(Verdicts::passing()))->toBe('');
});

it('raises a security survivor under survived-security, and every other issue as SARIF reports it', function () use ($sources): void {
    $json = Sonar::json(Verdicts::secured(), $sources(), Guide::unreleased());
    $issues = Decoded::at($json, 'issues');
    $rules = array_map(
        static fn(int $at): array => [Decoded::at($json, 'issues', $at, 'ruleId'), Decoded::at($json, 'issues', $at, 'primaryLocation', 'filePath')],
        array_keys(is_array($issues) ? $issues : []),
    );

    expect($rules)->toContain(['survived-security', 'src/Auth.php'])
        ->and(array_filter($rules, static fn(array $rule): bool => $rule[0] === 'survived-security'))->toHaveCount(1)
        ->and(SonarRule::Survived->ofSecurity())->toBe(SonarRule::SurvivedSecurity)
        ->and(SonarRule::Uncovered->ofSecurity())->toBe(SonarRule::Uncovered);
});
