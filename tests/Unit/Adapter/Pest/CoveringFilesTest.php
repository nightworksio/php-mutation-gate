<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedTests;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv(GateVariable::Narrow->value);
    CoveringFiles::forget();
    Scratch::sweep();
});

/**
 * Test files, loaded into this process as a suite's are: a helper function and a fake class each declared beside a
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
    ];
    $files = [];
    $root = Scratch::directory();

    foreach ($sources as $name => $source) {
        Scratch::write($root, sprintf('%s/%s', $namespace, $name), sprintf("<?php\nnamespace %s;\n%s\n", $namespace, sprintf($source, $namespace)));
        $files[$name] = sprintf('%s/%s/%s', $root, $namespace, $name);
    }

    foreach (['DeclaresTest.php', 'UsesHelperTest.php', 'NamesFakeTest.php', 'BaseTest.php', 'ChildTest.php', 'AssertsTest.php', 'UsesTraitTest.php', 'AloneTest.php'] as $name) {
        require_once $files[$name];
    }

    CoveringFiles::forget($root);

    return [$namespace, array_map(static fn(string $file): string => (string) realpath($file), $files), $root];
};

it('needs each loaded test file that declares a function, class, base or trait a test file uses, and no other', function () use ($suite): void {
    [$namespace, $files, $root] = $suite();
    $loaded = LoadedTests::inThisProcess($root);
    $needs = static fn(string $name): array => $loaded->needs($files[$name]);

    expect($needs('UsesHelperTest.php'))->toBe([$files['UsesHelperTest.php'], $files['DeclaresTest.php']])
        ->and($needs('NamesFakeTest.php'))->toBe([$files['NamesFakeTest.php'], $files['DeclaresTest.php']])
        ->and($needs('ChildTest.php'))->toBe([$files['ChildTest.php'], $files['BaseTest.php']])
        ->and($needs('UsesTraitTest.php'))->toBe([$files['UsesTraitTest.php'], $files['AssertsTest.php']])
        ->and($needs('AloneTest.php'))->toBe([$files['AloneTest.php']])
        ->and($loaded->fileOf(mb_strtolower(sprintf('%s\ChildTest', $namespace))))->toBe($files['ChildTest.php'])
        ->and($loaded->fileOf('never\loaded'))->toBe('');
});

it('narrows a mutant\'s run to what its covering tests need, only where the run narrows and it fits', function () use ($suite): void {
    [$namespace, $files, $root] = $suite();
    $child = sprintf('%s\ChildTest::testIt', $namespace);
    $alone = sprintf('%s\AloneTest::testIt with data set #0', $namespace);
    $narrowing = static function (int $longest, string ...$tests) use ($root): array {
        CoveringFiles::forget($root);

        return CoveringFiles::of(array_values($tests), $longest);
    };
    $unnarrowed = $narrowing(100000, $child);
    putenv(sprintf('%s=1', GateVariable::Narrow->value));
    $expected = [$files['AloneTest.php'], $files['BaseTest.php'], $files['ChildTest.php']];
    sort($expected);

    expect($unnarrowed)->toBe([])
        ->and($narrowing(100000, $child, $alone))->toBe($expected)
        ->and($narrowing(100000, $child, 'Never\Loaded::test'))->toBe([])
        ->and($narrowing(100000))->toBe([])
        ->and($narrowing(mb_strlen(implode(' ', $expected)), $child, $alone))->toBe([]);
});

it('narrows a run of a test its class takes from a trait to the class\'s file and the trait\'s', function () use ($suite): void {
    [$namespace, $files] = $suite();
    putenv(sprintf('%s=1', GateVariable::Narrow->value));

    expect(CoveringFiles::of([sprintf('%s\UsesTraitTest::testIt', $namespace)]))
        ->toBe([$files['AssertsTest.php'], $files['UsesTraitTest.php']]);
});

it('reads the suite from the test directory Pest runs, where none is named', function (): void {
    putenv(sprintf('%s=1', GateVariable::Narrow->value));
    CoveringFiles::forget();

    // The class Pest builds for this file.
    expect(CoveringFiles::of(['P\Tests\Unit\Adapter\Pest\CoveringFilesTest::__pest_evaluable_it_reads']))
        ->toContain((string) realpath(__FILE__));
});
