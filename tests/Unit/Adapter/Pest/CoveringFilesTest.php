<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedTests;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv(GateVariable::Narrow->value);
    putenv(GateVariable::Results->value);
    CoveringFiles::forget();
    Scratch::sweep();
});

/**
 * Test files, loaded into this process as a suite's are: a helper function, a fake class and constants each declared beside a
 * test case in one file and used from another, a base test case in a file of its own and a test case extending it,
 * and a trait of tests in a file of its own and a test case using it.
 *
 * @return array{string, array<string, string>, string} the namespace they declare in, each file by its name, and
 *                                                      the directory they are in
 */
$suite = static function (): array {
    $namespace = sprintf('Reach%d', hrtime(as_number: true));
    $sources = [
        'DeclaresTest.php' => 'function helper(): int { return 1; } final class Fake {} final class DeclaresTest extends \PHPUnit\Framework\TestCase { public function testIt(): void {} }',
        'UsesHelperTest.php' => 'final class UsesHelperTest extends \PHPUnit\Framework\TestCase { public function testIt(): void { helper(); } }',
        'NamesFakeTest.php' => 'final class NamesFakeTest extends \PHPUnit\Framework\TestCase { public function testIt(): void { $class = \'%1$s\\\\Fake\'; } }',
        'BaseTest.php' => 'abstract class BaseTest extends \PHPUnit\Framework\TestCase {}',
        'ChildTest.php' => 'final class ChildTest extends BaseTest { public function testIt(): void {} }',
        'AssertsTest.php' => 'trait Asserts { public function testIt(): void {} }',
        'UsesTraitTest.php' => 'final class UsesTraitTest extends \PHPUnit\Framework\TestCase { use Asserts; }',
        'AloneTest.php' => 'final class AloneTest extends \PHPUnit\Framework\TestCase { public function testIt(): void {} }',
        'ConstantsTest.php' => 'const LIMIT = 5; final class ConstantsTest extends \PHPUnit\Framework\TestCase { public function testIt(): void {} }',
        'UsesConstantTest.php' => 'final class UsesConstantTest extends \PHPUnit\Framework\TestCase { public function testIt(): void { $sum = LIMIT; } }',
        'SharesTest.php' => '$GLOBALS[\'shared\'] = helper(); final class SharesTest extends \PHPUnit\Framework\TestCase { public function testIt(): void {} }',
    ];
    $files = [];
    $root = Scratch::directory();

    foreach ($sources as $name => $source) {
        Scratch::write($root, sprintf('%s/%s', $namespace, $name), sprintf("<?php\nnamespace %s;\n%s\n", $namespace, sprintf($source, $namespace)));
        $files[$name] = sprintf('%s/%s/%s', $root, $namespace, $name);
    }

    foreach (array_keys($sources) as $name) {
        require_once $files[$name];
    }

    CoveringFiles::forget($root);

    return [$namespace, array_map(static fn(string $file): string => (string) realpath($file), $files), $root];
};

it('needs each loaded test file that is not inert, and each that declares a function, class, base, trait or constant one of those uses', function () use ($suite): void {
    [$namespace, $files, $root] = $suite();
    $loaded = LoadedTests::inThisProcess($root);
    $needs = static fn(string $name): array => $loaded->needs($files[$name]);

    $with = static fn(string ...$names): array => array_map(static fn(string $name): string => $files[$name], $names);

    expect($needs('UsesHelperTest.php'))->toBe($with('UsesHelperTest.php', 'SharesTest.php', 'DeclaresTest.php'))
        ->and($needs('NamesFakeTest.php'))->toBe($with('NamesFakeTest.php', 'SharesTest.php', 'DeclaresTest.php'))
        ->and($needs('ChildTest.php'))->toBe($with('ChildTest.php', 'SharesTest.php', 'BaseTest.php', 'DeclaresTest.php'))
        ->and($needs('UsesTraitTest.php'))->toBe($with('UsesTraitTest.php', 'SharesTest.php', 'AssertsTest.php', 'DeclaresTest.php'))
        ->and($needs('AloneTest.php'))->toBe($with('AloneTest.php', 'SharesTest.php', 'DeclaresTest.php'))
        ->and($needs('UsesConstantTest.php'))->toBe($with('UsesConstantTest.php', 'SharesTest.php', 'ConstantsTest.php', 'DeclaresTest.php'))
        ->and($needs('SharesTest.php'))->toBe($with('SharesTest.php', 'DeclaresTest.php'))
        ->and($loaded->fileOf(mb_strtolower(sprintf('%s\ChildTest', $namespace))))->toBe($files['ChildTest.php'])
        ->and($loaded->fileOf('never\loaded'))->toBe('');
});

