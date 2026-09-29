<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
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
