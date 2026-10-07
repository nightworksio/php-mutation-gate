<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\MutantTime;
use NightWorksIO\MutationGate\Adapter\Infection\Patch;
use NightWorksIO\MutationGate\Adapter\Infection\PatchState;
use NightWorksIO\MutationGate\Adapter\Infection\Release;
use NightWorksIO\MutationGate\Adapter\Infection\Silence;
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

it('patches the limit, the skip and the silence limit of every supported release, leaving each file PHP', function (Release $release) use ($source): void {
    $at = InfectionSource::pristine($release->value)->vendor();

    expect(Patch::stateIn($at))->toBe(PatchState::Missing)
        ->and(Patch::applyIn($at))->toBe('infection:patch patched 3 of the 3 files it changes in infection.')
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

    expect(Patch::applyIn($at))->toBe('infection:patch found its patch already in place in the 3 files it changes in infection.')
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
