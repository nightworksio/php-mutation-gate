<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

use function str_ends_with;

/**
 * What the reach rules read: files as they are on disk, and changed files as
 * they were at the base.
 */
final readonly class Sources
{
    /** How the name of a PHP file ends. */
    private const string PHP = '.php';

    /**
     * @param ByPath<Contents|Missing> $now    each file as it is on disk
     * @param ByPath<Contents|Missing> $before each changed file as it was at the base
     */
    private function __construct(private ByPath $now, private ByPath $before)
    {
    }

    public static function none(): self
    {
        return new self(ByPath::none(), ByPath::none());
    }

    /**
     * Files as a change source reads them together: as they are on disk, and
     * the changed ones as they were at the base.
     *
     * @param ByPath<Contents|Missing> $now
     * @param ByPath<Contents|Missing> $before
     */
    public static function of(ByPath $now, ByPath $before): self
    {
        return new self($now, $before);
    }

    /** These, with what a file holds on disk. */
    public function withNow(Path $path, Contents $contents): self
    {
        return new self($this->now->with($path, $contents), $this->before);
    }

    /** These, with what a changed file held at the base. */
    public function withBefore(Path $path, Contents $contents): self
    {
        return new self($this->now, $this->before->with($path, $contents));
    }

    public function now(Path $path): Contents|Missing
    {
        return $this->now->at($path, Missing::at($path));
    }

    public function before(Path $path): Contents|Missing
    {
        return $this->before->at($path, Missing::at($path));
    }

    /**
     * Every PHP file on disk, read, in the order the files came.
     *
     * @return ByPath<PhpFile>
     */
    public function php(): ByPath
    {
        $read = [];
        $paths = [];

        foreach ($this->now as $path => $contents) {
            if ($contents instanceof Contents && str_ends_with($path->value(), self::PHP)) {
                $read[$path->value()] = PhpFile::read($contents);
                $paths[] = $path;
            }
        }

        return ByPath::mapping(Paths::of(...$paths), static fn(Path $path): PhpFile => $read[$path->value()]);
    }
}