it('narrows a mutant\'s run to what its covering tests need, only where the run narrows and it fits', function () use ($suite): void {
    [$namespace, $files, $root] = $suite();
    $child = sprintf('%s\ChildTest::testIt', $namespace);
    $alone = sprintf('%s\AloneTest::testIt with data set #0', $namespace);
    $narrowing = static function (int $longest, string ...$tests) use ($root): array {
        CoveringFiles::forget($root);

        return CoveringFiles::of(array_values($tests), '/tmp/mutations/abc', $longest);
    };
    putenv(sprintf('%s=%s/results.jsonl', GateVariable::Results->value, Scratch::directory()));
    $unnarrowed = $narrowing(100000, $child);
    putenv(sprintf('%s=1', GateVariable::Narrow->value));
    $expected = array_map(
        static fn(string $name): string => $files[$name],
        ['AloneTest.php', 'BaseTest.php', 'ChildTest.php', 'DeclaresTest.php', 'SharesTest.php'],
    );
    sort($expected);

    expect($unnarrowed)->toBe([])
        ->and($narrowing(100000, $child, $alone))->toBe($expected)
        ->and($narrowing(100000, $child, 'Never\Loaded::test'))->toBe([])
        ->and($narrowing(100000))->toBe([])
        ->and($narrowing(Bytes::length(implode(' ', $expected)), $child, $alone))->toBe([]);
});

it('narrows a run only once it records the files it loads by the mutant\'s mutated copy, in the results file the gate names', function () use ($suite): void {
    [$namespace, , $root] = $suite();
    $child = sprintf('%s\ChildTest::testIt', $namespace);
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    putenv(sprintf('%s=1', GateVariable::Narrow->value));
    $unnamed = CoveringFiles::of([$child], '/tmp/mutations/unnamed');
    putenv(sprintf('%s=', GateVariable::Results->value));
    $empty = CoveringFiles::of([$child], '/tmp/mutations/empty');
    putenv(sprintf('%s=%s', GateVariable::Results->value, $results));
    $uncopied = CoveringFiles::of([$child]);
    putenv(sprintf('%s=%s/missing/results.jsonl', GateVariable::Results->value, Scratch::directory()));
    // A write that fails warns, as PHP does, and narrows nothing.
    set_error_handler(static fn(): bool => true);

    try {
        $unwritten = CoveringFiles::of([$child], '/tmp/mutations/unwritten');
    } finally {
        restore_error_handler();
    }

    putenv(sprintf('%s=%s', GateVariable::Results->value, $results));

    $paths = CoveringFiles::of([$child], '/tmp/mutations/abc');
    CoveringFiles::of(['Never\Loaded::test'], '/tmp/mutations/whole');

    expect([$unnamed, $empty, $uncopied, $unwritten])->toBe([[], [], [], []])
        ->and($paths)->toContain(sprintf('%s/%s/ChildTest.php', (string) realpath($root), $namespace))
        ->and(file_get_contents($results))->toBe(RecordLine::narrowed('/tmp/mutations/abc', $paths));
});

it('narrows a run of a test its class takes from a trait to the class\'s file and the trait\'s', function () use ($suite): void {
    [$namespace, $files] = $suite();
    putenv(sprintf('%s=1', GateVariable::Narrow->value));
    putenv(sprintf('%s=%s/results.jsonl', GateVariable::Results->value, Scratch::directory()));
    $expected = array_map(
        static fn(string $name): string => $files[$name],
        ['AssertsTest.php', 'DeclaresTest.php', 'SharesTest.php', 'UsesTraitTest.php'],
    );
    sort($expected);

    expect(CoveringFiles::of([sprintf('%s\UsesTraitTest::testIt', $namespace)], '/tmp/mutations/abc'))->toBe($expected);
});

it('reads the suite from the test directory Pest runs, where none is named', function (): void {
    putenv(sprintf('%s=1', GateVariable::Narrow->value));
    putenv(sprintf('%s=%s/results.jsonl', GateVariable::Results->value, Scratch::directory()));
    CoveringFiles::forget();

    // The class Pest builds for this file.
    expect(CoveringFiles::of(['P\Tests\Unit\Adapter\Pest\CoveringFilesTest::__pest_evaluable_it_reads'], '/tmp/mutations/abc'))
        ->toContain((string) realpath(__FILE__));
});
