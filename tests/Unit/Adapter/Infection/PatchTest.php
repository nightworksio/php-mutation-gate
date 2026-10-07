<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\MutantTime;
use NightWorksIO\MutationGate\Adapter\Infection\PackageSource;
use NightWorksIO\MutationGate\Adapter\Infection\Patch;
use NightWorksIO\MutationGate\Adapter\Infection\PatchState;
use NightWorksIO\MutationGate\Adapter\Infection\Release;
use NightWorksIO\MutationGate\Adapter\Infection\Silence;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\FileModes;
use NightWorksIO\MutationGate\Tests\Support\InfectionSource;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/** A file of the copy, as it is now. */
$source = static fn(string $vendor, string $file): string => (string) file_get_contents(
    sprintf('%s/infection/infection/src/%s', $vendor, $file),
);

it('patches the limit, the skip and the silence limit of every supported release, leaving each file PHP', function (Release $release) use ($source): void {
    $at = InfectionSource::pristine($release->value)->vendor();

    expect(Patch::stateIn($at))->toBe(PatchState::Missing)
        ->and(Patch::applyIn($at))->toBe('infection:patch patched 3 of the 3 files it changes in infection. infection:patch patched 1 of the 1 files it changes in include-interceptor.')
        ->and(Patch::stateIn($at))->toBe(PatchState::Applied)
        ->and($source($at, 'Process/Factory/MutantProcessContainerFactory.php'))
        ->toContain(sprintf(
            "? \\%s::of(\$mutant->getMutation()->getNominalTestExecutionTime(), \$this->timeout)\n",
            MutantTime::class,
        ))
        ->and($source($at, 'Process/Runner/MutationTestingRunner.php'))
        ->toContain(sprintf("if (class_exists(\\%1\$s::class) && \\%1\$s::bounded()) {\n", MutantTime::class))
        ->and($source($at, 'Process/Runner/MutationTestingRunner.php'))
        ->toContain('// mutation-gate infection:patch: within the gate\'s bounds')
        ->and($source($at, 'Process/Factory/MutantProcessContainerFactory.php'))
        ->toContain(sprintf("\\%s::watch(\$process, \$mutant, \$this->timeout);\n", Silence::class))
        ->and($source($at, 'Process/Runner/ParallelProcessRunner.php'))
        ->toContain(sprintf("\\%s::check(\$process, microtime(true));\n", Silence::class));

    foreach (InfectionSource::FILES as $file) {
        $lint = new Process([PHP_BINARY, '-l', sprintf('%s/infection/infection/src/%s', $at, $file)]);
        $lint->run();

        expect($lint->isSuccessful())->toBeTrue();
    }
})->with(Release::cases());

it('finds the patch in place and changes nothing when patching again', function () use ($source): void {
    $at = InfectionSource::pristine()->vendor();
    Patch::applyIn($at);
    $patched = array_map(static fn(string $file): string => $source($at, $file), InfectionSource::FILES);

    expect(Patch::applyIn($at))->toBe('infection:patch found its patch already in place in the 3 files it changes in infection. infection:patch found its patch already in place in the 1 files it changes in include-interceptor.')
        ->and(array_map(static fn(string $file): string => $source($at, $file), InfectionSource::FILES))->toBe($patched);
});

it('patches no release it does not support, and says which it supports', function (): void {
    $at = InfectionSource::pristine()->vendor('0.34.0');

    expect(Patch::applyIn($at))->toEqual(CannotJudge::because(sprintf(
        'infection:patch patched nothing: it patches Infection %s, and %s holds Infection 0.34.0. Install a supported release.',
        Release::listed(),
        $at,
    )))
        ->and(Patch::stateIn($at))->toBe(PatchState::Missing);
});

it('patches nothing where Composer lists no Infection', function (): void {
    $at = InfectionSource::pristine()->vendor();
    file_put_contents(sprintf('%s/composer/installed.json', $at), '{"packages": []}');

    expect(Patch::applyIn($at))->toEqual(CannotJudge::because(sprintf(
        'infection:patch patched nothing: %s/composer/installed.json lists no Infection. Is Infection installed?',
        $at,
    )));
});

