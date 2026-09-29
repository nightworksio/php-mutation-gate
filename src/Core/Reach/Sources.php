<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
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
     * @param array<string, array{Path, Contents}> $now    each file on disk, by its path
     * @param array<string, Contents>              $before each changed file at the base, by its path
     */
    private function __construct(private array $now, private array $before)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** These, with what a file holds on disk. */
    public function withNow(Path $path, Contents $contents): self
    {
        $now = $this->now;
        $now[$path->value()] = [$path, $contents];

        return new self($now, $this->before);
    }

    /** These, with what a changed file held at the base. */
    public function withBefore(Path $path, Contents $contents): self
    {
        $before = $this->before;
        $before[$path->value()] = $contents;

        return new self($this->now, $before);
    }

    public function now(Path $path): Contents|Missing
    {
        return array_key_exists($path->value(), $this->now) ? $this->now[$path->value()][1] : Missing::at($path);
    }

    public function before(Path $path): Contents|Missing
    {
        return array_key_exists($path->value(), $this->before) ? $this->before[$path->value()] : Missing::at($path);
    }

    /**
     * Every PHP file on disk, read.
     *
     * @return list<array{Path, PhpFile}>
     */
    public function php(): array
    {
        $read = [];

        foreach ($this->now as [$path, $contents]) {
            $read = str_ends_with($path->value(), self::PHP) ? [...$read, [$path, PhpFile::read($contents)]] : $read;
        }

        return $read;
    }
}
