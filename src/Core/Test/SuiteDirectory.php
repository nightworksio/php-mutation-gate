<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;
use function str_ends_with;

/**
 * A directory of tests, as a `<testsuite>` of PHPUnit's config names it, and
 * the suffix that tells its files of test cases from the rest: its fakes,
 * helpers and fixtures.
 */
final readonly class SuiteDirectory
{
    /** The suffix PHPUnit tells a file of test cases by where a directory names none. */
    private const string SUFFIX = 'Test.php';

    private function __construct(private Path $path, private string $suffix)
    {
    }

    /** A directory of tests whose files of test cases end in a suffix; PHPUnit's own where it is empty. */
    public static function of(Path $path, string $suffix): self
    {
        return new self($path, $suffix === '' ? self::SUFFIX : $suffix);
    }

    /** Where a project keeps its tests when nothing it configures says otherwise, with PHPUnit's suffix. */
    public static function conventional(): self
    {
        return new self(TestsDirectory::conventional(), self::SUFFIX);
    }

    /** Whether a file is inside this directory. */
    public function holds(Path $file): bool
    {
        return $file->within($this->path);
    }

    /**
     * Where a file of test cases for a source file goes in this directory: at
     * the source's path from its tree, named for its class with this suffix,
     * as `tests/Unit/Domain/MoneyTest.php` for `Domain/Money.php`.
     */
    public function caseFor(Path $source): Path
    {
        return $this->path
            ->child($source->directory())
            ->child(Path::of(sprintf('%s%s', $source->stem(), $this->suffix)));
    }

    /** Whether a file is one of this directory's files of test cases. */
    public function holdsTestCase(Path $file): bool
    {
        return $this->holds($file) && str_ends_with($file->value(), $this->suffix);
    }
}
