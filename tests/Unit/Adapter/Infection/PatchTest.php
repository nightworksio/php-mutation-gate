<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\MutantTime;
use NightWorksIO\MutationGate\Adapter\Infection\Patch;
use NightWorksIO\MutationGate\Adapter\Infection\PatchState;
use NightWorksIO\MutationGate\Core\CannotJudge;
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

it('patches the limit and the skip of a supported release, leaving each file PHP', function () use ($source): void {
    $at = InfectionSource::pristine()->vendor();

    expect(Patch::stateIn($at))->toBe(PatchState::Missing)
        ->and(Patch::applyIn($at))->toBe('infection:patch patched 2 of the 2 files it changes in infection.')
        ->and(Patch::stateIn($at))->toBe(PatchState::Applied)
        ->and($source($at, 'Process/Factory/MutantProcessContainerFactory.php'))
        ->toContain(sprintf(
            "? \\%s::of(\$mutant->getMutation()->getNominalTestExecutionTime(), \$this->timeout)\n",
            MutantTime::class,
        ))
        ->and($source($at, 'Process/Runner/MutationTestingRunner.php'))
        ->toContain(sprintf("if (class_exists(\\%1\$s::class) && \\%1\$s::bounded()) {\n", MutantTime::class))
        ->and($source($at, 'Process/Runner/MutationTestingRunner.php'))
        ->toContain('// mutation-gate infection:patch: within the gate\'s bounds');

    foreach (InfectionSource::FILES as $file) {
        $lint = new Process([PHP_BINARY, '-l', sprintf('%s/infection/infection/src/%s', $at, $file)]);
        $lint->run();

        expect($lint->isSuccessful())->toBeTrue();
    }
});

it('finds the patch in place and changes nothing when patching again', function () use ($source): void {
    $at = InfectionSource::pristine()->vendor();
    Patch::applyIn($at);
    $patched = array_map(static fn(string $file): string => $source($at, $file), InfectionSource::FILES);

    expect(Patch::applyIn($at))->toBe('infection:patch patched 0 of the 2 files it changes in infection.')
        ->and(array_map(static fn(string $file): string => $source($at, $file), InfectionSource::FILES))->toBe($patched);
});

it('patches no release it does not support, and says which it supports', function (): void {
    $at = InfectionSource::pristine()->vendor('0.35.5');

    expect(Patch::applyIn($at))->toEqual(CannotJudge::because(sprintf(
        'infection:patch patched nothing: it patches Infection 0.35.6, and %s holds Infection 0.35.5. Install a supported release.',
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
