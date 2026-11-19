<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function dirname;
use function file_put_contents;
use function is_file;
use function is_string;
use function json_encode;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\WholeFile;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Staged;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rename;
use function sort;

use SplFileInfo;

use function sprintf;
use function str_ends_with;
use function unlink;

/**
 * The readings of the suite's loaded test files (see LoadedTests), kept
 * beside the results file the gate names so each Pest process of its run
 * reads only the files whose text changed since one read them; by the
 * digest of the gate's own code, so a reading is never taken from another
 * build of the gate. None are kept where the gate names no results file.
 *
 * @internal
 */
final readonly class SuiteReadings
{
    private const string BESIDE = '%s.suite-readings.json';

    private function __construct(private string $file)
    {
    }

    /** The readings kept beside this results file; none where it is not named. */
    public static function beside(string|false $results): self
    {
        return new self(is_string($results) && $results !== '' ? sprintf(self::BESIDE, $results) : '');
    }

    /**
     * Each file's reading, by its path: as kept where its text is the one a
     * kept reading was read from, and read now otherwise; all kept for the
     * next process where any was read now.
     *
     * @param  array<string, string>      $texts each loaded test file's text, by its path
     * @return array<string, FileReading>
     */
    public function of(array $texts): array
    {
        $code = $this->file === '' ? '' : $this->code();
        $kept = $this->kept($code);
        $readings = [];
        $fresh = false;

        foreach ($texts as $file => $text) {
            $known = array_key_exists($file, $kept) && $kept[$file]->digest === FileReading::digestOf($text);
            $readings[$file] = $known ? $kept[$file] : FileReading::of($text);
            $fresh = $fresh || ! $known;
        }

        if ($fresh && $this->file !== '') {
            $this->keep($code, $readings);
        }

        return $readings;
    }

    /**
     * The readings kept under this build of the gate, by each file's path; none where none are, they were kept by
     * another build, or the file cannot be read as kept.
     *
     * @return array<string, FileReading>
     */
    private function kept(string $code): array
    {
        $node = $this->file === '' ? NotGiven::value() : WholeFile::read($this->file);

        try {
            return $node instanceof Node && $node->field('code')->text() === $code
                ? $this->decoded($node->field('files'))
                : [];
        } catch (NotInShape) {
            return [];
        }
    }

    /**
     * Each reading as keep() wrote it, by its file's path.
     *
     * @return array<string, FileReading>
     *
     * @throws NotInShape
     */
    private function decoded(Node $files): array
    {
        $readings = [];

        foreach ($files->entries() as $file => $reading) {
            $readings[sprintf('%s', $file)] = FileReading::decoded($reading);
        }

        return $readings;
    }

    /**
     * Keeps these readings, written beside the file under this process's own
     * name and moved into place, so a process reading them meanwhile finds
     * them whole.
     *
     * @param array<string, FileReading> $readings
     */
    private function keep(string $code, array $readings): void
    {
        $files = [];

        foreach ($readings as $file => $reading) {
            $files[$file] = $reading->encoded();
        }

        $writing = Staged::byThisProcess($this->file);
        $text = json_encode(['code' => $code, 'files' => $files], JsonText::FLAGS);

        if ((file_put_contents($writing, $text) === false || ! rename($writing, $this->file)) && is_file($writing)) {
            unlink($writing);
        }
    }

    /** The digest of the gate's own PHP code, every file of it by its place and text. */
    private function code(): string
    {
        $paths = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), Path::PHP)) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return FileReading::digestOfFiles(...$paths);
    }
}