it('patches nothing where a line it rewrites has moved', function (): void {
    $at = InfectionSource::pristine()->vendor();
    $file = sprintf('%s/infection/infection/src/Process/Runner/MutationTestingRunner.php', $at);
    file_put_contents($file, str_replace('< $this->timeout) {', '<= $this->timeout) {', (string) file_get_contents($file)));

    expect(Patch::applyIn($at))->toEqual(CannotJudge::because(sprintf(
        'infection:patch patched nothing: the lines it rewrites have moved in %s. Install a supported version.',
        $file,
    )))
        ->and(Patch::stateIn($at))->toBe(PatchState::Missing);
});

it('patches nothing where Composer lists nothing it installed, or a file it changes is not there', function (): void {
    $unlisted = InfectionSource::pristine()->vendor();
    unlink(sprintf('%s/composer/installed.json', $unlisted));
    $gone = InfectionSource::pristine()->vendor();
    unlink(sprintf('%s/infection/infection/src/Process/Runner/MutationTestingRunner.php', $gone));

    expect(Patch::applyIn($unlisted))->toEqual(CannotJudge::because(sprintf(
        'infection:patch patched nothing: %s/composer/installed.json lists no Infection. Is Infection installed?',
        $unlisted,
    )))
        ->and(Patch::applyIn($gone))->toEqual(CannotJudge::because(sprintf(
            'infection:patch cannot read %s/infection/infection/src/Process/Runner/MutationTestingRunner.php. Is infection installed?',
            $gone,
        )))
        ->and(Patch::stateIn($gone))->toBe(PatchState::Missing);
});

/** The copy's include-interceptor, as it is now. */
function interceptorIn(string $vendor): string
{
    return (string) file_get_contents(sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $vendor));
}

it('patches the interceptor\'s stat of every include-interceptor release Infection allows, leaving it PHP', function (string $interceptor): void {
    $at = InfectionSource::pristine('0.35.6', $interceptor)->vendor();

    expect(Patch::applyIn($at))->toBe(
        'infection:patch patched 3 of the 3 files it changes in infection. infection:patch patched 1 of the 1 files it changes in include-interceptor.',
    )
        ->and(interceptorIn($at))->toContain("if (! file_exists(\$path) && ! (\$link && is_link(\$path))) {\n")
        ->and(interceptorIn($at))->toContain('// mutation-gate infection:patch: a path stats as without the wrapper')
        ->and(interceptorIn($at))->not->toContain('is_readable($path) === false');

    $lint = new Process([PHP_BINARY, '-l', sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $at)]);
    $lint->run();

    expect($lint->isSuccessful())->toBeTrue();
})->with(array_keys(InfectionSource::INTERCEPTOR_SHIPS_AS));

it('patches nothing, in Infection either, where a line it rewrites in the interceptor has moved, and counts it unpatched', function (): void {
    $at = InfectionSource::pristine()->vendor();
    $file = sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $at);
    file_put_contents($file, str_replace('if (is_readable($path) === false) {', 'if (!is_readable($path)) {', interceptorIn($at)));
    $runner = sprintf('%s/infection/infection/src/Process/Runner/MutationTestingRunner.php', $at);
    $before = (string) file_get_contents($runner);

    expect(Patch::applyIn($at))->toEqual(CannotJudge::because(sprintf(
        'infection:patch patched nothing: the lines it rewrites have moved in %s. Install a supported version.',
        $file,
    )))
        ->and(file_get_contents($runner))->toBe($before)
        ->and(Patch::stateIn($at))->toBe(PatchState::Missing);
});

