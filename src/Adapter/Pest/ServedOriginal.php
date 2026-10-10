<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_file;
use function is_writable;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * A file served unmutated through Pest's override, in its own place, as a
 * mutant's own run serves its mutated copy: printed as Pest prints each of its
 * mutants, so a control run and the mutant's run differ in the mutation alone.
 * A test that fails only while the override serves a file, as one does that
 * stats a dangling link through it, then fails in the control as well, and
 * its failure is never read as a kill.
 */
final readonly class ServedOriginal
{
    /** Where the copies are written, in the directory of a run's files. */
    private const string COPIES = '%s/originals/%s.php';

    private const string UNWRITTEN = 'The gate cannot write %s, the unmutated copy of %s.';

    private function __construct(private string $original, private string $copy)
    {
    }

    /**
     * A file of the project, printed and written in the directory of a run's
     * files, each print by its own name; or why it cannot be served.
     */
    public static function of(Project $project, string $directory, Path $file): self|CannotJudge
    {
        $original = $project->absolute($file);
        $text = is_file($original) ? file_get_contents($original) : false;
        $printed = $text === false
            ? CannotJudge::because(sprintf(Control::UNREAD, $file->value()))
            : Printed::of(Contents::of($text), $file);

        if ($printed instanceof CannotJudge) {
            return $printed;
        }

        $copy = sprintf(self::COPIES, $directory, hash('xxh3', $printed->text()));
        $copies = $project->directory(Path::of(dirname($copy)));

        return is_file($copy) || (is_writable($copies) && file_put_contents($copy, $printed->text()) !== false)
            ? new self($original, $copy)
            : CannotJudge::because(sprintf(self::UNWRITTEN, $copy, $file->value()));
    }

    /** The command, run with the override serving the unmutated copy in the original's place. */
    public function onto(Command $command): Command
    {
        return $command->with([Recorder::MUTANT => $this->original, Recorder::MUTATED => $this->copy]);
    }

    /** The unmutated copy served in the original's place, which a run's records name as its mutated copy. */
    public function copy(): string
    {
        return $this->copy;
    }

    /** What tells this served file from any other, as a run's key reads it. */
    public function key(): string
    {
        return sprintf('%s => %s', $this->original, $this->copy);
    }
}
