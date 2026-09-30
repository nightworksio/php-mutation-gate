<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Composer;

use Closure;

use function file_get_contents;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_readable;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

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
     * The directories a shell glob matches, from the root.
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

    private function on(Path $path): string
    {
        return $this->root->at($path)->value();
    }
}
