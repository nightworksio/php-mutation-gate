<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Composer;

use function array_diff;
use function array_values;

use Closure;

use function count;
use function explode;
use function file_get_contents;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

use function scandir;
use function sprintf;

/** The directory a project lives in, read by paths as the repository spells them. */
final readonly class Disk
{
    private function __construct(private Root $root)
    {
    }

    public static function at(Root $root): self
    {
        return new self($root);
    }

    /** What a file holds. */
    public function read(Path $file): string|Missing|CannotJudge
    {
        $on = $this->on($file);

        if (! is_file($on)) {
            return Missing::at($file);
        }

        $text = is_readable($on) ? file_get_contents($on) : false;

        return is_string($text) ? $text : CannotJudge::because(sprintf('%s could not be read.', $file->value()));
    }

    /** The manifest in a directory, where there is one. */
    public function manifestIn(Path $directory): Manifest|Missing|CannotJudge
    {
        $read = $this->read(Manifest::fileIn($directory));

        return is_string($read) ? Manifest::decode(Contents::of($read), $directory) : $read;
    }

    public function isFile(Path $file): bool
    {
        return is_file($this->on($file));
    }

    /**
     * The directories a glob of the config matches, from the root, each level
     * in name order: walked from the directory before its first wildcard, as
     * deep as a path it matches goes, and never into a link.
     *
     * @return list<Path>
     */
    public function directoriesMatching(Glob $glob): array
    {
        return $this->walked($glob->base(), $glob);
    }

    /**
     * The directories a shell glob matches, from the root, as Composer reads
     * the `url` of a path repository.
     *
     * @return list<Path>
     */
    public function directories(string $pattern): array
    {
        return $this->matching($pattern, is_dir(...));
    }

    /**
     * The files a shell glob matches, from the root.
     *
     * @return list<Path>
     */
    public function files(string $pattern): array
    {
        return $this->matching($pattern, is_file(...));
    }

    /**
     * What a shell glob matches, from the root, that passes a test.
     *
     * @param  Closure(string): bool $passes
     * @return list<Path>
     */
    private function matching(string $pattern, Closure $passes): array
    {
        $matched = glob($this->root->path()->child($pattern)->value());
        $found = [];

        foreach (is_array($matched) ? $matched : [] as $path) {
            $found = $passes($path) ? [...$found, $this->root->relative($path)] : $found;
        }

        return $found;
    }

    /**
     * A directory, where the glob matches it, and every directory under it it can match.
     *
     * @return list<Path>
     */
    private function walked(Path $directory, Glob $glob): array
    {
        $isDirectory = is_dir($this->on($directory));
        $found = $isDirectory && ! $directory->equals(Path::root()) && $glob->matches($directory) ? [$directory] : [];

        foreach ($this->below($directory, $glob) as $entry) {
            $found = [...$found, ...$this->walked($directory->child(Path::of($entry)), $glob)];
        }

        return $found;
    }

    /**
     * The entries of a directory a glob can match something at or under: none
     * where the directory is as deep as a path it matches goes, is a link, or
     * is no directory.
     *
     * @return list<string>
     */
    private function below(Path $directory, Glob $glob): array
    {
        $on = $this->on($directory);
        $depth = $directory->equals(Path::root()) ? 0 : count(explode('/', $directory->value()));
        $entries = $depth < $glob->depth() && is_dir($on) && ! is_link($on) ? scandir($on) : [];

        return array_values(array_diff(is_array($entries) ? $entries : [], ['.', '..']));
    }

    private function on(Path $path): string
    {
        return $this->root->at($path)->value();
    }
}
