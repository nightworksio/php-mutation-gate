<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_filter;
use function array_key_exists;
use function array_values;
use function is_array;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Runner\ProgramArguments;

use function sprintf;
use function stat;
use function str_starts_with;

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

    private const string VALUE = '=';

    /** @param array<string, list<int>> $identities */
    private function __construct(private array $identities)
    {
    }

    /** @param list<string> $arguments the command line */
    public static function namedBy(array $arguments): self
    {
        $identities = [];

        foreach ($arguments as $argument) {
            $path = self::pathIn($argument);
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
        $arguments = array_key_exists('argv', $_SERVER) ? $_SERVER['argv'] : [];

        return self::namedBy(is_array($arguments) ? array_values(array_filter($arguments, is_string(...))) : []);
    }

    /**
     * Whether a file's status, as stat or fstat gives it, is one of the runner's own.
     *
     * @param array{dev: int, ino: int}|false $status
     */
    public function hold(array|false $status): bool
    {
        return is_array($status)
            && array_key_exists(sprintf(self::IDENTITY, $status['dev'], $status['ino']), $this->identities);
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
        $equals = Bytes::find($argument, self::VALUE, 0);

        return str_starts_with($argument, ProgramArguments::DOUBLE_DASH) && $equals !== false
            ? Bytes::from($argument, $equals + 1)
            : $argument;
    }
}
