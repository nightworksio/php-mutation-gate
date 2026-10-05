<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

// G9: a diagnostic fails the run. PHP raises one as it compiles a file, which
// a run that loads the file and runs none of its tests still fails on.

/** The trees PHP compiles: the package's code, its plugins and its tests. */
const COMPILED = ['src', 'plugins', 'tests'];

/** Fewer files than this is a scan of the wrong tree, not a quiet one. */
const AT_LEAST = 1000;

/** How many files one `php -l` takes, well under any limit on its arguments. */
const PER_LINT = 500;

it('compiles every file without a warning or a deprecation', function (): void {
    $files = array_merge(...array_map(static fn(string $tree): array => Tree::filesUnder($tree), COMPILED));
    $said = '';

    foreach (array_chunk($files, PER_LINT) as $chunk) {
        $lint = new Process([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stdout', '-d', 'log_errors=0', '-l', ...$chunk], Tree::root());
        $said .= $lint->mustRun()->getOutput();
    }

    preg_match_all('/^(?:Warning|Deprecated|Notice): .+$/mu', $said, $raised);

    // G9
    expect(count($files))->toBeGreaterThanOrEqual(AT_LEAST)
        ->and(substr_count($said, 'No syntax errors detected'))->toBe(count($files))
        ->and($raised[0])->toBe([], sprintf(
            "PHP raises these as it compiles the files:\n  %s\n\nA run that loads one fails on it, whichever tests it runs (G9).",
            implode("\n  ", $raised[0]),
        ));
});
