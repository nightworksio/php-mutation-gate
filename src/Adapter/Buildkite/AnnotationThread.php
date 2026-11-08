<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Buildkite;

use function file_put_contents;
use function is_dir;
use function is_writable;

use NightWorksIO\MutationGate\Core\Delivery\CommentThread;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Processes;

use function sprintf;
use function tempnam;
use function trim;
use function unlink;

/**
 * The build's one annotation, as `buildkite-agent annotate` writes it: found by its context, so each write replaces the
 * last. The body goes through standard input, since an annotation may be longer than one argument can be.
 *
 * @internal
 */
final readonly class AnnotationThread implements CommentThread
{
    private const string ANNOTATE = 'exec buildkite-agent annotate --context "$1" < "$2"';

    private const string WRITTEN = 'the annotation of %s';

    private const string UNWRITTEN = 'The build annotation could not be written (%s).';

    private const string NO_FILE = 'its body could not be written to a file';

    private const string NOT_A_BUILD = 'This run is not a Buildkite build, so there is no annotation to write.';

    private function __construct(
        private Processes $processes,
        private string $directory,
        private string $build,
        private bool $inBuild,
        private string $scratch,
    ) {
    }

    /**
     * The annotation of the build at this URL, written through these processes from this directory, where in one, its
     * body held for the agent in a file in this scratch directory.
     */
    public static function of(
        Processes $processes,
        string $directory,
        string $build,
        bool $inBuild,
        string $scratch,
    ): self {
        return new self($processes, $directory, $build, $inBuild, $scratch);
    }

    public function written(string $markdown, CommentWrite $write): Written|NotWritten
    {
        if (! $this->inBuild) {
            return NotWritten::because(self::NOT_A_BUILD);
        }

        $writable = is_dir($this->scratch) && is_writable($this->scratch);
        $file = $writable ? tempnam($this->scratch, ThisPackage::NAME) : false;

        if ($file === false || file_put_contents($file, $markdown) === false) {
            return NotWritten::because(sprintf(self::UNWRITTEN, self::NO_FILE));
        }

        $ran = $this->processes->run(
            ProcessCommand::inShell($this->directory, self::ANNOTATE, ThisPackage::NAME, $file),
        );
        unlink($file);

        return $ran->succeeded()
            ? Written::to(sprintf(self::WRITTEN, $this->build))
            : NotWritten::because(sprintf(self::UNWRITTEN, trim($ran->output())));
    }
}
