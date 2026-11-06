<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\OutsideTheVerdict;
use NightWorksIO\MutationGate\Core\Proof\Key\VerdictSet;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// ADR-0084: every file of the gate is either code a proof's result depends
// on, in a set of VerdictCode a key reads, or listed in OutsideTheVerdict with
// why no result depends on it; never both, never neither, and no glob of
// either names no file, so a new file lands classified.

/** @return list<string> every file of the gate's src and bin, by its path from the root */
function gateFiles(): array
{
    $root = Tree::root();
    $files = [];

    foreach (['src', 'bin'] as $top) {
        $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(sprintf('%s/%s', $root, $top), FilesystemIterator::SKIP_DOTS));

        foreach ($found as $file) {
            $files = $file instanceof SplFileInfo ? [...$files, mb_substr($file->getPathname(), mb_strlen($root) + 1)] : $files;
        }
    }

    sort($files);

    return $files;
}

/** @return list<Glob> every glob of every verdict set */
function verdictGlobs(): array
{
    return array_merge(...array_map(static fn(VerdictSet $set): array => $set->globs(), VerdictSet::cases()));
}

/** @param list<Glob> $globs */
function matchedBy(array $globs, string $file): bool
{
    return array_any($globs, static fn(Glob $glob): bool => $glob->matches(Path::of($file)));
}

it('holds every file of the gate in a verdict set or outside the verdict, and none in both', function (): void {
    $verdict = verdictGlobs();
    $outside = OutsideTheVerdict::globs();
    $offenders = [];

    foreach (gateFiles() as $file) {
        $in = matchedBy($verdict, $file);
        $out = matchedBy($outside, $file);
        $offenders = $in === $out ? [...$offenders, sprintf('%s is %s', $file, $in ? 'in both' : 'in neither')] : $offenders;
    }

    expect($offenders)->toBe([], sprintf(
        "These files are not classified once:\n  %s\n\nA file a mutant's run, or the reading of its record, depends on goes in a set of VerdictSet; any other in OutsideTheVerdict, under why no result depends on it (ADR-0084).",
        implode("\n  ", $offenders),
    ));
});

it('names no glob in either that matches no file', function (): void {
    $files = gateFiles();
    $stale = array_filter(
        [...verdictGlobs(), ...OutsideTheVerdict::globs()],
        static fn(Glob $glob): bool => ! array_any($files, static fn(string $file): bool => $glob->matches(Path::of($file))),
    );

    expect(array_values(array_map(static fn(Glob $glob): string => $glob->value(), $stale)))->toBe([]);
});
