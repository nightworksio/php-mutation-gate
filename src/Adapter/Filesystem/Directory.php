<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function basename;
use function chmod;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function fopen;
use function fwrite;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function is_writable;
use function max;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Written;

use function realpath;
use function rename;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function tempnam;
use function umask;
use function unlink;

/**
 * A directory on disk, read and written by paths relative to it: the plain
 * file I/O the command line needs that no port covers, such as a plan, a
 * shard's results, the baseline and the report files. It never writes
 * through a link: a path whose last part is one, there or dangling, is
 * refused, and every file is written whole beside its place and then moved
 * into it, so a link put there meanwhile is replaced, never followed.
 */
final readonly class Directory
{
    /** Why a path is not read or written. */
    private const string OUTSIDE = '%s leads out of %s, so the gate does not read or write it.';

    /** Why a path is not written. */
    private const string LINKED = '%s is a link, so the gate does not write through it.';

    /** How the name of a file being written, beside its place, begins. */
    private const string WRITING = '.%s.';

    /** Whom a new file is open to before the umask takes its part away. */
    private const int OPEN = 0o666;

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

    /**
     * What a file under the directory holds, read no further than this many bytes, so a file past them is refused
     * without being held whole; a path that leads out of it is refused.
     */
    public function readAtMost(Path $path, int $bytes): Contents|Missing|TooLarge|CannotJudge
    {
        $file = $this->pathTo($path);
        $text = match (true) {
            $this->leadsOut($path) => $this->refused($path),
            ! file_exists($file) => Missing::at($path),
            is_dir($file) => false,
            default => file_get_contents($file, length: max(0, $bytes) + 1),
        };

        return match (true) {
            $text === false => CannotJudge::because(sprintf('%s could not be read.', $file)),
            ! is_string($text) => $text,
            Bytes::length($text) > $bytes
                => TooLarge::because(sprintf('%s is past %d bytes, so it is not read.', $file, $bytes)),
            default => Contents::of($text),
        };
    }

    /** Write a file, creating the directories it needs and replacing what was there; never outside the directory. */
    public function write(Path $path, Contents $contents): Written|CannotJudge
    {
        return $this->stream($path, [$contents->text()]);
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
        $file = $this->pathTo($path);

        return match (true) {
            $this->leadsOut($path) => $this->refused($path),
            is_link($file) => CannotJudge::because(sprintf(self::LINKED, $file)),
            default => $this->streamInside($path, $pieces),
        };
    }

    /** Remove a file under the directory, or a link where one is, there or dangling; never outside the directory. */
    public function remove(Path $path): Missing|CannotJudge
    {
        $file = $this->pathTo($path);
        $there = is_file($file) || is_link($file);

        return match (true) {
            $this->leadsOut($path) => $this->refused($path),
            $there && (! is_writable(dirname($file)) || ! unlink($file)) => CannotJudge::because(
                sprintf('%s could not be removed.', $file),
            ),
            default => Missing::at($path),
        };
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

    /**
     * The pieces written to a file made beside the path's place, which is
     * then moved over it whole; where any step fails, that file is removed.
     *
     * @param iterable<string> $pieces
     */
    private function streamInside(Path $path, iterable $pieces): Written|CannotJudge
    {
        $file = $this->pathTo($path);
        $writing = $this->beside($file);
        $handle = $writing === false ? false : fopen($writing, 'wb');

        if ($handle === false) {
            return Written::failedAt($file);
        }

        $written = true;

        foreach ($pieces as $piece) {
            $written = $written && fwrite($handle, $piece) !== false;
        }

        $moved = fclose($handle) && $written && rename($writing, $file);

        return $moved ? Written::to($file) : $this->leftUnwritten($writing, $file);
    }

    /**
     * A new, empty file in the directory a file goes in, made there under a
     * name no other file has and open to whom a file written there is; false
     * where none can be made there, and none is left elsewhere.
     */
    private function beside(string $file): string|false
    {
        $parent = dirname($file);
        $made = ! is_dir($file) && (is_dir($parent) || (! is_file($parent) && mkdir($parent, recursive: true)));
        $writing = $made ? tempnam($parent, sprintf(self::WRITING, basename($file))) : false;

        $there = $writing !== false && dirname($writing) === realpath($parent);

        if ($writing !== false && (! $there || ! chmod($writing, self::OPEN & ~umask()))) {
            unlink($writing);

            return false;
        }

        return $writing;
    }

    /** Why a file was not written, its unfinished copy beside it removed. */
    private function leftUnwritten(string $writing, string $file): CannotJudge
    {
        unlink($writing);

        return Written::failedAt($file);
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
