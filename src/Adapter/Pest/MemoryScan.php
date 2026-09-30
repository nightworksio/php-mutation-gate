<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Uncapped;

use function sprintf;

/**
 * The directory of the ini file that caps a run's memory (ADR-0004, decision
 * 9), for every PHP process of the run to scan: Pest, each mutant's own
 * process, and each run of a mutant judged by reference. It is beside the
 * run's results, so the run and its judging by reference share it.
 */
final readonly class MemoryScan
{
    private const string DIRECTORY = '%s/php';

    private function __construct(private string|Uncapped $directory)
    {
    }

    /** The cap written beside a run's results file, or none where it caps nothing; or why it cannot be written. */
    public static function beside(Project $project, string $results, MemoryCap $memory): self|CannotJudge
    {
        if (! $memory->caps()) {
            return new self(Uncapped::Memory);
        }

        $directory = $project->directory($project->relative(sprintf(self::DIRECTORY, dirname($results))));
        $ini = sprintf('%s/%s', $directory, MemoryCap::FILE);

        return is_dir($ini) || file_put_contents($ini, $memory->ini()) === false
            ? CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, $ini))
            : new self($directory);
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
}
