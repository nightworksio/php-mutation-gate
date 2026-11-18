<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\Split\SettledFirst;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\MutationTest;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    CoveringFiles::forget();
    Scratch::sweep();
});

/**
 * Test files loaded into this process as a suite's are: one alone, one that
 * a test of another needs for the helper it declares, and that other.
 *
 * @return array{string, string} the namespace they declare in and the directory they are in
 */
function settledFirstSuite(): array
{
    $namespace = sprintf('Settled%d', hrtime(as_number: true));
    $sources = [
        'AloneTest.php' => 'final class AloneTest extends \PHPUnit\Framework\TestCase { public function testIt(): void {} }',
        'HelpsTest.php' => 'function helps(): int { return 1; } final class HelpsTest extends \PHPUnit\Framework\TestCase { public function testIt(): void {} }',
        'NeedsTest.php' => 'final class NeedsTest extends \PHPUnit\Framework\TestCase { public function testIt(): void { helps(); } }',
    ];
    $root = Scratch::directory();

    foreach ($sources as $name => $source) {
        Scratch::write($root, sprintf('%s/%s', $namespace, $name), sprintf("<?php\nnamespace %s;\n%s\n", $namespace, $source));
    }

    foreach (array_keys($sources) as $name) {
        require_once sprintf('%s/%s/%s', $root, $namespace, $name);
    }

    CoveringFiles::forget($root);

    return [$namespace, $root];
}

function settledFirstMutant(string $id): MutationTest
{
    return new MutationTest(Mutations::mutation('src/Money.php', $id, 11, sprintf('%s/%s.php', Scratch::directory(), $id)));
}

it('settles each mutant the second pass streams no site of and runs no test file of a covering test of, that file one a streamed test needs or not', function (): void {
    [$namespace] = settledFirstSuite();
    $test = static fn(string $class): string => sprintf('%s\%s::testIt', $namespace, $class);
    $byId = [
        'alone' => settledFirstMutant('alone'),
        'helps' => settledFirstMutant('helps'),
        'needs' => settledFirstMutant('needs'),
        'again' => settledFirstMutant('again'),
        'unloaded' => settledFirstMutant('unloaded'),
    ];
    $covering = [
        'alone' => [$test('AloneTest')],
        'helps' => [$test('HelpsTest')],
        'needs' => [$test('NeedsTest')],
        'again' => [$test('NeedsTest')],
        'unloaded' => ['Never\Loaded::testIt'],
    ];
    $site = ['k', 1, '', []];

    expect(array_keys(SettledFirst::of($byId, $covering, ['again' => $site], ['again' => [$test('NeedsTest')]])))
        ->toBe(['alone', 'unloaded'])
        ->and(array_keys(SettledFirst::of($byId, $covering, [], [])))
        ->toBe(['alone', 'helps', 'needs', 'again', 'unloaded'])
        ->and(array_keys(SettledFirst::of($byId, $covering, ['again' => $site], ['again' => [$test('AloneTest')]])))
        ->toBe(['helps', 'needs', 'unloaded']);
});
