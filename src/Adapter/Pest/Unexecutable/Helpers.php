<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_any;
use function array_key_exists;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Php\References;
use NightWorksIO\MutationGate\Core\Php\Site;

/**
 * The test files that run a helper: a file under the test directories that
 * holds no test the map names, such as a support class or a file of
 * functions. Those are the files of the suite that name a class or function
 * the helper declares, or that name another helper that does, in turn. A
 * helper can also run where no name shows it, as a base test case's set-up
 * or a Pest.php hook runs it, so a read in one is ambiguous.
 * The test files are read once, the first time a helper is asked about.
 */
final class Helpers
{
    /** @var list<NamedFiles> the test files linked by the names they declare and mention, once read */
    private array $named = [];

    /** @var array<string, Paths> the test files of the suite that run each helper, by its path */
    private array $running = [];

    /**
     * @param Paths $files every file under the test directories
     * @param Paths $suite those that hold a test the map names
     */
    public function __construct(
        private readonly Project $project,
        private readonly Paths $files,
        private readonly Paths $suite,
    ) {
    }

    /**
     * The test files a read in a file under the test directories chooses:
     * the file itself, where it holds a test the map names, and the test
     * files that run it, where it is a helper.
     */
    public function choosing(Path $file): Paths
    {
        return $this->suite->has($file) ? Paths::of($file) : $this->running($file);
    }

    /** Whether any of these reads is in a helper, which makes the reads ambiguous. */
    public function readAny(References $references): bool
    {
        return array_any(
            $references->sites(),
            fn(Site $site): bool => $site->isInTest() && ! $this->suite->has($site->file()),
        );
    }

    /** The test files of the suite that name what a helper declares, through any helpers that name it. */
    private function running(Path $helper): Paths
    {
        $key = $helper->value();

        if (! array_key_exists($key, $this->running)) {
            $this->running[$key] = $this->namingAny($helper);
        }

        return $this->running[$key];
    }

    private function namingAny(Path $helper): Paths
    {
        $named = $this->named();
        $read = $this->read($helper);
        $running = Paths::none();

        foreach ($read instanceof PhpFile ? $named->naming(...NamedFiles::declaredIn($read)) : [] as $file) {
            $path = Path::of($file);
            $running = $this->suite->has($path) ? $running->with($path) : $running;
        }

        return $running;
    }

    private function named(): NamedFiles
    {
        if ($this->named === []) {
            $reads = [];

            foreach ($this->files as $file) {
                $read = $this->read($file);
                $reads += $read instanceof PhpFile ? [$file->value() => $read] : [];
            }

            $this->named = [NamedFiles::read($reads)];
        }

        return $this->named[0];
    }

    /** A test file, read for the names it declares and mentions; none where it is not there. */
    private function read(Path $file): PhpFile|NotGiven
    {
        $path = $this->project->absolute($file);
        $text = is_file($path) ? file_get_contents($path) : null;

        return is_string($text) ? PhpFile::read(Contents::of($text)) : NotGiven::value();
    }
}
