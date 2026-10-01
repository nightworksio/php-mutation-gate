<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function file_get_contents;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestClassFiles;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * The files in the project's test directories that declare some test
 * classes, found by their tokens. The directories are walked once, and a file
 * is read only where it is named after a class asked about.
 */
final class TestFiles
{
    /** @var list<list<Path>> every PHP file in the test directories, once walked */
    private array $walked = [];

    public function __construct(private readonly Project $project)
    {
    }

    /** The files that declare the classes of these tests. */
    public function declaring(TestIds $tests): TestClassFiles
    {
        $classes = TestClassFiles::of($tests);

        foreach ($this->all() as $file) {
            $classes = $classes->mayDeclare($file) ? $classes->readIn($file, $this->contentsOf($file)) : $classes;
        }

        return $classes;
    }

    private function contentsOf(Path $file): Contents
    {
        return Contents::of(sprintf('%s', file_get_contents($this->project->absolute($file))));
    }

    /** @return list<Path> */
    private function all(): array
    {
        if ($this->walked === []) {
            $this->walked = [PhpFiles::in($this->project, $this->project->tests(), Paths::none())];
        }

        return $this->walked[0];
    }
}
