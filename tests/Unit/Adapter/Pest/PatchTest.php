<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Adapter\Pest\Bridged;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\MutantTime;
use NightWorksIO\MutationGate\Adapter\Pest\ParsedSource;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Adapter\Pest\ServedClasses;
use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnCovering;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A vendor directory holding a copy of the files of pest-plugin-mutate the patch changes, as its allowed version ships them. */
$vendor = static fn(): string => MutatePlugin::pristine()->vendor();

/** A file of the copy, as it is now. */
$source = static fn(string $vendor, string $file): string => (string) file_get_contents(
    sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $vendor, $file),
);

it('finds the patch in place and changes nothing when patching again', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at, new PackageDirectory());
    $patched = array_map(static fn(string $file): string => $source($at, $file), MutatePlugin::FILES);

    expect(Patch::applyIn($at, new PackageDirectory()))->toBe('pest:patch found its patch already in place in the 6 files it changes in pest-plugin-mutate. pest:patch found its patch already in place in the 3 files it changes in pest.')
        ->and(array_map(static fn(string $file): string => $source($at, $file), MutatePlugin::FILES))->toBe($patched);
});

it('patches only the files that are not patched yet', function () use ($vendor, $source): void {
    $at = $vendor();
    $pristine = $source($at, 'Tester/MutationTestRunner.php');
    Patch::applyIn($at, new PackageDirectory());
    file_put_contents(sprintf('%s/pestphp/pest-plugin-mutate/src/Tester/MutationTestRunner.php', $at), $pristine);

    expect(Patch::isAppliedIn($at, new PackageDirectory()))->toBeFalse()
        ->and(Patch::applyIn($at, new PackageDirectory()))->toBe('pest:patch patched 1 of the 6 files it changes in pest-plugin-mutate. pest:patch found its patch already in place in the 3 files it changes in pest.')
        ->and(substr_count($source($at, 'Tester/MutationTestRunner.php'), 'MUTATION_GATE_SUITE_SECONDS'))->toBe(1);
});

it('writes nothing when a line it rewrites has moved', function () use ($vendor, $source): void {
    $at = $vendor();
    $moved = sprintf('%s/pestphp/pest-plugin-mutate/src/Plugins/Mutate.php', $at);
    file_put_contents($moved, str_replace('return $arguments;', 'return $moved;', $source($at, 'Plugins/Mutate.php')));
    $before = $source($at, 'MutationTest.php');

    expect(Patch::applyIn($at, new PackageDirectory()))->toEqual(CannotJudge::because(sprintf(
        'pest:patch patched nothing: the lines it rewrites have moved in %s. Install a supported version.',
        $moved,
    )))->and($source($at, 'MutationTest.php'))->toBe($before);
});

it('cannot patch a pest-plugin-mutate that is not installed', function (): void {
    $at = Scratch::directory();

    expect(Patch::applyIn($at, new PackageDirectory()))->toEqual(CannotJudge::because(sprintf(
        'pest:patch cannot read %s/pestphp/pest-plugin-mutate/src/MutationTest.php. Is pest-plugin-mutate installed?',
        $at,
    )))->and(Patch::isAppliedIn($at, new PackageDirectory()))->toBeFalse();
});

it('writes nothing when a file it rewrites cannot be written', function () use ($vendor, $source): void {
    $at = $vendor();
    $locked = sprintf('%s/pestphp/pest-plugin-mutate/src/Tester', $at);
    chmod(sprintf('%s/MutationTestRunner.php', $locked), 0o444);
    $before = $source($at, 'MutationTest.php');

    try {
        $patched = Patch::applyIn($at, new PackageDirectory());
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
    Patch::applyIn($at, new PackageDirectory());
    $files = array_map(static fn(string $file): string => sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $at, $file), MutatePlugin::FILES);
    array_map(static fn(string $file): bool => chmod($file, 0o444), $files);

    try {
        $again = Patch::applyIn($at, new PackageDirectory());
        $applied = Patch::isAppliedIn($at, new PackageDirectory());
    } finally {
        array_map(static fn(string $file): bool => chmod($file, 0o644), $files);
    }

    expect($again)->toBe('pest:patch found its patch already in place in the 6 files it changes in pest-plugin-mutate. pest:patch found its patch already in place in the 3 files it changes in pest.')
        ->and($applied)->toBeTrue();
});

