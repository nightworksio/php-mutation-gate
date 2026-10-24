<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinitions;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Proof\Key\Source;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;
use NightWorksIO\MutationGate\Core\Proof\Key\Tests;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Process;

$holds = ['holds:src/Adapter/Pest'];

// What every runner that drives PHPUnit leaves of a project whose PHPUnit
// config names its cache directory, `.phpunit.cache`, which the repository
// does not ignore, as lcobucci/jwt's benchmark copy did: the files git
// lists, which every content key reads (ADR-0007), as they were, and
// PHPUnit's caches in the gate's own directory for them, so a run of an
// unchanged project reads the keys the last one wrote and reuses every unit.
// Pest makes the directory the config names as it boots, before it reads
// its arguments, and writes nothing in it.

afterEach(function (): void {
    Scratch::sweep();
});

/** The files under a directory, at any depth; none where it is not there. */
function filesUnderCache(string $directory): int
{
    $files = 0;

    if (is_dir($directory)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $ignored) {
            $files++;
        }
    }

    return $files;
}

/**
 * Every file git lists in the library, tracked or untracked and not ignored,
 * by its contents' digest, the gate's own directory left out, as a content
 * key reads the files of a project (ADR-0007).
 */
function listedIn(string $root): Fingerprints
{
    $listing = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard', ':(exclude).mutation-gate'], $root);
    $listing->mustRun();
    $files = Fingerprints::none();

    foreach (explode("\0", rtrim($listing->getOutput(), "\0")) as $file) {
        $files = $files->with(Fingerprint::of(Path::of($file), Digest::of(hash('sha256', (string) file_get_contents(sprintf('%s/%s', $root, $file))))));
    }

    return $files;
}

/**
 * The content key of each of the library's sources, as a run of it would
 * key that unit from the files git lists there.
 *
 * @return array<string, string>
 */
function keysOfSources(string $root): array
{
    $files = listedIn($root);
    $keys = ContentKeys::of(
        Version::of('nightworksio/mutation-gate', '1.0.0', 'abc123'),
        '{}',
        Identity::of('phpunit', Versions::none(), Digest::of('platform')),
        NoAnalyser::configured(),
        Digest::of('installed'),
        Source::of($files, CiDefinitions::none(), Exceptions::of(Absent::setting(), Path::of('mutation-gate.baseline.json'), Ignored::nothing(), Paths::none())),
        Tests::of(TestFiles::of(), Paths::none(), Paths::none()),
    );
    $byUnit = [];

    foreach ($files as $file) {
        if ($file->path()->within(Path::of('src'))) {
            $key = $keys->keyOf(Unit::file($file->path()), Paths::none(), CoverageMap::empty());
            $byUnit[$file->path()->value()] = $key instanceof Digest ? $key->value() : '';
        }
    }

    return $byUnit;
}

/** The libraries whose runner drives PHPUnit, Pest's among them. */
$drivingPhpUnit = [
    'pest' => fn(): Library => Library::installed('pest'),
    'infection' => fn(): Library => Library::installed('infection'),
    'phpunit' => fn(): Library => Library::installed('phpunit'),
];

it('keeps PHPUnit\'s caches in the gate\'s own directory, leaving every file a content key reads, and so every unit\'s key, as it was', function (Library $library): void {
    $root = $library->root();
    $before = listedIn($root);
    $keys = keysOfSources($root);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    $library->runner()->listing(Withheld::standard(), Suites::all());
    $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $library->runner()->mutate($request->narrowedTo($request->files(), Narrowing::none()->toMutators($library->mutators('adds'))));

    expect(listedIn($root))->toEqual($before)
        ->and(keysOfSources($root))->toBe($keys)
        ->and($keys)->not->toBe([])
        ->and(filesUnderCache(sprintf('%s/.phpunit.cache', $root)))->toBe(0)
        ->and(is_dir(sprintf('%s/.mutation-gate/phpunit-cache', $root)))->toBeTrue();
})->with($drivingPhpUnit)->group(...$holds);