it('cannot patch, and counts unpatched, an Infection whose include-interceptor is not there or not patched', function (): void {
    $gone = InfectionSource::pristine()->vendor();
    unlink(sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $gone));
    $half = InfectionSource::pristine()->vendor();
    Patch::applyIn($half);
    $pristine = InfectionSource::pristine()->vendor();
    file_put_contents(sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $half), interceptorIn($pristine));

    expect(Patch::applyIn($gone))->toEqual(CannotJudge::because(sprintf(
        'infection:patch cannot read %s/infection/include-interceptor/src/IncludeInterceptor.php. Is include-interceptor installed?',
        $gone,
    )))
        ->and(Patch::stateIn($gone))->toBe(PatchState::Missing)
        ->and(Patch::stateIn($half))->toBe(PatchState::Missing)
        ->and(Patch::applyIn($half))->toBe(
            'infection:patch found its patch already in place in the 3 files it changes in infection. infection:patch patched 1 of the 1 files it changes in include-interceptor.',
        )
        ->and(Patch::stateIn($half))->toBe(PatchState::Applied);
});

/**
 * What a PHP process says of a dangling link, a file it cannot read, a missing
 * file, a plain one and a link to it, by these stat calls: without
 * include-interceptor, or with the interceptor from this vendor directory
 * enabled to serve another file, and how many warnings each call raised.
 *
 * @return array<mixed>
 */
function interceptedStats(string $vendor = ''): array
{
    $at = Scratch::directory();
    Scratch::write($at, 'served.php', "<?php\n");
    Scratch::write($at, 'copy.php', "<?php\n");
    Scratch::write($at, 'plain', 'plain');
    Scratch::write($at, 'unreadable', 'unreadable');
    chmod(sprintf('%s/unreadable', $at), 0o000);
    symlink(sprintf('%s/gone', $at), sprintf('%s/dangling', $at));
    symlink(sprintf('%s/plain', $at), sprintf('%s/link', $at));
    $wrapper = $vendor === '' ? '' : sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $vendor);
    $probe = <<<'PHP'
        <?php
        [, $at, $wrapper] = $argv;
        if ($wrapper !== '') {
            require $wrapper;
            \Infection\StreamWrapper\IncludeInterceptor::intercept("$at/served.php", "$at/copy.php");
            \Infection\StreamWrapper\IncludeInterceptor::enable();
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

it('stats a dangling link as a link, and a file it cannot read as there, while the patched interceptor serves a file, as PHP does without it', function (): void {
    $at = InfectionSource::pristine()->vendor();
    Patch::applyIn($at);

    expect(interceptedStats($at))->toBe(interceptedStats())
        ->and(interceptedStats())->toMatchArray([
            'is_link(dangling)' => [true, 0],
            'file_exists(unreadable)' => [true, 0],
            'stat(missing)' => [false, 1],
            'file_exists(missing)' => [false, 0],
        ]);
})->skip(! FileModes::areEnforced(), 'root reads a file whatever its mode');

it('stats a dangling link and a file it cannot read as missing while the shipped interceptor serves a file', function (): void {
    expect(interceptedStats(InfectionSource::pristine()->vendor()))->toMatchArray([
        'is_link(dangling)' => [false, 0],
        'file_exists(unreadable)' => [false, 0],
    ]);
})->skip(! FileModes::areEnforced(), 'root reads a file whatever its mode');

it('says it could not write a package whose changed file would not be written, though Infection\'s were', function (): void {
    $at = InfectionSource::pristine()->vendor();
    $interceptor = sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $at);
    $runner = sprintf('%s/infection/infection/src/Process/Runner/MutationTestingRunner.php', $at);
    $infection = InfectionSource::pristine()->vendor();
    Patch::applyIn($infection);
    $patched = (string) file_get_contents(sprintf('%s/infection/infection/src/Process/Runner/MutationTestingRunner.php', $infection));

    $said = PackageSource::applyWith(
        static fn(string $file, string $source): int|false => $file === $interceptor ? false : file_put_contents($file, $source),
        $at,
        ...Patch::patches(),
    );

    expect($said)->toEqual(CannotJudge::because(sprintf(
        'infection:patch cannot write %s/infection/include-interceptor/src. Make the vendor directory writable.',
        $at,
    )))
        ->and(file_get_contents($runner))->toBe($patched);
});
