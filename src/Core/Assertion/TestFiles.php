<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Test\TestName;

/**
 * The test files a verdict reads for its tests' assertions, each read once,
 * by its path, with the helpers the files that define the runner declare,
 * such as `tests/Pest.php` (ADR-0025, decision 5).
 */
final readonly class TestFiles
{
    /** @param array<string, TestAssertions> $files each file's tests, by its path */
    private function __construct(private array $files)
    {
    }

    /**
     * @param ByPath<Contents> $files       the test files
     * @param ByPath<Contents> $definitions the files that define the runner
     */
    public static function read(ByPath $files, ByPath $definitions): self
    {
        $shared = Helpers::none();

        foreach ($definitions as $contents) {
            $shared = $shared->and(Helpers::in($contents));
        }

        $read = [];

        foreach ($files as $file => $contents) {
            $read[$file->value()] = TestAssertions::in($contents, $shared);
        }

        return new self($read);
    }

    /** The assertions of a named test, read from its file; none assessed where its file was not read. */
    public function assertionsOf(TestName $named): Assertions
    {
        $file = $named->file()->value();

        return array_key_exists($file, $this->files)
            ? $this->files[$file]->of($named->description())
            : Assertions::notAssessed();
    }
}
