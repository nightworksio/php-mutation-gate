<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_values;
use function fstat;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function sprintf;
use function stat;
use function str_starts_with;
use function strpos;
use function substr;

/**
 * The files this process's own command line names, which its test runner
 * writes its output through: paratest's status, progress and unexpected
 * output files, a JUnit or TeamCity log, a coverage report. Each is known
 * by its device and inode, so a descriptor open on it is told apart from a
 * file a test opened, whatever name either took (ADR-0117).
 *
 * @internal
 */
final readonly class RunnerFiles
{
    private const string IDENTITY = '%d:%d';

    private const string OPTION = '--';

    private const string VALUE = '=';

    /** @param array<string, list<int>> $identities */
    private function __construct(private array $identities)
    {
    }

    /** @param list<mixed> $arguments the command line, as `$_SERVER['argv']` holds it */
    public static function namedBy(array $arguments): self
    {
        $identities = [];

        foreach ($arguments as $argument) {
            $path = is_string($argument) ? self::pathIn($argument) : '';
            $status = $path !== '' && is_file($path) ? stat($path) : false;

            if (is_array($status)) {
                $identities[sprintf(self::IDENTITY, $status['dev'], $status['ino'])] = [$status['dev'], $status['ino']];
            }
        }

        return new self($identities);
    }

    /** The files this process was started with. */
    public static function ofThisProcess(): self
    {
        $arguments = $_SERVER['argv'] ?? [];

        return self::namedBy(is_array($arguments) ? array_values($arguments) : []);
    }

    /** Whether a file's status, as stat or fstat gives it, is one of the runner's own. */
    public function hold(mixed $status): bool
    {
        return is_array($status)
            && isset($status['dev'], $status['ino'])
            && isset($this->identities[sprintf(self::IDENTITY, $status['dev'], $status['ino'])]);
    }

    /** Whether a stream is open on one of the runner's own files. */
    public function holdStream(mixed $stream): bool
    {
        return is_resource($stream) && $this->hold(fstat($stream));
    }

    /**
     * Each file's device and inode, as `outbreak_descriptors()` takes them.
     *
     * @return list<list<int>>
     */
    public function identities(): array
    {
        return array_values($this->identities);
    }

    private static function pathIn(string $argument): string
    {
        $equals = strpos($argument, self::VALUE);

        return str_starts_with($argument, self::OPTION) && $equals !== false
            ? substr($argument, $equals + 1)
            : $argument;
    }
}
