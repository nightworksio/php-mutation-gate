<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function array_filter;
use function array_map;
use function array_sum;
use function is_array;
use function is_dir;
use function is_float;
use function is_link;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\Cost\LinesOfCode;
use NightWorksIO\MutationGate\Core\Cost\SecondsPerLine;
use NightWorksIO\MutationGate\Core\Cost\Shares;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CostModel;

use function scandir;

/**
 * The cost model `learned`: a unit costs what a shard last measured it to
 * take, the newest measurement winning. A unit no shard has measured is
 * estimated as its lines of code times `costs.secondsPerLine` for its path,
 * read from the files on disk; a held path that is a directory is the sum of
 * the PHP files under it.
 */
final readonly class MeasuredCosts implements Configurable, CostModel
{
    private const string PER_LINE = 'secondsPerLine';

    private function __construct(private Root $root, private SecondsPerLine $perLine)
    {
    }

    /** Costs of the files under this directory, by these seconds per line. */
    public static function at(Root $root, SecondsPerLine $perLine): self
    {
        return new self($root, $perLine);
    }

    /** `{"secondsPerLine": {"": 0.2, "src/Http": 0.5}}`, as `costs.secondsPerLine`, read from the working directory. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $perLine = $options->object(Key::of(self::PER_LINE));

        if ($perLine instanceof NotGiven) {
            return Invalid::because(Problem::at(self::PER_LINE, 'expected the seconds a line costs, got nothing'));
        }

        if ($perLine instanceof Problem) {
            return Invalid::because($perLine);
        }

        $rates = [];

        foreach ($perLine as $prefix) {
            $seconds = $perLine->number($prefix);
            $rates[] = is_float($seconds) ? LineRate::of($prefix->value(), Seconds::of($seconds)) : $seconds;
        }

        $problems = [...array_filter(
            $rates,
            static fn(LineRate|Problem|NotGiven $rate): bool => $rate instanceof Problem,
        )];

        return $problems === []
            ? self::at(
                Root::here(),
                SecondsPerLine::of(...array_filter(
                    $rates,
                    static fn(LineRate|Problem|NotGiven $rate): bool => $rate instanceof LineRate,
                )),
            )
            : Invalid::because(...$problems);
    }

    public function cost(Unit $unit, Timings $learned): Seconds
    {
        $measured = $learned->secondsFor($unit->path());

        $perLine = $this->perLine->forPath($unit->path())->seconds();

        return $measured instanceof Seconds ? $measured : Seconds::of($this->linesIn($unit->path()) * $perLine);
    }

    public function learn(Units $units, Mutants $mutants, CoverageMap $coverage, Measurement $measured): Timings
    {
        return Shares::of($units, $mutants, $coverage, $measured);
    }

    private function linesIn(Path $path): int
    {
        $files = $this->isDirectory($path) ? $this->phpUnder($path) : [$path];

        return array_sum(array_map($this->linesInFile(...), $files));
    }

    private function linesInFile(Path $file): int
    {
        $source = Directory::in($this->root)->read($file);

        return $source instanceof Contents ? LinesOfCode::in($source) : 0;
    }

    private function isDirectory(Path $path): bool
    {
        return is_dir($this->root->at($path)->value());
    }

    /** Whether a path is a link to a directory, which is not followed, since it may lead back up into a loop. */
    private function isLinkedDirectory(Path $path): bool
    {
        return is_link($this->root->at($path)->value()) && $this->isDirectory($path);
    }

    /** @return list<Path> */
    private function phpUnder(Path $directory): array
    {
        $entries = scandir($this->root->at($directory)->value());
        $found = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $path = $directory->child(Path::of($entry));
            $found = [...$found, ...match (true) {
                $entry === '.' || $entry === '..' || $this->isLinkedDirectory($path) => [],
                $this->isDirectory($path) => $this->phpUnder($path),
                $path->isPhp() => [$path],
                default => [],
            }];
        }

        return $found;
    }
}
