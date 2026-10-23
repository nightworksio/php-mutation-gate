<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Autoloader;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;

use function realpath;
use function sprintf;
use function unlink;

/**
 * The project a native runner runs in: its root, its test directories, the
 * vendor directory its framework is installed in, the gate's directory, and
 * the runner, whose own directory inside it the runner writes to.
 */
final readonly class Project
{
    private const string UNWRITTEN = 'The gate cannot write %s, which the run it starts reads.';

    private function __construct(
        private Root $root,
        private Paths $tests,
        private Path $vendor,
        private Path $workspace,
        private BuiltinRunner $runner,
    ) {
    }

    /** The project at a directory, held as its real path, or as it is named where it is not there. */
    public static function at(string $root, Paths $tests, Path $vendor, Path $workspace, BuiltinRunner $runner): self
    {
        $real = realpath($root);

        return new self(Root::of($real === false ? $root : $real), $tests, $vendor, $workspace, $runner);
    }

    /**
     * The project in a directory of this one, with its tests in these, and
     * its vendor and gate's directory named as this one's are.
     */
    public function in(Path $directory, Paths $tests): self
    {
        return self::at($this->absolute($directory), $tests, $this->vendor, $this->workspace, $this->runner);
    }

    public function root(): string
    {
        return $this->root->value();
    }

    /** The directories the project's tests are in. */
    public function tests(): Paths
    {
        return $this->tests;
    }

    /** Where a path of the project is on disk. */
    public function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }

    /** A file on disk as a path of the project. */
    public function relative(string $file): Path
    {
        return $this->root->relative($file);
    }

    /** The vendor directory the framework is installed in, as a path of the project. */
    public function vendor(): Path
    {
        return $this->vendor;
    }

    /** Composer's autoloader in the vendor directory the framework is installed in. */
    public function autoloader(): string
    {
        return $this->absolute(Autoloader::fileIn($this->vendor));
    }




    /** The gate's directory, on disk. */
    public function workspace(): DiskPath
    {
        return $this->root->at($this->workspace);
    }


    /** A path of the adapter's own, inside the gate's directory, on disk. */
    public function own(string $name): string
    {
        return $this->absolute($this->ownPath($name));
    }

    /** A path of the adapter's own, inside the gate's directory, as a path of the project. */
    public function ownPath(string $name): Path
    {
        return $this->workspace->child(Path::of($this->runner->value))->child(Path::of($name));
    }

    /**
     * A file of the adapter's own, written with its directory made and in
     * place of a link where one is, never through it; or why it cannot be.
     */
    public function written(string $name, string $contents): string|CannotJudge
    {
        $file = $this->fresh($name);

        return is_string($file) && file_put_contents($file, $contents) === false
            ? CannotJudge::because(sprintf(self::UNWRITTEN, $file))
            : $file;
    }

    /**
     * A file of the adapter's own that a run the gate starts writes: its
     * directory made, and no earlier run's file, nor a link, left in its
     * place; or why it cannot be.
     */
    public function fresh(string $name): string|CannotJudge
    {
        $file = $this->own($name);
        $made = is_dir(dirname($file)) || (! is_file(dirname($file)) && mkdir(dirname($file), recursive: true));
        $gone = !is_file($file) && !is_link($file) || unlink($file);

        return $made && $gone && ! is_dir($file) ? $file : CannotJudge::because(sprintf(self::UNWRITTEN, $file));
    }
}
