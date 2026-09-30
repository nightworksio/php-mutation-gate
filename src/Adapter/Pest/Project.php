<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_exists;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function unlink;

/**
 * The project Pest runs in: its root, the directories its tests live in, the
 * directory the gate works in, and the one Composer installed its packages
 * in. Pest reports files by their real path, so the root is held as its real
 * path too.
 */
final readonly class Project
{
    private const string RESULTS = 'pest/results.jsonl';

    /** Why a run cannot be told from an earlier one. */
    private const string STALE = 'An earlier run left %s or the map beside it, and the gate cannot remove them.';

    private function __construct(
        private string $root,
        private Paths $tests,
        private Path $workspace,
        private Path $vendor,
    ) {
    }

    public static function at(string $root, Paths $tests, Path $workspace, Path $vendor): self
    {
        $real = realpath($root);

        return new self(is_string($real) ? $real : rtrim($root, '/'), $tests, $workspace, $vendor);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return Paths the directories the tests live in */
    public function tests(): Paths
    {
        return $this->tests;
    }

    /** The directory Composer installed the project's packages in, Pest's among them. */
    public function vendor(): Path
    {
        return $this->vendor;
    }

    /** Where a path of the project is on disk; an absolute path is where it says. */
    public function absolute(Path $path): string
    {
        return str_starts_with($path->value(), '/') ? $path->value() : sprintf('%s/%s', $this->root, $path->value());
    }

    /** A file on disk as the project spells it, or as it is where it lies outside the project. */
    public function relative(string $file): Path
    {
        $prefix = sprintf('%s/', $this->root);

        return Path::of(str_starts_with($file, $prefix) ? mb_substr($file, mb_strlen($prefix)) : $file);
    }

    /** A directory of the project on disk, made where it is not there yet. */
    public function directory(Path $path): string
    {
        $directory = $this->absolute($path);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        return $directory;
    }

    /**
     * The file the plugin writes a run's results to, with no earlier run's
     * results, map or mutated copies left beside it, or why an earlier run's
     * are still there.
     */
    public function freshResults(): string|CannotJudge
    {
        $results = sprintf('%s/%s', $this->absolute($this->workspace), self::RESULTS);
        $this->directory(Path::of(sprintf('%s/pest', $this->workspace->value())));
        $copies = glob(Recorder::mutantBeside($results, '*'));

        return $this->without($results, Recorder::coverageBeside($results), ...(is_array($copies) ? $copies : []))
            ? $results
            : CannotJudge::because(sprintf(self::STALE, $results));
    }

    /** Whether none of these files is there once each that is has been removed. */
    public function without(string ...$files): bool
    {
        $gone = true;

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }

            $gone = $gone && ! file_exists($file);
        }

        return $gone;
    }
}
