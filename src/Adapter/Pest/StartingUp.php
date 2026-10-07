<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function basename;
use function copy;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function realpath;
use function sprintf;

/**
 * A run of no test, timed from its start to its end, started as
 * pest-plugin-mutate starts a mutant's own run of a file, its mutant an
 * unchanged copy, so the plugin serves the file through its own wrapper as
 * it does a mutant's.
 */
final readonly class StartingUp
{
    /** Where a run of no test finds its unchanged mutant, among the adapter's own files. */
    private const string START_UP_COPY = 'pest/start-up/%s';

    private const string NOT_COPIED = 'Pest\'s run of no test needs an unchanged copy of %s, and it could not be made.';

    private const string NOT_STARTED = "Pest's run of no test, timing a mutant's start-up, failed. Pest said:\n%s";

    public function __construct(private Project $project, private Shell $shell)
    {
    }

    /** How long the run of no test took, or why it could not be timed. */
    public function of(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        $original = realpath($this->project->absolute($file));
        $copy = $original === false
            ? CannotJudge::because(sprintf(self::NOT_COPIED, $file->value()))
            : $this->unchanged($original);

        if ($copy instanceof CannotJudge) {
            return $copy;
        }

        $ran = $this->shell->run(
            Invocation::installedIn($this->project->vendor())->startingUp($withheld, $original, $copy),
        );

        return $ran->succeeded() ? $ran->timed() : CannotJudge::because(sprintf(self::NOT_STARTED, $ran->output()));
    }

    /**
     * An unchanged copy of a file among the adapter's own files, which a run
     * of no test serves in its place, copied as the adapter writes its other
     * files.
     */
    private function unchanged(string $original): string|CannotJudge
    {
        $copy = $this->project->fresh(sprintf(self::START_UP_COPY, basename($original)));

        if (is_string($copy)) {
            copy($original, $copy);
        }

        return $copy;
    }
}