it('writes nothing where a line it rewrites is there twice', function () use ($vendor, $source): void {
    $at = $vendor();
    $twice = sprintf('%s/pestphp/pest-plugin-mutate/src/Plugins/Mutate.php', $at);
    $shipped = $source($at, 'Plugins/Mutate.php');
    $again = mb_substr($shipped, (int) mb_strpos($shipped, 'public function handleArguments'));
    file_put_contents($twice, sprintf('%s%s', $shipped, $again));

    expect(Patch::applyIn($at, new PackageDirectory()))->toBeInstanceOf(CannotJudge::class)
        ->and($source($at, 'MutationTest.php'))->toBe($source($vendor(), 'MutationTest.php'));
});

it('writes nothing where another version of the gate patched a file, and says to reinstall first', function (
    string $file,
    bool $patchedFirst,
) use ($vendor, $source): void {
    $at = $vendor();
    if ($patchedFirst) {
        Patch::applyIn($at, new PackageDirectory());
    }
    $marked = sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $at, $file);
    // Another version's hunk, as each hunk begins: a comment with the patch's mark.
    file_put_contents($marked, sprintf(
        "%s\n// mutation-gate pest:patch: a run again makes only the mutants it names.\n",
        is_file($marked) ? (string) file_get_contents($marked) : '<?php',
    ));
    $before = $source($at, 'MutationTest.php');

    expect(Patch::applyIn($at, new PackageDirectory()))->toEqual(CannotJudge::because(sprintf(
        "pest:patch patched nothing: %s holds another gate's patch. Run composer reinstall pestphp/pest-plugin-mutate.",
        $marked,
    )))->and(Patch::isAppliedIn($at, new PackageDirectory()))->toBeFalse()
        ->and($source($at, 'MutationTest.php'))->toBe($before);
})->with([
    'in a file it patches, not yet patched' => ['Tester/MutationTestRunner.php', false],
    'in a file it patches, already patched' => ['Tester/MutationTestRunner.php', true],
    'in a file it leaves alone' => ['Plugins/Other.php', true],
]);

it('marks every hunk it writes, one mark to a hunk, so another version\'s are found', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at, new PackageDirectory());

    $marks = array_sum(array_map(
        static fn(string $file): int => substr_count($source($at, $file), '// mutation-gate pest:patch:'),
        MutatePlugin::FILES,
    ));

    expect($marks)->toBe(31);
});

it('compiles each file\'s mutants into a schema and serves a switched mutant the schema, its switch in force, where the gate asks for schemata', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at, new PackageDirectory());

    expect($source($at, 'Support/MutationGenerator.php'))
        ->toContain(sprintf('$afresh = class_exists(\\%1$s::class) && \\%1$s::areOn();', Schemata::class))
        ->toContain('if (! $afresh && $cache->has($file, $contents, $linesToMutate, $mutator)) {')
        ->toContain(sprintf('\\%s::sited($made, $this->originalNode, $this->modifiedNode ?? false);', Schemata::class))
        ->toContain(sprintf('\\%s::written((string) $file->getRealPath(), $contents, $copies);', Schemata::class))
        ->and($source($at, 'MutationTest.php'))
        ->toContain(sprintf('$envs = [...$envs, ...\\%s::variablesOf($this->mutation)];', Schemata::class))
        ->and($source($at, 'Plugins/Mutate.php'))
        ->toContain(sprintf('$schema = (string) getenv(\'%s\');', GateVariable::Schema->value))
        ->toContain(sprintf('$on = (string) getenv(\'%s\');', GateVariable::SchemaOn->value))
        ->toContain(sprintf(
            '$copy = class_exists(\\%1$s::class) ? \\%1$s::inForce($original, $copy, $schema, $on) : $copy;',
            SchemaSwitch::class,
        ));
});

it('parses each file once while its mutants are made, and prints it once for every mutant\'s diff', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at, new PackageDirectory());

    $generator = $source($at, 'Support/MutationGenerator.php');
    $mutation = $source($at, 'Mutation.php');

    expect($generator)->toContain(sprintf('? \\%s::statements($parser->parse(...), $contents)', ParsedSource::class))
        ->and($generator)->not->toContain('$stmts = $parser->parse($contents);')
        ->and($mutation)->toContain(sprintf('? \\%s::printed($parser->parse(...), $file->getContents())', ParsedSource::class))
        ->and($mutation)->not->toContain('$orignalAst');
});

