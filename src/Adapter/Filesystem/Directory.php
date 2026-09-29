<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Written;

use function rtrim;
use function sprintf;

/**
 * A directory on disk, read and written by paths relative to it: the plain
 * file I/O the command line needs that no port covers, such as a plan, a
 * shard's results, the baseline and the report files.
 */
final readonly class Directory
{
    private function __construct(private string $root) {}

    public static function at(string $root): self
    {
        return new self(rtrim($root, '/'));
    }

    public function read(Path $path): Contents|Missing|CannotJudge
    {
        $file = $this->pathTo($path);

        if (! file_exists($file)) {
            return Missing::at($path);
        }

        $text = is_dir($file) ? false : file_get_contents($file);

        return $text === false ? CannotJudge::because(sprintf('%s could not be read.', $file)) : Contents::of($text);
    }

    /** Write a file, creating the directories it needs and replacing what was there. */
    public function write(Path $path, Contents $contents): Written|CannotJudge
    {
        $file = $this->pathTo($path);

        if (is_dir($file) || (! is_dir(dirname($file)) && ! mkdir(dirname($file), recursive: true))) {
            return CannotJudge::because(sprintf('%s could not be written.', $file));
        }

        return file_put_contents($file, $contents->text()) === false ? CannotJudge::because(sprintf('%s could not be written.', $file)) : Written::to($file);
    }

    private function pathTo(Path $path): string
    {
        return sprintf('%s/%s', $this->root, $path->value());
    }
}
