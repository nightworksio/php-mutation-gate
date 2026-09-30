<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function dirname;
use function fclose;
use function fopen;
use function fwrite;
use function getenv;
use function is_dir;
use function is_file;
use function is_link;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Uncapped;

use function rename;
use function sprintf;
use function unlink;

/**
 * The directory of the ini file that caps a run's memory (ADR-0004, decision
 * 9), for every PHP process of the run to scan: Infection's own, its
 * opening run and each mutant's PHPUnit, all of which inherit the
 * environment. It is in Infection's own directory of the gate's workspace.
 */
final readonly class MemoryScan
{
    private function __construct(private string|Uncapped $directory)
    {
    }

    /** The cap written into Infection's own directory, or none where it caps nothing; or why it cannot be written. */
    public static function in(Project $project, MemoryCap $memory): self|CannotJudge
    {
        if (! $memory->caps()) {
            return new self(Uncapped::Memory);
        }

        $directory = $project->directory($project->relative(MemoryCap::directoryIn($project->own('.'))))->value();
        $ini = sprintf('%s/%s', $directory, MemoryCap::FILE);

        return self::written($directory, $ini, $memory)
            ? new self($directory)
            : CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, $ini));
    }

    /** A command whose PHP processes scan the cap's directory too, after those they scan already. */
    public function onto(Command $command): Command
    {
        return $this->directory instanceof Uncapped
            ? $command
            : $command->with([
                MemoryCap::SCAN_DIR => MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $this->directory),
            ]);
    }

    /**
     * Whether the cap is written into its directory, whole: staged beside
     * it, then moved into place, so a PHP that starts meanwhile reads the
     * file before or after, never half of one. A link where the directory,
     * its parent or the staged file should be is refused, not followed.
     */
    private static function written(string $directory, string $ini, MemoryCap $memory): bool
    {
        $staged = MemoryCap::stagedIn($directory);

        if (is_link($directory) || is_link(dirname($directory)) || is_link($staged) || is_dir($ini)) {
            return false;
        }

        if (is_file($staged)) {
            unlink($staged);
        }

        $handle = fopen($staged, 'x');

        return $handle !== false
            && fwrite($handle, $memory->ini()) !== false
            && fclose($handle)
            && rename($staged, $ini);
    }
}
