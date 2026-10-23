<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function implode;

use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;

use function sprintf;

/**
 * How a mutant's run selects the tests that cover it: the file in its
 * directory that lists them, one to a line, the option that has the
 * framework read that file, and whether the file names exactly those tests
 * or the files that hold them, which runs their other tests too.
 */
final readonly class Selection
{
    /**
     * @param list<string> $lines what the file lists, one to a line
     */
    private function __construct(
        private string $file,
        private array $lines,
        private string $option,
        private bool $exact,
    ) {
    }

    /**
     * Exactly these tests, listed in a file of this name, which the option
     * names where it holds `%s`.
     *
     * @param list<string> $lines
     */
    public static function exactly(string $file, array $lines, string $option): self
    {
        return new self($file, $lines, $option, exact: true);
    }

    /**
     * Every test of the listed files, in a file of this name, which the
     * option names where it holds `%s`.
     *
     * @param list<string> $lines
     */
    public static function byFile(string $file, array $lines, string $option): self
    {
        return new self($file, $lines, $option, exact: false);
    }

    /** The name of the file the selection is written to, in the mutant's directory. */
    public function file(): string
    {
        return $this->file;
    }

    /** @return list<string> what the file lists, one to a line */
    public function lines(): array
    {
        return $this->lines;
    }

    /** What the file holds: each line, ended. */
    public function contents(): string
    {
        return sprintf('%s%s', implode(PhpUnitOption::LINE_END, $this->lines), PhpUnitOption::LINE_END);
    }

    /** The option that has the framework read the selection from the file at this path. */
    public function option(string $written): string
    {
        return sprintf($this->option, $written);
    }

    /** Whether the run selects exactly the tests that cover the mutant, whose times are known. */
    public function isExact(): bool
    {
        return $this->exact;
    }
}
