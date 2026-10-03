<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\File\Path;

use function rtrim;
use function sprintf;

/**
 * A failing test, and the file it goes in (ADR-0015, decision 3): added to
 * the test file it follows, or the whole of a new one. It is printed unless
 * `--write` is given, and writing it never overwrites a file.
 */
final readonly class Stub
{
    private const string ADD = "// Add to %s:\n\n%s";

    private const string CREATE = "// A new file, %s:\n\n%s";

    private const string ADDED = 'Added a test for %2$s to %1$s.';

    private const string CREATED = 'Wrote %1$s, with a test for %2$s.';

    private function __construct(
        private Path $file,
        private string $test,
        private string $contents,
        private bool $new,
        private string $for,
    ) {
    }

    /** A test added to a test file, for the mutant or cluster named so. */
    public static function into(TestFile $file, string $test, string $for): self
    {
        return new self($file->path(), $test, $file->with($test), new: false, for: $for);
    }

    /** A test in a new file of its own, in a style, for the mutant or cluster named so. */
    public static function created(Path $file, string $test, AssertionStyle $style, string $for): self
    {
        return new self($file, $test, TestFile::created($file, $test, $style), new: true, for: $for);
    }

    public function file(): Path
    {
        return $this->file;
    }

    /** Whether the file is new, so writing it creates it. */
    public function isNew(): bool
    {
        return $this->new;
    }

    /** The whole file once the test is in it. */
    public function contents(): string
    {
        return $this->contents;
    }

    /** What `stub` prints: where the test goes, then the test, or the whole of a new file. */
    public function printed(): string
    {
        return $this->new
            ? sprintf(self::CREATE, $this->file->value(), rtrim($this->contents))
            : sprintf(self::ADD, $this->file->value(), $this->test);
    }

    /** What `stub --write` says it did. */
    public function written(): string
    {
        return sprintf($this->new ? self::CREATED : self::ADDED, $this->file->value(), $this->for);
    }
}
