<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function explode;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function mkdir;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function rmdir;
use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function trim;
use function unlink;

/**
 * Programs compiled by `outbreak.so` in a child of the PHP that runs the
 * tests, with its own php.ini and extensions, the extension loaded besides
 * (ADR-0130): each program's op arrays as one digest. The child compiles
 * each program from its text and runs none of it. A compile error can leave
 * PHP's compiler in a state no later compile may trust, so the child stops at
 * the first program it cannot compile, and the programs after it go to a
 * child of their own; a child that ends otherwise, run out of time or
 * crashed, keeps what it answered before, and the next program goes to the
 * next child likewise. Where the extension does not load, nothing is
 * compiled.
 *
 * @internal
 */
final readonly class OpArrays
{
    private const string PROGRAM = '%s/%d.php';

    /** A digest as the extension writes it: a SHA-256 in lowercase hex. */
    private const string DIGEST = '/^[0-9a-f]{64}$/';

    /** What the child answers first where it has no extension. */
    private const string NONE = '-';

    /** The child's script: the extension's version, then each program's digest, stopping after the first it lacks. */
    private const string SCRIPT = <<<'PHP'
        if (! function_exists('outbreak_op_arrays')) {
            echo "-\n";
            exit;
        }
        echo outbreak_version(), "\n";
        foreach (array_slice($argv, 1) as $file) {
            try {
                $digest = outbreak_op_arrays($file);
            } catch (Throwable) {
                $digest = null;
            }
            echo $digest ?? '-', "\n";
            if ($digest === null) {
                exit;
            }
        }
        PHP;

    /** What the child runs with besides the tests' own php.ini: the extension, and no error among its answers. */
    private const array SETTINGS = ['-d', 'extension=%s', '-d', 'display_errors=0', '-d', 'log_errors=0'];

    /**
     * @param string $binary    the PHP the tests run on
     * @param string $extension the `outbreak.so` built for it
     * @param string $directory where each process writes its programs, in a directory the gate owns
     */
    public function __construct(
        private string $binary,
        private string $extension,
        private string $directory,
        private float $seconds,
    ) {
    }

    /**
     * Each program's digest by its key, and the extension's version; the
     * programs it could not compile are left out. None where the extension
     * did not load.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, Contents>                          $programs
     * @return array{array<TKey, string>, string}|NotGiven
     */
    public function digests(array $programs): array|NotGiven
    {
        $directory = sprintf('%s/%d', $this->directory, getmypid());
        $paths = $this->written($programs, $directory);
        $keys = array_keys($paths);
        $digests = [];
        $version = '';

        try {
            for ($at = 0; $at < count($keys); $at++) {
                $answered = $this->answered(array_slice($paths, $at, preserve_keys: true));

                if (! $answered instanceof Answer) {
                    break;
                }

                $version = $answered->version;
                $digests += $answered->digests;
                $at += count($answered->digests);
            }
        } finally {
            $this->removed($paths, $directory);
        }

        if ($version === '') {
            return NotGiven::value();
        }

        return [$digests, $version];
    }

    /**
     * What a child answered for these programs, from the first on, until the
     * first it did not answer for, which is skipped; none where the child
     * could not run, ran past its time, or did not load the extension.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, string> $paths
     * @return Answer<TKey>|NotGiven
     */
    private function answered(array $paths): Answer|NotGiven
    {
        $process = new Process(
            [
                $this->binary,
                ...array_map(fn(string $setting): string => sprintf($setting, $this->extension), self::SETTINGS),
                '-r',
                self::SCRIPT,
                ...$paths,
            ],
            timeout: $this->seconds,
        );

        $lines = explode("\n", trim($this->outputOf($process)));
        $version = $lines[0];

        if ($version === self::NONE || $version === '') {
            return NotGiven::value();
        }

        $digests = [];
        $answers = array_slice($lines, 1);

        foreach (array_keys($paths) as $index => $key) {
            $answer = $answers[$index] ?? self::NONE;

            if (preg_match(self::DIGEST, $answer) !== 1) {
                break;
            }

            $digests[$key] = $answer;
        }

        return new Answer($version, $digests);
    }

    /** What a child wrote before it ended, ran out of time or could not start, which is then nothing. */
    private function outputOf(Process $process): string
    {
        try {
            $process->run();
        } catch (ExceptionInterface) {
            return $process->isStarted() ? $process->getOutput() : '';
        }

        return $process->getOutput();
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, Contents> $programs
     * @return array<TKey, string>   each program's path
     */
    private function written(array $programs, string $directory): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $paths = [];

        foreach (array_keys($programs) as $index => $key) {
            $path = sprintf(self::PROGRAM, $directory, $index);
            file_put_contents($path, $programs[$key]->text());
            $paths[$key] = $path;
        }

        return $paths;
    }

    /** @param array<array-key, string> $paths */
    private function removed(array $paths, string $directory): void
    {
        foreach ($paths as $path) {
            unlink($path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
