<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Tree;

// H4, H7, G1, G5 and G6, over the text of every test file. Read rather than
// run, because a committed `->only()` narrows the run that would report it.

/** The files that name what they refuse in order to refuse it. */
const THAT_NAME_WHAT_THEY_REFUSE = ['tests/Arch/HowATestIsWrittenTest.php', 'tests/Guards/EveryRuleRefusesAViolationTest.php'];

/**
 * Every test file, the package's and each plugin's, by its path relative to
 * the repository, with its text.
 *
 * @return array<string, string>
 */
function testFiles(): array
{
    $found = [];

    foreach ([...Tree::filesUnder('tests', 'Test.php'), ...Tree::filesUnder('plugins', 'Test.php')] as $path) {
        if (! in_array($path, THAT_NAME_WHAT_THEY_REFUSE, strict: true)) {
            $found[$path] = (string) file_get_contents(Tree::at($path));
        }
    }

    return $found;
}

/**
 * The test files whose text matches a pattern, each with the first match.
 *
 * @return list<string>
 */
function testFilesMatching(string $pattern): array
{
    $found = [];

    foreach (testFiles() as $path => $text) {
        if (preg_match($pattern, $text, $matched) === 1) {
            $found[] = sprintf('%s: %s', $path, $matched[0]);
        }
    }

    return $found;
}

it('reads the tests it judges', function (): void {
    expect(testFiles())->not->toBe([], 'no test file was read, so every rule in this file judged nothing');
});

it('keeps every unit test beside the file it tests', function (): void {
    $orphans = [];
    $mirrors = ['tests/Unit/' => 'src/'];

    foreach (Tree::plugins() as $plugin) {
        $mirrors[sprintf('%s/tests/', $plugin)] = sprintf('%s/src/', $plugin);
    }

    foreach ($mirrors as $tests => $sources) {
        foreach (Tree::filesUnder($tests, 'Test.php') as $test) {
            $subject = mb_substr($test, mb_strlen($tests), -mb_strlen('Test.php'));
            $source = sprintf('%s%s.php', $sources, $subject);
            // A test too long for one file is split by concern into a directory named for its subject, where src has
            // no directory of that name.
            $parent = sprintf('%s%s', $sources, dirname($subject));
            $split = dirname($subject) !== '.' && ! is_dir(Tree::at($parent)) && is_file(Tree::at(sprintf('%s.php', $parent)));

            if (! is_file(Tree::at($source)) && ! $split) {
                $orphans[] = sprintf('%s has no %s', $test, $source);
            }
        }
    }

    // H4
    expect($orphans)->toBe([], sprintf(
        "These tests describe something that is not there:\n  %s\n\nA test whose subject moved keeps passing on the fixture it set up. Move it beside its subject, or delete it (H4).",
        implode("\n  ", $orphans),
    ));
});

it('names every test for its behaviour, without an identifier', function (): void {
    $offenders = [];

    foreach (testFiles() as $path => $text) {
        preg_match_all('/\b(?:it|test|describe)\(\s*([\'"])(.*?)(?<!\\\\)\1/su', $text, $found);

        foreach ($found[2] as $description) {
            if (preg_match('/(?<![\w-])(?:[A-Z]\d{1,2}|ADR-\d{1,4}|[A-Z]{1,6}-R\d+)(?!\w)/u', $description) === 1) {
                $offenders[] = sprintf('%s: %s', $path, $description);
            }
        }
    }

    // H7
    expect($offenders)->toBe([], sprintf(
        "These tests are named with an identifier:\n  %s\n\nAn identifier in a name rots when the rule is renumbered, and says nothing about what failed. Name the behaviour, and put the identifier beside the expectation (H7).",
        implode("\n  ", $offenders),
    ));
});

it('mocks nothing', function (): void {
    $offenders = testFilesMatching('/\b(?:Mockery|createMock|createStub|createConfiguredMock|getMockBuilder|prophesize)\b/u');

    // G1
    expect($offenders)->toBe([], sprintf(
        "These tests mock:\n  %s\n\nA mock asserts on how it was called and drifts from what it stands in for. Write a fake in tests/Fakes that the contract suite holds to the port (G1, G2).",
        implode("\n  ", $offenders),
    ));
});

it('asserts with expect and nothing else', function (): void {
    $offenders = testFilesMatching('/(?:\$this->|self::|static::|Assert::)assert[A-Z]\w*/u');

    // G5
    expect($offenders)->toBe([], sprintf(
        "These tests assert with PHPUnit:\n  %s\n\nOne idiom reads the same everywhere. Use expect() (G5).",
        implode("\n  ", $offenders),
    ));
});

it('commits no focused test and no unexplained skip', function (): void {
    $offenders = [
        ...testFilesMatching('/->only\(/u'),
        ...testFilesMatching('/->skip\(\s*\)/u'),
    ];

    // G6
    expect($offenders)->toBe([], sprintf(
        "These tests narrow the run or skip without a reason:\n  %s\n\nA committed only() runs one test and reports green for the rest; a skip without its reason is a test nobody will turn back on (G6).",
        implode("\n  ", $offenders),
    ));
});
