<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The committed baseline, as the checkout holds it and as the default
 * branch does, and writing it back.
 */
final readonly class Baselines
{
    private const string UNREAD = <<<'SAID'
        The baseline %s holds cannot be read,
        so a floor lowered here cannot be checked against it. %s
        Fetch the default branch into the checkout before the verdict.
        SAID;

    public function __construct(private Adapters $adapters, private Path $file)
    {
    }

    /** The file the baseline is committed in. */
    public function file(): Path
    {
        return $this->file;
    }

    /** The baseline on disk; none where there is no file yet. */
    public function committed(): Baseline|CannotJudge
    {
        $contents = $this->adapters->project->read($this->file);

        return match (true) {
            $contents instanceof CannotJudge => $contents,
            $contents instanceof Contents => BaselineFile::decode($contents->text(), $this->file),
            default => Baseline::none(),
        };
    }

    /**
     * The baseline as the default branch holds it, which a floor lowered here
     * is checked against; none where the branch has no file, since a floor is
     * lowered only against one that was committed. Where git cannot read the
     * branch, as in a shallow checkout, or its file is not a baseline, no
     * lowered floor can be checked, and the verdict cannot judge.
     */
    public function onDefaultBranch(Scope $defaultBranch): Baseline|CannotJudge
    {
        $remote = Standing::fetched($defaultBranch);
        $contents = $this->adapters->changes->fileAt($this->file, $remote);
        $read = match (true) {
            $contents instanceof Contents => BaselineFile::decode($contents->text(), $this->file),
            $contents instanceof CannotTell => CannotJudge::because($contents->why()),
            default => Baseline::none(),
        };

        return $read instanceof CannotJudge
            ? CannotJudge::because(sprintf(self::UNREAD, $remote->name(), $read->why()))
            : $read;
    }

    public function write(Baseline $baseline): Written|CannotJudge
    {
        return $this->adapters->project->write($this->file, Contents::of(BaselineFile::encode($baseline)));
    }
}
