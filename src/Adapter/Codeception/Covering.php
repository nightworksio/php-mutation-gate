<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

use function array_diff;
use function get_included_files;

use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Native\OwnFiles;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

/**
 * Measures the lines of the project's own files each test the run selects
 * runs, and how long it took, with php-code-coverage, and writes the map
 * once the run is done, as PHPUnit's `--coverage-php` writes it, which the
 * native runner reads. Each file PHP loaded by a test's end is one the map
 * measures where it is the project's own, so no driver measures a file of
 * the vendor directory.
 */
final class Covering
{
    /** @var list<string> the files PHP loaded that the map has been told of */
    private array $told = [];

    /** @param non-empty-string $map */
    public function __construct(
        private readonly string $map,
        private readonly CodeCoverage $coverage,
        private readonly OwnFiles $own,
    ) {
    }

    public function started(Candidate $test): void
    {
        $this->coverage->start($test->key());
    }

    /** The test started last ended, after this many seconds. */
    public function ended(float $took): void
    {
        $loaded = get_included_files();
        $this->coverage->filter()->includeFiles($this->own->among(array_diff($loaded, $this->told)));
        $this->told = $loaded;
        $append = true;
        $this->coverage->stop($append, null, null, null, $took);
    }

    public function written(): void
    {
        new Serializer()->serialize($this->map, $this->coverage);
    }
}
