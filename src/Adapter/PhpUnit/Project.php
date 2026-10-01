<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;

use function realpath;
use function sprintf;

/**
 * The project PHPUnit runs in: its root, its test directories, the vendor
 * directory PHPUnit is installed in, and the gate's directory.
 */
final readonly class Project
{
    /** The adapter's own directory in the gate's. */
    private const string OWN = 'phpunit';

    /** PHPUnit's script, in the vendor directory's `bin`. */
    private const string PHPUNIT = 'bin/phpunit';

    private const string UNWRITTEN = 'The gate cannot write %s, which the PHPUnit it starts reads.';

    private function __construct(
        private Root $root,
        private Paths $tests,
        private Path $vendor,
        private Path $workspace,
    ) {
    }

    /** The project at a directory, held as its real path. */
    public static function at(string $root, Paths $tests, Path $vendor, Path $workspace): self
    {
        return new self(Root::of((string) realpath($root)), $tests, $vendor, $workspace);
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

    public function phpunit(): string
    {
        return $this->absolute($this->vendor->child(Path::of(self::PHPUNIT)));
    }

    /** A path of the adapter's own, inside the gate's directory. */
    public function own(string $name): string
    {
        return $this->absolute($this->workspace->child(Path::of(self::OWN))->child(Path::of($name)));
    }

    /** A file of the adapter's own, written with its directory made, or why it cannot be. */
    public function written(string $name, string $contents): string|CannotJudge
    {
        $file = $this->own($name);
        $made = is_dir(dirname($file)) || (! is_file(dirname($file)) && mkdir(dirname($file), recursive: true));

        return $made && ! is_dir($file) && file_put_contents($file, $contents) !== false
            ? $file
            : CannotJudge::because(sprintf(self::UNWRITTEN, $file));
    }
}
