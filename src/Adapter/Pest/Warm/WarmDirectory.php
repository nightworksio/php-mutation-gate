<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_any;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function mkdir;

use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function rmdir;
use function scandir;
use function sprintf;
use function str_ends_with;
use function touch;
use function unlink;
use function usleep;

/**
 * The directory a patched Pest run's warm workers share, as the gate sees it
 * (ADR-0023): named beside the run's results, so no other run's files are
 * read as this one's; read for what the workers warned of once the run is
 * done and they have ended, and then removed with every file in it.
 *
 * @internal
 */
final readonly class WarmDirectory
{
    private const string BESIDE = '%s.warm';

    private const string WARNED = '.warned';

    private const string PID = '.pid';

    private const int OWNER_ONLY = 0o700;

    /** The directory of the warm workers of the run that records to these results. */
    public static function beside(string $results): string
    {
        return sprintf(self::BESIDE, $results);
    }

    /**
     * The directory of the warm workers of the run that records to these
     * results made, its owner's alone, as each request holds its run's
     * environment: its path.
     */
    public static function made(string $results): string
    {
        $directory = self::beside($results);

        if (! is_dir($directory)) {
            mkdir($directory, self::OWNER_ONLY);
        }

        return $directory;
    }

    /**
     * What the workers of the run that records to these results warned of,
     * read once they are told the run is done and every worker has ended, or
     * the wait for them has, before their directory is removed with every
     * file in it.
     */
    public static function cleared(string $results): Warnings
    {
        $directory = self::beside($results);

        if (is_dir($directory)) {
            touch(Seat::stopIn($directory));
        }

        Polling::wait(
            Seconds::of(Seat::WAIT),
            static fn(): bool => ! array_any(self::files($directory), self::isRunning(...)),
            usleep(...),
        );
        $warnings = self::warnings($directory);
        self::removed($directory);

        return $warnings;
    }

    /** Whether the file is a worker's process id, and that process is still there. */
    private static function isRunning(string $file): bool
    {
        return str_ends_with($file, self::PID) && PidFile::isRunning($file);
    }

    /** One warning for each reason a worker forked a mutant's run no child. */
    private static function warnings(string $directory): Warnings
    {
        $reasons = [];

        foreach (self::files($directory) as $file) {
            $warned = str_ends_with($file, self::WARNED) ? (string) file_get_contents($file) : '';
            $reasons = [...$reasons, ...explode("\n", $warned)];
        }

        $reasons = array_filter(array_unique($reasons), static fn(string $reason): bool => $reason !== '');

        return Warnings::of(...array_map(Warning::that(...), $reasons));
    }

    /** The directory removed, with every file in it. */
    private static function removed(string $directory): void
    {
        foreach (self::files($directory) as $file) {
            unlink($file);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    /** @return list<string> each file in the directory, by its path */
    private static function files(string $directory): array
    {
        $names = is_dir($directory) ? scandir($directory) : [];
        $files = array_map(
            static fn(string $name): string => sprintf('%s/%s', $directory, $name),
            is_array($names) ? $names : [],
        );

        return array_values(array_filter($files, is_file(...)));
    }
}