it('has each mutant\'s run select exactly the tests that cover it, a row of a data set alone, named whole by the map Pest loaded', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at, new PackageDirectory());

    expect($source($at, 'MutationTest.php'))->toContain(sprintf('? \\%s::covering($covering, \\%s::suite())->argument()', Selection::class, LoadedMap::class))
        ->and($source($at, 'MutationTest.php'))->toContain(sprintf('\\%s::of($covering, $this->mutation->modifiedSourcePath, $this->calculateTimeout(), $filter)', MutantTime::class))
        ->and($source($at, 'Tester/MutationTestRunner.php'))->toContain(sprintf('\\%s::remember($loadedCoverage)', LoadedMap::class));
});

it('narrows a mutant\'s own run to the covering tests its shard\'s part of the test files holds, after Pest built its filter', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at, new PackageDirectory());
    $test = $source($at, 'MutationTest.php');
    $narrowed = sprintf('$covering = class_exists(\\%1$s::class) ? \\%1$s::of($this->mutation, $covering) : $covering;', OwnCovering::class);

    $inPlace = sprintf(
        '/%s.*%s.*%s/s',
        preg_quote('$filters = array_unique($filters);', '/'),
        preg_quote($narrowed, '/'),
        preg_quote('CoveringFiles::of($covering', '/'),
    );

    expect($test)->toContain($narrowed)
        ->and(preg_match($inPlace, $test))->toBe(1);
});

it('asks each mutant\'s run of its place\'s warm worker, where the run has one', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at, new PackageDirectory());

    expect($source($at, 'MutationTest.php'))->toContain(sprintf(
        '...($processId !== null && class_exists(\\%1$s::class) ? \\%1$s::prefix($processId) : []),',
        Pool::class,
    ));
});

it('serves a class Composer autoloads by an autoloader, and starts the wrapper for any other file or a mutant pinned by source', function () use ($vendor, $source): void {
    $at = $vendor();
    Patch::applyIn($at, new PackageDirectory());

    expect($source($at, 'Plugins/Mutate.php'))->toContain(sprintf('if ($copy !== \'\' && ($pinned || ! class_exists(\\%1$s::class) || ! \\%1$s::serve($original, $copy))) {', ServedClasses::class))
        ->and($source($at, 'Plugins/Mutate.php'))->toContain(sprintf("\$pinned = (string) getenv('%s') !== '';\n", GateVariable::SourcePinned->value))
        ->and($source($at, 'Plugins/Mutate.php'))->toContain('StreamWrapper::start(getenv(self::ENV_MUTATION_TESTING), $copy);')
        ->and($source($at, 'MutationTest.php'))->toContain(sprintf(
            "if (class_exists(\\%1\$s::class) && \\%1\$s::isPinnedBySource(\$this->mutation->mutator)) {\n",
            Bridged::class,
        ))
        ->and($source($at, 'MutationTest.php'))->toContain(sprintf("\$envs['%s'] = '1';\n", GateVariable::SourcePinned->value));
});

it('plans what patching would write, writing nothing, and plans nothing once the patch is in place', function () use ($vendor, $source): void {
    $at = $vendor();
    $pristine = array_map(static fn(string $file): string => $source($at, $file), MutatePlugin::FILES);
    $planned = Patch::plannedIn($at, new PackageDirectory());

    expect(array_map(static fn(string $file): string => $source($at, $file), MutatePlugin::FILES))->toBe($pristine);

    Patch::applyIn($at, new PackageDirectory());
    $applied = [];

    foreach (is_array($planned) ? array_keys($planned) : [] as $path) {
        $applied[$path] = (string) file_get_contents($path);
    }

    expect($planned)->toBe($applied)
        ->and(array_keys($applied))->toContain(sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $at, MutatePlugin::FILES[0]))
        ->and($applied)->toHaveCount(9)
        ->and(Patch::plannedIn($at, new PackageDirectory()))->toBe([])
        ->and(Patch::plannedIn(Scratch::directory(), new PackageDirectory()))->toBeInstanceOf(CannotJudge::class);
});
