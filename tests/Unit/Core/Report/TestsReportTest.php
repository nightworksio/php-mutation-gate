<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Report\ReportSchema;
use NightWorksIO\MutationGate\Core\Report\TestsReport;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Killings;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes what the committed schema describes, and the schema as it is committed', function (): void {
    $schema = Schema::at('resources/tests.schema.json');

    expect((string) file_get_contents($schema))->toBe(sprintf("%s\n", ReportSchema::tests()), 'resources/tests.schema.json is out of date. Run composer report:schema and commit it.')
        ->and(Schema::errors(TestsReport::json(Killings::verdict(MatrixKind::Full)), $schema))->toBe([])
        ->and(Schema::errors(TestsReport::json(Killings::verdict(MatrixKind::FirstKiller)), $schema))->toBe([])
        ->and(Schema::errors(TestsReport::json(Verdicts::failing()), $schema))->toBe([]);
});

it('lists the useless tests with their rows, and asks for a full matrix before it names any as removable', function (): void {
    expect(Decoded::at(TestsReport::json(Killings::verdict(MatrixKind::FirstKiller))))->toBe([
        'format' => 1,
        'matrix' => 'first-killer',
        'useless' => [
            [
                'test' => 'tests/BTest.php::it does B',
                'standing' => 'never-first',
                'covers' => 3,
                'rows' => [
                    ['name' => 'tests/BTest.php::it does B with data set #0', 'standing' => 'never-first'],
                    ['name' => 'tests/BTest.php::it does B with data set #1', 'standing' => 'kills-nothing'],
                ],
            ],
            ['test' => 'tests/CTest.php::it does C', 'standing' => 'never-first', 'covers' => 2],
        ],
        'notAssessed' => 1,
        'redundant' => ['needs' => 'Needs a full kill matrix: run `mutation-gate run --kill-matrix=full`.'],
        'weak' => [],
    ]);
});

it('names the tests that can go from a full matrix, each kill with the kept test that makes it too', function (): void {
    expect(Decoded::at(TestsReport::json(Killings::verdict(MatrixKind::Full))))->toBe([
        'format' => 1,
        'matrix' => 'full',
        'useless' => [['test' => 'tests/CTest.php::it does C', 'standing' => 'kills-nothing', 'covers' => 2]],
        'notAssessed' => 1,
        'redundant' => [
            'kept' => ['tests/ETest.php::it does E', 'tests/ATest.php::it does A', 'tests/DTest.php::it does D'],
            'removable' => [
                [
                    'test' => 'tests/BTest.php::it does B',
                    'seconds' => 1.0,
                    'kills' => [['mutant' => Killings::mutantAt(2)->value(), 'keptBy' => 'tests/ATest.php::it does A']],
                ],
                ['test' => 'tests/CTest.php::it does C', 'seconds' => 0.2, 'kills' => []],
            ],
        ],
        'weak' => [],
    ]);
});

it('says why a matrix under Infection can never name a removable test', function (): void {
    $verdict = Verdicts::failing()->withMatrix(Verdicts::matrix(MatrixKind::FirstKiller)->cannotBeFull(NotFull::Infection));

    expect(Decoded::at(TestsReport::json($verdict), 'redundant'))->toBe(['needs' => NotFull::Infection->sentence()])
        ->and(TestsReport::markdown($verdict))->toContain(sprintf("## Removable\n\n%s", NotFull::Infection->sentence()));
});

it('writes the same as Markdown, a section to each list', function (): void {
    expect(TestsReport::markdown(Killings::verdict(MatrixKind::Full)))->toBe(implode("\n", [
        '# Tests mutation cannot see',
        '',
        'This judges mutation kills only, and never fails anything. One test judged no mutant with a known result, so it is not assessed.',
        '',
        '## Kills nothing it covers (1)',
        '',
        '| Test | Mutants judged | Rows |',
        '|---|---|---|',
        '| <code>tests/CTest.php::it does C</code> | 2 |  |',
        '',
        '## Never the first to kill (0)',
        '',
        'None.',
        '',
        '## Removable (2)',
        '',
        '| Test | Time | Its kills, each with the kept test that makes it too |',
        '|---|---|---|',
        sprintf('| <code>tests/BTest.php::it does B</code> | 1.00s | <code>%s</code> by <code>tests/ATest.php::it does A</code> |', Killings::mutantAt(2)->value()),
        '| <code>tests/CTest.php::it does C</code> | 0.20s |  |',
        '',
        'The kept set is a small one, not the smallest.',
        '',
        '## Asserts only existence or shape (0)',
        '',
        'None.',
        '',
    ]));
});

it('marks a suspicion as one, lists each row, and says how many tests it could not assess', function (): void {
    expect(TestsReport::markdown(Killings::verdict(MatrixKind::FirstKiller)))->toContain(implode("\n", [
        '## Kills nothing it covers (0)',
        '',
        'None.',
        '',
        '## Never the first to kill (2)',
        '',
        'A suspicion, not a finding: run `mutation-gate run --kill-matrix=full` to settle it.',
        '',
        '| Test | Mutants judged | Rows |',
        '|---|---|---|',
        '| <code>tests/BTest.php::it does B</code> | 3 | <code>&#35;0</code>: never-first<br><code>&#35;1</code>: kills-nothing |',
        '| <code>tests/CTest.php::it does C</code> | 2 |  |',
    ]))
        ->and(TestsReport::markdown(Verdicts::passing()))->toContain("This judges mutation kills only, and never fails anything.\n\n")
        ->and(TestsReport::markdown(Killings::tiedVerdict()))->toContain("## Removable (1)");
});

it('says how many tests it could not assess, and names an untimed removable test so', function (): void {
    expect(TestsReport::markdown(Killings::untimedVerdict()))->toContain('2 tests judged no mutant with a known result, so they are not assessed.')
        ->and(TestsReport::markdown(Killings::untimedVerdict()))->toContain('| <code>tests/DTest.php::it does D</code> | untimed |')
        ->and(Decoded::at(TestsReport::json(Killings::untimedVerdict()), 'redundant', 'removable', 0))->not->toHaveKey('seconds');
});

it('says in every form why it names no useless test where the verdict holds no coverage, and nothing of it where there is', function (): void {
    expect(Decoded::at(TestsReport::json(Verdicts::failing()), 'noCoverage'))->toBe(TestsReport::NO_COVERAGE)
        ->and(Decoded::at(TestsReport::json(Verdicts::failing()), 'useless'))->toBe([])
        ->and(Decoded::at(TestsReport::json(Killings::verdict(MatrixKind::Full))))->not->toHaveKey('noCoverage')
        ->and(TestsReport::markdown(Verdicts::failing()))->toContain(sprintf("\n\n%s\n\n## Removable\n\n", TestsReport::NO_COVERAGE))
        ->and(TestsReport::markdown(Verdicts::failing()))->not->toContain('Kills nothing it covers');
});
