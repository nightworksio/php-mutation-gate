<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_any;
use function array_map;
use function chmod;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_writable;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\Hook\HookCall;
use NightWorksIO\MutationGate\Core\Hook\Removed;
use NightWorksIO\MutationGate\Core\Written;

use function rtrim;
use function sprintf;
use function unlink;

/**
 * The hooks of the repository a project is in, in the directory git runs
 * them from: `.git/hooks`, the main worktree's for a linked one, or what
 * `core.hooksPath` names (ADR-0010, decision 3).
 */
final readonly class Hooks
{
    /** A hook git runs: readable and runnable by everyone, written by its owner. */
    private const int RUNNABLE = 0o755;

    /** Why a hook is not removed. */
    private const string UNREMOVED = '%s could not be removed.';

    /**
     * @param Path       $project    the project as the top of the working tree spells it
     * @param list<Path> $repository the top of the working tree and the git directory its worktrees share
     */
    private function __construct(private Root $directory, private Path $project, private array $repository)
    {
    }

    /**
     * The hooks of the repository git finds from this directory, where the
     * directory is in its working tree, and where the repository is.
     */
    public static function of(Command $git): self|CannotTell
    {
        $directory = $git->run(['rev-parse', '--path-format=absolute', '--git-path', 'hooks']);
        $project = $git->run(['rev-parse', '--show-prefix']);
        $repository = $git->run(['rev-parse', '--path-format=absolute', '--show-toplevel', '--git-common-dir']);

        return match (true) {
            $directory instanceof CannotTell => $directory,
            $project instanceof CannotTell => $project,
            $repository instanceof CannotTell => $repository,
            default => new self(
                Root::of(rtrim($directory, "\n")),
                Path::of(rtrim($project, "\n")),
                array_map(Path::of(...), explode("\n", rtrim($repository, "\n"))),
            ),
        };
    }

    /**
     * Whether git runs these hooks from a directory outside the repository,
     * as a `core.hooksPath` other repositories share can name, so a hook
     * written there would run in them too.
     */
    public function isShared(): bool
    {
        $directory = Path::of($this->directory->value());

        return ! array_any($this->repository, static fn(Path $inside): bool => $directory->within($inside));
    }

    /** How a hook calls the gate by this binary, as the project spells it. */
    public function call(Path $binary): HookCall
    {
        return HookCall::of($this->project, $binary);
    }

    /** Where a hook's file is. */
    public function where(Hook $hook): string
    {
        return $this->directory->at($hook->file())->value();
    }

    /** What a hook's file holds, or that there is none. */
    public function read(Hook $hook): Contents|Missing
    {
        $file = $this->where($hook);
        $text = is_file($file) ? file_get_contents($file) : false;

        return $text === false ? Missing::at($hook->file()) : Contents::of($text);
    }

    /** Write a hook, replacing what was there, and make it runnable. */
    public function write(Hook $hook, Contents $script): Written|CannotJudge
    {
        $file = $this->where($hook);
        $directory = $this->directory->value();
        $writable = ! is_dir($file)
            && ! is_file($directory)
            && (is_dir($directory) || mkdir($directory, recursive: true))
            && is_writable(is_file($file) ? $file : $directory);
        $written = $writable && file_put_contents($file, $script->text()) !== false && chmod($file, self::RUNNABLE);

        return $written ? Written::to($file) : Written::failedAt($file);
    }

    /** Remove a hook. */
    public function remove(Hook $hook): Removed|CannotJudge
    {
        $file = $this->where($hook);

        return is_file($file) && is_writable($this->directory->value()) && unlink($file)
            ? Removed::from($file)
            : CannotJudge::because(sprintf(self::UNREMOVED, $file));
    }
}
