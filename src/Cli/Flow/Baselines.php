<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
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
    private const string BRANCH = 'refs/heads/';

    private const string REMOTE = 'refs/remotes/origin/%s';

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
     * is checked against; none where the branch has no file or git cannot read
     * it, since a floor is lowered only against one that was committed.
     */
    public function onDefaultBranch(Scope $defaultBranch): Baseline
    {
        $branch = mb_substr($defaultBranch->ref(), mb_strlen(self::BRANCH));
        $contents = $this->adapters->changes->fileAt($this->file, Revision::ref(sprintf(self::REMOTE, $branch)));
        $read = $contents instanceof Contents ? BaselineFile::decode($contents->text(), $this->file) : Baseline::none();

        return $read instanceof Baseline ? $read : Baseline::none();
    }

    public function write(Baseline $baseline): Written|CannotJudge
    {
        return $this->adapters->project->write($this->file, Contents::of(BaselineFile::encode($baseline)));
    }
}
