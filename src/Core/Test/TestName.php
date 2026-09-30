<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * A whole test as its JUnit entry names it: the file it is in and its
 * description, such as `tests/Unit/MoneyTest.php::it adds`. The rows of a
 * data set fold into it, and it is what the reports judge (ADR-0014,
 * decision 6).
 */
final readonly class TestName
{
    private function __construct(private Path $file, private string $description)
    {
    }

    public static function in(Path $file, string $description): self
    {
        return new self($file, $description);
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** `tests/Unit/MoneyTest.php::it adds`. */
    public function value(): string
    {
        return sprintf('%s::%s', $this->file->value(), $this->description);
    }
}
