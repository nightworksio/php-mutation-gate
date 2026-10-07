<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\MutantTime;
use NightWorksIO\MutationGate\Adapter\Pest\OnlyList;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\FileModes;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/** A vendor directory holding a copy of the files of pest-plugin-mutate the patch changes, as its allowed version ships them. */
$vendor = static fn(): string => MutatePlugin::pristine()->vendor();

/** A file of the copy, as it is now. */
$source = static fn(string $vendor, string $file): string => (string) file_get_contents(
    sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $vendor, $file),
);

it('patches the four files, leaving each one PHP', function () use ($vendor, $source): void {
    $at = $vendor();

    expect(Patch::isAppliedIn($at))->toBeFalse()
        ->and(Patch::applyIn($at))->toBe('pest:patch patched 4 of the 4 files it changes in pest-plugin-mutate.')
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
        ))
        ->and($source($at, 'Support/StreamWrapper.php'))
        ->toContain("if (! file_exists(\$path) && ! (\$link && is_link(\$path))) {\n")
        ->and($source($at, 'Support/StreamWrapper.php'))->not->toContain('is_readable($path) === false');

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

    expect(Patch::applyIn($at))->toBe('pest:patch found its patch already in place in the 4 files it changes in pest-plugin-mutate.')
        ->and(array_map(static fn(string $file): string => $source($at, $file), MutatePlugin::FILES))->toBe($patched);
});

it('patches only the files that are not patched yet', function () use ($vendor, $source): void {
    $at = $vendor();
    $pristine = $source($at, 'Tester/MutationTestRunner.php');
    Patch::applyIn($at);
    file_put_contents(sprintf('%s/pestphp/pest-plugin-mutate/src/Tester/MutationTestRunner.php', $at), $pristine);

    expect(Patch::isAppliedIn($at))->toBeFalse()
        ->and(Patch::applyIn($at))->toBe('pest:patch patched 1 of the 4 files it changes in pest-plugin-mutate.')
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

    expect($again)->toBe('pest:patch found its patch already in place in the 4 files it changes in pest-plugin-mutate.')
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

    expect($marks)->toBe(12);
});

/**
 * What a PHP process says of a dangling link, a file it cannot read, a missing
 * file, a plain one and a link to it, by these stat calls: without pest-plugin-mutate's
 * override, or with the override from this vendor directory serving another
 * file, and how many warnings each call raised.
 *
 * @return array<mixed>
 */
function overrideStats(string $vendor = ''): array
{
    $at = Scratch::directory();
    Scratch::write($at, 'served.php', "<?php\n");
    Scratch::write($at, 'copy.php', "<?php\n");
    Scratch::write($at, 'plain', 'plain');
    Scratch::write($at, 'unreadable', 'unreadable');
    chmod(sprintf('%s/unreadable', $at), 0o000);
    symlink(sprintf('%s/gone', $at), sprintf('%s/dangling', $at));
    symlink(sprintf('%s/plain', $at), sprintf('%s/link', $at));
    $wrapper = $vendor === '' ? '' : sprintf('%s/pestphp/pest-plugin-mutate/src/Support/StreamWrapper.php', $vendor);
    $probe = <<<'PHP'
        <?php
        [, $at, $wrapper] = $argv;
        if ($wrapper !== '') {
            require $wrapper;
            \Pest\Mutate\Support\StreamWrapper::start("$at/served.php", "$at/copy.php");
        }
        $warnings = 0;
        set_error_handler(static function () use (&$warnings): bool { $warnings++; return true; });
        $said = [];
        foreach ([
            'is_link(dangling)' => static fn() => is_link("$at/dangling"),
            'file_exists(dangling)' => static fn() => file_exists("$at/dangling"),
            'lstat(dangling)' => static fn() => lstat("$at/dangling") !== false,
            'file_exists(unreadable)' => static fn() => file_exists("$at/unreadable"),
            'is_file(unreadable)' => static fn() => is_file("$at/unreadable"),
            'is_readable(unreadable)' => static fn() => is_readable("$at/unreadable"),
            'file_exists(missing)' => static fn() => file_exists("$at/missing"),
            'stat(missing)' => static fn() => stat("$at/missing"),
            'is_link(plain)' => static fn() => is_link("$at/plain"),
            'filesize(plain)' => static fn() => filesize("$at/plain"),
            'is_file(link)' => static fn() => is_file("$at/link"),
            'filesize(link)' => static fn() => filesize("$at/link"),
            'is_link(link)' => static fn() => is_link("$at/link"),
        ] as $call => $asked) {
            $warnings = 0;
            $said[$call] = [$asked(), $warnings];
        }
        echo json_encode($said);
        PHP;
    Scratch::write($at, 'probe.php', $probe);
    $process = new Process([PHP_BINARY, '-n', sprintf('%s/probe.php', $at), $at, $wrapper]);
    $process->run();
    chmod(sprintf('%s/unreadable', $at), 0o600);
    $said = json_decode($process->getOutput(), associative: true);

    return is_array($said) ? $said : ['failed' => $process->getErrorOutput()];
}

it('stats a dangling link as a link, and a file it cannot read as there, while the patched override serves a file, as PHP does without it', function (): void {
    $vendor = MutatePlugin::pristine()->vendor();
    Patch::applyIn($vendor);

    expect(overrideStats($vendor))->toBe(overrideStats())
        ->and(overrideStats())->toMatchArray([
            'is_link(dangling)' => [true, 0],
            'file_exists(unreadable)' => [true, 0],
            'stat(missing)' => [false, 1],
            'file_exists(missing)' => [false, 0],
        ]);
})->skip(! FileModes::areEnforced(), 'root reads a file whatever its mode');

it('stats a dangling link and a file it cannot read as missing while the shipped override serves a file', function (): void {
    expect(overrideStats(MutatePlugin::pristine()->vendor()))->toMatchArray([
        'is_link(dangling)' => [false, 0],
        'file_exists(unreadable)' => [false, 0],
    ]);
})->skip(! FileModes::areEnforced(), 'root reads a file whatever its mode');
