<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\OneHome\Clash;
use NightWorksIO\MutationGate\PHPStan\Rules\OneHome\Home;

/**
 * Each clash as its constant and the homes it shares its value with.
 *
 * @param array<string, list<list<array{string, string, int, bool}>>> $collected
 * @param list<string>                                                 $coincidences
 *
 * @return list<string>
 */
function clashesAmong(array $collected, array $coincidences = []): array
{
    return array_map(
        static fn(Clash $clash): string => sprintf(
            '%s with %s',
            $clash->home->name,
            implode(', ', array_map(static fn(Home $other): string => $other->name, $clash->others)),
        ),
        Clash::among($collected, $coincidences),
    );
}

it('reports each constant whose value another class also declares', function (): void {
    expect(clashesAmong([
        '/src/A.php' => [[["'.php'", 'A::PHP', 10, false]]],
        '/src/B.php' => [[["'.php'", 'B::SUFFIX', 20, false], ["'sha256'", 'B::ALGORITHM', 21, false]]],
    ]))->toBe(['A::PHP with B::SUFFIX', 'B::SUFFIX with A::PHP']);
});

it('says where each reported constant is declared', function (): void {
    $clashes = Clash::among([
        '/src/A.php' => [[["'.php'", 'A::PHP', 10, false]]],
        '/src/B.php' => [[["'.php'", 'B::SUFFIX', 20, false]]],
    ], []);

    expect([$clashes[0]->home->file, $clashes[0]->home->line, $clashes[0]->home->value])->toBe(['/src/A.php', 10, "'.php'"]);
});

it('leaves a value declared twice in one class alone', function (): void {
    expect(clashesAmong(['/src/A.php' => [[['20', 'A::MOST', 3, false], ['20', 'A::RETRIES', 4, false]]]]))->toBe([]);
});

it('counts an enum case as a home, but reports only the constant beside it', function (): void {
    expect(clashesAmong([
        '/src/Judgement.php' => [[["'passed'", 'Judgement::Passed', 5, true]]],
        '/src/LedgerFile.php' => [[["'passed'", 'LedgerFile::PASSED', 9, false]]],
    ]))->toBe(['LedgerFile::PASSED with Judgement::Passed']);
});

it('leaves two enums that share a word alone', function (): void {
    expect(clashesAmong([
        '/src/MutantStatus.php' => [[["'killed'", 'MutantStatus::Killed', 5, true]]],
        '/src/MutantJudgement.php' => [[["'killed'", 'MutantJudgement::Killed', 6, true]]],
    ]))->toBe([]);
});

it('leaves out a constant named a coincidence', function (): void {
    expect(clashesAmong([
        '/src/A.php' => [[['20', 'A::FARTHEST', 1, false]]],
        '/src/B.php' => [[['20', 'B::MOST_SHARDS', 2, false]]],
    ], ['A::FARTHEST']))->toBe([]);
});

it('reports a constant once for a value its array holds more than once', function (): void {
    expect(clashesAmong([
        '/src/A.php' => [[["'pest'", 'A::RUNNERS', 3, false], ["'pest'", 'A::RUNNERS', 3, false]]],
        '/src/B.php' => [[["'pest'", 'B::RUNNER', 7, false]]],
    ]))->toBe(['A::RUNNERS with B::RUNNER', 'B::RUNNER with A::RUNNERS']);
});
