<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Git\Command as Git;
use NightWorksIO\MutationGate\Adapter\Git\Hooks;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * Git's own pre-push hook as `init --hook` sets it up (ADR-0017, decision 1):
 * written as `hook install` writes it, or printed whole with `--dry-run`.
 */
final readonly class GitHooks
{
    /** The pre-push hook in this project's repository, written or printed, said; or why it could not be. */
    public static function made(string $project, Output $output): string|CannotJudge
    {
        $hooks = Hooks::of(Git::in($project));

        return match (true) {
            $hooks instanceof CannotTell => CannotJudge::because($hooks->why()),
            $output === Output::Printed => sprintf(
                Output::FILE,
                $hooks->where(Hook::PrePush),
                Hook::PrePush->script(
                    $hooks->call(ComposerVendor::binaries($project)->child(Path::of(ThisPackage::NAME))),
                )->text(),
            ),
            default => HookCommand::installed($hooks, $project, [Hook::PrePush]),
        };
    }
}
