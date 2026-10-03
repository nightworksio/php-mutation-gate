<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Paths;
use Traversable;

/**
 * Every file under the test directories, one per path; a later file of a path
 * replaces the earlier.
 *
 * @implements IteratorAggregate<int, TestFile>
 */
final readonly class TestFiles implements Countable, IteratorAggregate
{
    /** @param array<string, TestFile> $files by path */
    private function __construct(private array $files)
    {
    }

    public static function of(TestFile ...$files): self
    {
        $collected = [];

        foreach ($files as $file) {
            $collected[$file->fingerprint()->path()->value()] = $file;
        }

        return new self($collected);
    }

    /** The files that declare one of these classes, each named in full, matched by its last segment. */
    public function declaring(string ...$classes): Paths
    {
        $declaring = Paths::none();

        foreach ($this->files as $file) {
            $declaring = $file->php()->declaresAnyOf(...$classes)
                ? $declaring->with($file->fingerprint()->path())
                : $declaring;
        }

        return $declaring;
    }

    public function count(): int
    {
        return count($this->files);
    }

    /** @return Traversable<int, TestFile> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->files));
    }
}
