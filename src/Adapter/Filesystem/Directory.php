<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function basename;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fwrite;
use function is_dir;
use function is_file;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Written;

use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * A directory on disk, read and written by paths relative to it: the plain
 * file I/O the command line needs that no port covers, such as a plan, a
 * shard's results, the baseline and the report files.
 */
final readonly class Directory
{
    /** Why a path is not read or written. */
    private const string OUTSIDE = '%s leads out of %s, so the gate does not read or write it.';

    private function __construct(private Root $root)
    {
    }

    /**
     * The directory as the command line or a config spells it. The console and
     * `init` hand the project's and the vendor's roots over this way (owner:
     * flows, config).
     */
    public static function at(string $root): self
    {
        return new self(Root::of($root));
    }

    public static function in(Root $root): self
    {
        return new self($root);
    }

    /** Where the directory is, for a reporter that reads the project's files itself. */
    public function root(): Root
    {
        return $this->root;
    }

    /** What a file under the directory holds; a path that leads out of it is refused. */
    public function read(Path $path): Contents|Missing|CannotJudge
    {
        return $this->leadsOut($path) ? $this->refused($path) : $this->readInside($path);
    }

    /** Write a file, creating the directories it needs and replacing what was there; never outside the directory. */
    public function write(Path $path, Contents $contents): Written|CannotJudge
    {
        return $this->leadsOut($path) ? $this->refused($path) : $this->writeInside($path, $contents);
    }

    /**
     * Write a file piece by piece as the pieces come, creating the directories
     * it needs and replacing what was there, so a large file is never held
     * whole; never outside the directory.
     *
     * @param iterable<string> $pieces
     */
    public function stream(Path $path, iterable $pieces): Written|CannotJudge
    {
        return $this->leadsOut($path) ? $this->refused($path) : $this->streamInside($path, $pieces);
    }

    private function readInside(Path $path): Contents|Missing|CannotJudge
    {
        $file = $this->pathTo($path);

        if (! file_exists($file)) {
            return Missing::at($path);
        }

        $text = is_dir($file) ? false : file_get_contents($file);

        return $text === false ? CannotJudge::because(sprintf('%s could not be read.', $file)) : Contents::of($text);
    }

    private function writeInside(Path $path, Contents $contents): Written|CannotJudge
    {
        $file = $this->pathTo($path);

        $parent = dirname($file);

        if (is_dir($file) || is_file($parent) || (! is_dir($parent) && ! mkdir($parent, recursive: true))) {
            return CannotJudge::because(sprintf('%s could not be written.', $file));
        }

        $written = file_put_contents($file, $contents->text());

        return $written === false
            ? CannotJudge::because(sprintf('%s could not be written.', $file))
            : Written::to($file);
    }

    /** @param iterable<string> $pieces */
    private function streamInside(Path $path, iterable $pieces): Written|CannotJudge
    {
        $file = $this->pathTo($path);
        $unwritten = CannotJudge::because(sprintf('%s could not be written.', $file));

        $handle = is_dir($file) || (! is_dir(dirname($file)) && ! mkdir(dirname($file), recursive: true))
            ? false
            : fopen($file, 'wb');

        if ($handle === false) {
            return $unwritten;
        }

        $written = true;

        foreach ($pieces as $piece) {
            $written = $written && fwrite($handle, $piece) !== false;
        }

        return fclose($handle) && $written ? Written::to($file) : $unwritten;
    }

    private function pathTo(Path $path): string
    {
        return $this->root->at($path)->value();
    }

    /**
     * Whether a path leads out of the directory: it is absolute, it goes up
     * through `..`, or a link on its way leads elsewhere.
     */
    private function leadsOut(Path $path): bool
    {
        $root = self::resolved($this->root->value());
        $file = self::resolved($this->pathTo($path));

        return $path->escapes() || ($file !== $root && ! str_starts_with($file, sprintf('%s/', rtrim($root, '/'))));
    }

    private function refused(Path $path): CannotJudge
    {
        return CannotJudge::because(sprintf(self::OUTSIDE, $path->value(), $this->root->value()));
    }

    /**
     * Where a path on disk really is: the real path of the nearest part of it
     * that is there, with the rest, which is not there yet, after it.
     */
    private static function resolved(string $path): string
    {
        $real = realpath($path);
        $parent = dirname($path);

        return match (true) {
            $real !== false => $real,
            $parent === $path => $path,
            default => sprintf('%s/%s', rtrim(self::resolved($parent), '/'), basename($path)),
        };
    }
}
