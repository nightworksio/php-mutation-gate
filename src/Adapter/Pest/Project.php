<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function is_dir;
use function is_file;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function unlink;

/**
 * The project Pest runs in: its root, the directories its tests live in, and
 * the directory the gate works in. Pest reports files by their real path, so
 * the root is held as its real path too.
 */
final readonly class Project
{
    private const string RESULTS = 'pest/results.jsonl';

    private function __construct(private string $root, private Paths $tests, private Path $workspace)
    {
    }

    public static function at(string $root, Paths $tests, Path $workspace): self
    {
        $real = realpath($root);

        return new self(is_string($real) ? $real : rtrim($root, '/'), $tests, $workspace);
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

    /** The file the plugin writes a run's results to, with no earlier run's results left in it. */
    public function freshResults(): string
    {
        $results = sprintf('%s/%s', $this->absolute($this->workspace), self::RESULTS);
        $this->directory(Path::of(sprintf('%s/pest', $this->workspace->value())));

        foreach ([$results, Recorder::coverageBeside($results)] as $stale) {
            if (is_file($stale)) {
                unlink($stale);
            }
        }

        return $results;
    }
}
