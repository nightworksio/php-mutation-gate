<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function dirname;
use function is_dir;
use function is_file;
use function is_string;
use function is_writable;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;

use function realpath;
use function sprintf;
use function unlink;

/**
 * The project Infection runs in: its root, the directories its tests live in,
 * and the directory the gate works in. Infection reports files by the paths
 * its config names, which the adapter writes from the root's real path.
 */
final readonly class Project
{
    private const string STALE
        = 'The gate cannot remove %s, so it cannot tell what this run wrote from what an earlier one did.';

    private function __construct(private Root $root, private Paths $tests, private Path $workspace)
    {
    }

    /** The project at a root, held as its real path. */
    public static function at(Root $root, Paths $tests, Path $workspace): self
    {
        return new self(Root::of(self::real($root->value())), $tests, $workspace);
    }

    /** The same project in one of its directories: the tests and the gate's directory are that directory's. */
    public function in(Path $directory): self
    {
        return self::at(Root::of($this->absolute($directory)), $this->tests, $this->workspace);
    }

    /** The root's real path, as Infection's config and the coverage layout write it. */
    public function root(): string
    {
        return $this->root->value();
    }

    /** @return Paths the directories the tests live in */
    public function tests(): Paths
    {
        return $this->tests;
    }

    /** Where a path of the project is on disk; the root is the root, and an absolute path is where it says. */
    public function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }

    /** A file on disk as the project spells it, the root as the root, or as it is where it lies outside the project. */
    public function relative(string $file): Path
    {
        return $this->root->relative($file);
    }

    /** A directory of the project on disk, made where it is not there yet. */
    public function directory(Path $path): DiskPath
    {
        $directory = $this->root->at($path);

        if (! is_dir($directory->value())) {
            mkdir($directory->value(), recursive: true);
        }

        return $directory;
    }

    /**
     * A file on disk with its directory made and no earlier run's copy of it
     * left, or why an earlier copy is still there: a run that fails to write
     * it must not be read from what the one before it wrote.
     */
    public function fresh(string $file): string|CannotJudge
    {
        $this->directory(Path::of(dirname($file)));

        if (is_file($file) && is_writable(dirname($file))) {
            unlink($file);
        }

        return is_file($file) ? CannotJudge::because(sprintf(self::STALE, $file)) : $file;
    }

    /** A path of the adapter's own, inside the gate's directory. */
    public function own(string $name): string
    {
        return $this->root->at(
            $this->workspace->child(Path::of(BuiltinRunner::Infection->value))->child(Path::of($name)),
        )->value();
    }

    /** A directory as its real path where it is there, so that it compares with the real paths the runner reports. */
    private static function real(string $directory): string
    {
        $real = realpath($directory);

        return is_string($real) ? $real : $directory;
    }
}
