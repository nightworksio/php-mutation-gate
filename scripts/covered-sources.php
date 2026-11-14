<?php

declare(strict_types=1);

// The sources G7 holds to every line covered, for the scripts that hold the
// floor: each PHP file of the sources phpunit.xml includes, less the files
// scripts/coverage-exemptions.php names.

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/** @return list<string> each file G7 leaves off its floor, by its absolute path */
function exemptFromCoverage(string $root): array
{
    $exempt = require __DIR__ . '/coverage-exemptions.php';

    return array_map(static fn(string $path): string => "{$root}/{$path}", array_keys($exempt));
}

/** @return list<string> every PHP file of the sources phpunit.xml includes that G7 holds, by its absolute path */
function coveredSources(string $root): array
{
    $xml = simplexml_load_file("{$root}/phpunit.xml") ?: throw new RuntimeException('phpunit.xml cannot be read.');
    $files = [];

    foreach ($xml->source->include->directory ?? [] as $pattern) {
        foreach (glob("{$root}/{$pattern}", GLOB_ONLYDIR) ?: [] as $directory) {
            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

            foreach ($walk as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = (string) $file->getRealPath();
                }
            }
        }
    }

    return array_values(array_diff($files, exemptFromCoverage($root)));
}

/** Leaves the lines of each file G7 leaves off its floor out of a report's data. */
function withoutExemptions(ProcessedCodeCoverageData $data, string $root): void
{
    $exempt = array_flip(exemptFromCoverage($root));
    $data->setLineCoverage(array_diff_key($data->lineCoverage(), $exempt));
    $data->setFunctionCoverage(array_diff_key($data->functionCoverage(), $exempt));
}
