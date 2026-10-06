<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\MutantTime;
use NightWorksIO\MutationGate\Adapter\Pest\OnlyList;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/** A vendor directory holding a copy of pest-plugin-mutate's three files, as its allowed version ships them. */
$vendor = static fn(): string => MutatePlugin::pristine()->vendor();

/** A file of the copy, as it is now. */
$source = static fn(string $vendor, string $file): string => (string) file_get_contents(
    sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $vendor, $file),
);

it('patches the three files, leaving each one PHP', function () use ($vendor, $source): void {
    $at = $vendor();

    expect(Patch::isAppliedIn($at))->toBeFalse()
        ->and(Patch::applyIn($at))->toBe('pest:patch patched 3 of the 3 files it changes in pest-plugin-mutate.')
        ->and(Patch::isAppliedIn($at))->toBeTrue()
        ->and($source($at, 'MutationTest.php'))->toContain("...(strlen(\$filter) < 100000 ? [\$filter] : []),\n")
        ->and($source($at, 'Plugins/Mutate.php'))
        ->toContain("if ((string) getenv('MUTATION_GATE_SHARED_COVERAGE') !== '') {\n")
        ->and($source($at, 'Plugins/Mutate.php'))
        ->toContain("\$arguments[] = '--group='.getenv('MUTATION_GATE_CANARY');\n")
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain("\$seconds = (float) getenv('MUTATION_GATE_SUITE_SECONDS');\n")
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain("\$shared = (string) getenv('MUTATION_GATE_SHARED_COVERAGE');\n")
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain(sprintf(
            '$only = class_exists(\\%1$s::class) ? \\%1$s::in((string) getenv(\'MUTATION_GATE_ONLY\')) : [];',
            OnlyList::class,
        ))
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain("if (\$only !== [] && ! isset(\$only[\$mutation->id])) {\n")
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain(sprintf("\\%s::remember(\$loadedCoverage);\n", MutantTime::class))
        ->and($source($at, 'MutationTest.php'))
        ->toContain(sprintf(
            "? \\%s::of(\$covering, \$this->mutation->modifiedSourcePath, \$this->calculateTimeout())\n",
            MutantTime::class,
        ));

    foreach (MutatePlugin::FILES as $file) {
        $lint = new Process([PHP_BINARY, '-l', sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $at, $file)]);
        $lint->run();

        expect($lint->isSuccessful())->toBeTrue();
    }
});

it('finds the patch in place and changes nothing when patching again', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at);
    $patched = array_map(static fn(string $file): string => $source($at, $file), MutatePlugin::FILES);

    expect(Patch::applyIn($at))->toBe('pest:patch found its patch already in place in the 3 files it changes in pest-plugin-mutate.')
        ->and(array_map(static fn(string $file): string => $source($at, $file), MutatePlugin::FILES))->toBe($patched);
});

it('patches only the files that are not patched yet', function () use ($vendor, $source): void {
    $at = $vendor();
    $pristine = $source($at, 'Tester/MutationTestRunner.php');
    Patch::applyIn($at);
    file_put_contents(sprintf('%s/pestphp/pest-plugin-mutate/src/Tester/MutationTestRunner.php', $at), $pristine);

    expect(Patch::isAppliedIn($at))->toBeFalse()
        ->and(Patch::applyIn($at))->toBe('pest:patch patched 1 of the 3 files it changes in pest-plugin-mutate.')
        ->and(substr_count($source($at, 'Tester/MutationTestRunner.php'), 'MUTATION_GATE_SUITE_SECONDS'))->toBe(1);
});

it('writes nothing when a line it rewrites has moved', function () use ($vendor, $source): void {
    $at = $vendor();
    $moved = sprintf('%s/pestphp/pest-plugin-mutate/src/Plugins/Mutate.php', $at);
    file_put_contents($moved, str_replace('return $arguments;', 'return $moved;', $source($at, 'Plugins/Mutate.php')));
    $before = $source($at, 'MutationTest.php');

    expect(Patch::applyIn($at))->toEqual(CannotJudge::because(sprintf(
        'pest:patch patched nothing: the lines it rewrites have moved in %s. Install a supported version.',
        $moved,
    )))->and($source($at, 'MutationTest.php'))->toBe($before);
});

