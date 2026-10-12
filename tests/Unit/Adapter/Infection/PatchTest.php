<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\PackageSource;
use NightWorksIO\MutationGate\Adapter\Infection\Patch;
use NightWorksIO\MutationGate\Adapter\Infection\PatchState;
use NightWorksIO\MutationGate\Adapter\Infection\Release;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\InfectionPatches;
use NightWorksIO\MutationGate\Tests\Support\InfectionSource;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A file of the copy, as it is now. */
$source = static fn(string $vendor, string $file): string => (string) file_get_contents(
    sprintf('%s/infection/infection/src/%s', $vendor, $file),
);

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

it('patches nothing, in Infection either, where a line it rewrites in the interceptor has moved, and counts it unpatched', function (): void {
    $at = InfectionSource::pristine()->vendor();
    $file = sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $at);
    file_put_contents($file, str_replace('if (is_readable($path) === false) {', 'if (!is_readable($path)) {', InfectionPatches::interceptor($at)));
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
    file_put_contents(sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $half), InfectionPatches::interceptor($pristine));

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
