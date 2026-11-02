<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function implode;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\GateCommand;
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

    private const string UNREAD_CONFIG
        = '%s on the default branch cannot be read, so the file it keeps its baseline in is not known. %s';

    public function __construct(private Adapters $adapters, private Path $file, private GateCommand $gate)
    {
    }

    /** The baselines of the file the config names, which the gate as it was started is named to write. */
    public static function of(Adapters $adapters, Settings $settings, Setup $setup): self
    {
        return new self($adapters, $settings->floors()->baseline(), $setup->command);
    }

    /** The gate as it was started, which what is said of the baseline names. */
    public function gate(): GateCommand
    {
        return $this->gate;
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
            $contents instanceof Contents => BaselineFile::decode($contents->text(), $this->file, $this->gate),
            default => Baseline::none(),
        };
    }

    /**
     * The baseline as the branch the run is read against holds it, in the file
     * its own config file names, which a floor lowered here is checked against;
     * none where the branch has no file, since a floor is lowered only against
     * one that was committed. Where git cannot read the branch, as in a shallow checkout,
     * its config file cannot be read, or its file is not a baseline, no
     * lowered floor can be checked, and the verdict cannot judge.
     */
    public function onBase(Scope $base, Setup $setup): Baseline|CannotJudge
    {
        $remote = Standing::fetched($base);
        $file = $this->fileOn($remote, $setup);
        $contents = $file instanceof Path ? $this->adapters->changes->fileAt($file, $remote) : $file;
        $read = match (true) {
            ! $file instanceof Path => $file,
            $contents instanceof Contents => BaselineFile::decode($contents->text(), $file, $this->gate),
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

    /**
     * The file the base branch commits its baseline in, as its own config
     * file names it, so a change that moves the baseline is still checked
     * against the floors the base branch holds.
     */
    private function fileOn(Revision $remote, Setup $setup): Path|CannotJudge
    {
        $config = $setup->configFile;

        if (! $config instanceof Path) {
            return $this->file;
        }

        $before = $this->adapters->changes->fileAt($config, $remote);

        $file = $before instanceof CannotTell
            ? CannotJudge::because($before->why())
            : $setup->config->baselineAt($before, $this->file);

        return $file instanceof Invalid ? CannotJudge::because($this->unreadConfig($config, $file)) : $file;
    }

    /** Why the config file the default branch holds names no baseline: each of its problems. */
    private function unreadConfig(Path $config, Invalid $invalid): string
    {
        $problems = [];

        foreach ($invalid as $problem) {
            $problems[] = sprintf('%s: %s', $problem->path(), $problem->message());
        }

        return sprintf(self::UNREAD_CONFIG, $config->value(), implode(' ', $problems));
    }
}
