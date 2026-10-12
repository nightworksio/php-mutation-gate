<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ending;
use NightWorksIO\MutationGate\Adapter\Pest\MutantTime;
use NightWorksIO\MutationGate\Adapter\Pest\OnlyList;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\PrunedFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Twins;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Verdicts;
use NightWorksIO\MutationGate\Tests\Support\FileModes;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

$holds = [
    'holds:src/Adapter/Pest/Ceiling.php',
];

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
        ->toContain(sprintf("\$pruned = class_exists(\\%s::class) ? (string) getenv('MUTATION_GATE_PRUNED') : '';\n", PrunedFile::class))
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain(sprintf(
            "&& \\%s::leavesOut(\$pruned, (string) \$mutation->file->getRealPath(), \$mutation->mutator)\n",
            PrunedFile::class,
        ))
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain(sprintf("\$recorded = class_exists(\\%s::class) ? (string) getenv('MUTATION_GATE_RESULTS') : '';\n", Twins::class))
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain(sprintf("if (\$recorded !== '' && \\%s::isTwin(\$mutation, \$recorded)) {\n", Twins::class))
        ->and($source($at, 'MutationTest.php'))
        ->toContain(sprintf("if (class_exists(\\%1\$s::class) && \\%1\$s::rejects(\$this->mutation->modifiedSourcePath)) {\n", Verdicts::class))
        ->and($source($at, 'Tester/MutationTestRunner.php'))
        ->toContain(sprintf("\\%s::remember(\$loadedCoverage);\n", MutantTime::class))
        ->and($source($at, 'MutationTest.php'))
        ->toContain(sprintf(
            "? \\%s::of(\$covering, \$this->mutation->modifiedSourcePath, \$this->calculateTimeout())\n",
            MutantTime::class,
        ))
        ->and($source($at, 'Support/StreamWrapper.php'))
        ->toContain("if (! file_exists(\$path) && ! (\$link && is_link(\$path))) {\n")
        ->and($source($at, 'Support/StreamWrapper.php'))->not->toContain('is_readable($path) === false')
        ->and($source($at, 'MutationTest.php'))
        ->toContain(sprintf("\\%s::record(\$this->process, \$this->mutation->modifiedSourcePath);\n", Ending::class));

    foreach (MutatePlugin::FILES as $file) {
        $lint = new Process([PHP_BINARY, '-l', sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $at, $file)]);
        $lint->run();

        expect($lint->isSuccessful())->toBeTrue();
    }
})->group(...$holds);

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
})->skip(fn(): bool => ! FileModes::areEnforced(), 'root reads a file whatever its mode')->group(...$holds);

it('stats a dangling link and a file it cannot read as missing while the shipped override serves a file', function (): void {
    expect(overrideStats(MutatePlugin::pristine()->vendor()))->toMatchArray([
        'is_link(dangling)' => [false, 0],
        'file_exists(unreadable)' => [false, 0],
    ]);
})->skip(fn(): bool => ! FileModes::areEnforced(), 'root reads a file whatever its mode')->group(...$holds);
