<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitIni;

use function realpath;
use function sprintf;
use function unlink;

/**
 * The project PHPUnit runs in: its root, its test directories, the vendor
 * directory PHPUnit is installed in, and the gate's directory.
 */
final readonly class Project
{
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

    /** The project at a directory, held as its real path, or as it is named where it is not there. */
    public static function at(string $root, Paths $tests, Path $vendor, Path $workspace): self
    {
        $real = realpath($root);

        return new self(Root::of($real === false ? $root : $real), $tests, $vendor, $workspace);
    }

    /** The project in a directory of this one, with its tests, vendor and gate's directory named as this one's are. */
    public function in(Path $directory): self
    {
        return self::at($this->absolute($directory), $this->tests, $this->vendor, $this->workspace);
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

    /** The vendor directory PHPUnit is installed in, as a path of the project. */
    public function vendor(): Path
    {
        return $this->vendor;
    }

    public function phpunit(): string
    {
        return $this->absolute($this->vendor->child(Path::of(self::PHPUNIT)));
    }

    /** Whether PHPUnit's script is in the project's vendor directory, so the project holds a PHPUnit to run. */
    public function hasPhpUnit(): bool
    {
        return is_file($this->phpunit());
    }

    /** Where Composer lists what it installed in the project's vendor directory. */
    public function installed(): string
    {
        return $this->absolute(Installed::fileIn($this->vendor));
    }

    /** The gate's directory, on disk. */
    public function workspace(): DiskPath
    {
        return $this->root->at($this->workspace);
    }

    /**
     * Where the PHPUnit config PHPUnit reads in the root has PHP print
     * errors, as its `<php>` sets `display_errors`; none where it sets none,
     * or the project has no config.
     */
    public function errorDisplay(): ErrorDisplay|NotGiven
    {
        foreach (PhpUnitConfig::candidatesIn(Path::root()) as $candidate) {
            if (is_file($this->absolute($candidate))) {
                return PhpUnitIni::displayIn(sprintf('%s', file_get_contents($this->absolute($candidate))));
            }
        }

        return NotGiven::value();
    }

    /** A path of the adapter's own, inside the gate's directory, on disk. */
    public function own(string $name): string
    {
        return $this->absolute($this->ownPath($name));
    }

    /** A path of the adapter's own, inside the gate's directory, as a path of the project. */
    public function ownPath(string $name): Path
    {
        return $this->workspace->child(Path::of(BuiltinRunner::PhpUnit->value))->child(Path::of($name));
    }

    /**
     * A file of the adapter's own, written with its directory made and in
     * place of a link where one is, never through it; or why it cannot be.
     */
    public function written(string $name, string $contents): string|CannotJudge
    {
        $file = $this->own($name);
        $made = is_dir(dirname($file)) || (! is_file(dirname($file)) && mkdir(dirname($file), recursive: true));
        $unlinked = ! is_link($file) || unlink($file);

        return $made && $unlinked && ! is_dir($file) && file_put_contents($file, $contents) !== false
            ? $file
            : CannotJudge::because(sprintf(self::UNWRITTEN, $file));
    }
}