it('cannot patch a pest-plugin-mutate that is not installed', function (): void {
    $at = Scratch::directory();

    expect(Patch::applyIn($at))->toEqual(CannotJudge::because(sprintf(
        'pest:patch cannot read %s/pestphp/pest-plugin-mutate/src/MutationTest.php. Is pest-plugin-mutate installed?',
        $at,
    )))->and(Patch::isAppliedIn($at))->toBeFalse();
});

it('writes nothing when a file it rewrites cannot be written', function () use ($vendor, $source): void {
    $at = $vendor();
    $locked = sprintf('%s/pestphp/pest-plugin-mutate/src/Tester', $at);
    chmod(sprintf('%s/MutationTestRunner.php', $locked), 0o444);
    $before = $source($at, 'MutationTest.php');

    try {
        $patched = Patch::applyIn($at);
    } finally {
        chmod(sprintf('%s/MutationTestRunner.php', $locked), 0o644);
    }

    expect($patched)->toEqual(CannotJudge::because(sprintf(
        'pest:patch cannot write %s/MutationTestRunner.php. Make the vendor directory writable.',
        $locked,
    )))->and($source($at, 'MutationTest.php'))->toBe($before);
});

it('counts a vendor it already patched as patched, though none of its files can be written', function () use ($vendor): void {
    $at = $vendor();
    Patch::applyIn($at);
    $files = array_map(static fn(string $file): string => sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $at, $file), MutatePlugin::FILES);
    array_map(static fn(string $file): bool => chmod($file, 0o444), $files);

    try {
        $again = Patch::applyIn($at);
        $applied = Patch::isAppliedIn($at);
    } finally {
        array_map(static fn(string $file): bool => chmod($file, 0o644), $files);
    }

    expect($again)->toBe('pest:patch found its patch already in place in the 3 files it changes in pest-plugin-mutate.')
        ->and($applied)->toBeTrue();
});

it('writes nothing where a line it rewrites is there twice', function () use ($vendor, $source): void {
    $at = $vendor();
    $twice = sprintf('%s/pestphp/pest-plugin-mutate/src/Plugins/Mutate.php', $at);
    $shipped = $source($at, 'Plugins/Mutate.php');
    $again = mb_substr($shipped, (int) mb_strpos($shipped, 'public function handleArguments'));
    file_put_contents($twice, sprintf('%s%s', $shipped, $again));

    expect(Patch::applyIn($at))->toBeInstanceOf(CannotJudge::class)
        ->and($source($at, 'MutationTest.php'))->toBe($source($vendor(), 'MutationTest.php'));
});

it('writes nothing where another version of the gate patched a file, and says to reinstall first', function (
    string $file,
    bool $patchedFirst,
) use ($vendor, $source): void {
    $at = $vendor();
    if ($patchedFirst) {
        Patch::applyIn($at);
    }
    $marked = sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $at, $file);
    // Another version's hunk, as each hunk begins: a comment with the patch's mark.
    file_put_contents($marked, sprintf(
        "%s\n// mutation-gate pest:patch: a run again makes only the mutants it names.\n",
        is_file($marked) ? (string) file_get_contents($marked) : '<?php',
    ));
    $before = $source($at, 'MutationTest.php');

    expect(Patch::applyIn($at))->toEqual(CannotJudge::because(sprintf(
        "pest:patch patched nothing: %s holds another gate's patch. Run composer reinstall pestphp/pest-plugin-mutate.",
        $marked,
    )))->and(Patch::isAppliedIn($at))->toBeFalse()
        ->and($source($at, 'MutationTest.php'))->toBe($before);
})->with([
    'in a file it patches, not yet patched' => ['Tester/MutationTestRunner.php', false],
    'in a file it patches, already patched' => ['Tester/MutationTestRunner.php', true],
    'in a file it leaves alone' => ['Plugins/Other.php', true],
]);

it('marks every hunk it writes, one mark to a hunk, so another version\'s are found', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at);

    $marks = array_sum(array_map(
        static fn(string $file): int => substr_count($source($at, $file), '// mutation-gate pest:patch:'),
        MutatePlugin::FILES,
    ));

    expect($marks)->toBe(11);
});
