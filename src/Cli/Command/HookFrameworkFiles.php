<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hook\HookCall;
use NightWorksIO\MutationGate\Core\Hook\HookFramework;
use NightWorksIO\MutationGate\Core\Hook\HookRecipe;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The config `init --hook` sets a hook manager up with (ADR-0024, decision
 * 12): written where the manager reads none, and otherwise printed as what
 * to add to the one it reads; with `--stdout`, printed whole. Each manager
 * calls the gate Composer linked into the project.
 */
final readonly class HookFrameworkFiles
{
    private const string HOOK = 'hook';

    private const string UNKNOWN = '%s is no hook manager init --hook sets up. Name one of %s.';

    private const string PRINTED = "%s:\n\n%s";

    private const string HERE = "%s is here, so init leaves it as it is. %s";

    private const string INSTALL = 'Then install its hooks: %s %s';

    private const string GRUMPHP_PRE_PUSH = <<<'SAID'
        GrumPHP runs no pre-push hook; %s hook install adds the gate's.
        SAID;

    public static function option(Command $command): Command
    {
        return $command->addOption(
            self::HOOK,
            mode: InputOption::VALUE_REQUIRED,
            description: sprintf('Set the gate up in this hook manager: %s', HookFramework::names()),
        );
    }

    /** The manager `--hook` names; none where it names none; why not, where it names one `init` does not know. */
    public static function asked(InputInterface $input): HookFramework|NotGiven|CannotJudge
    {
        $named = $input->getOption(self::HOOK);
        $manager = HookFramework::tryFrom(is_string($named) ? $named : '');

        return match (true) {
            ! is_string($named) => NotGiven::value(),
            $manager instanceof HookFramework => $manager,
            default => CannotJudge::because(sprintf(self::UNKNOWN, $named, HookFramework::names())),
        };
    }

    /** The manager's config in this project, written or printed, and what to run next, said; or why it could not be. */
    public static function made(
        string $project,
        HookFramework $manager,
        GatePin $gate,
        Output $output,
    ): string|CannotJudge {
        $binaries = ComposerVendor::binaries($project);
        $recipe = HookRecipe::of(HookCall::of(Path::root(), $binaries->child(Path::of(ThisPackage::NAME))), $gate);

        if ($output === Output::Printed) {
            return sprintf(self::PRINTED, $manager->file(), $recipe->whole($manager));
        }

        $directory = Directory::at($project);
        $existing = self::existing($directory, $manager);
        $said = match (true) {
            $existing instanceof CannotJudge => $existing,
            is_string($existing) => sprintf(self::HERE, $existing, $recipe->addition($manager)),
            default => self::written($directory, $manager, $recipe),
        };

        return is_string($said) ? sprintf("%s\n%s", $said, self::next($manager, $binaries)) : $said;
    }

    /** The first config the manager reads that is here; none where none is; or why one could not be read. */
    private static function existing(Directory $project, HookFramework $manager): string|NotGiven|CannotJudge
    {
        foreach ($manager->files() as $file) {
            $there = $project->read(Path::of($file));

            if (! $there instanceof Contents && ! $there instanceof CannotJudge) {
                continue;
            }

            return $there instanceof CannotJudge ? $there : $file;
        }

        return NotGiven::value();
    }

    private static function written(Directory $project, HookFramework $manager, HookRecipe $recipe): string|CannotJudge
    {
        $file = Path::of($manager->file());
        $written = $project->write($file, Contents::of(sprintf("%s\n", $recipe->whole($manager))));

        return $written instanceof CannotJudge ? $written : Written::to($file->value())->said();
    }

    /** What installs the manager's hooks, and, for GrumPHP, what adds the pre-push hook it does not run. */
    private static function next(HookFramework $manager, Path $binaries): string
    {
        $gate = $binaries->child(Path::of(ThisPackage::NAME))->value();

        return match ($manager) {
            HookFramework::CaptainHook => sprintf(
                self::INSTALL,
                $binaries->child(Path::of(HookFramework::CaptainHook->value))->value(),
                'install',
            ),
            HookFramework::GrumPhp => sprintf(
                "%s\n%s",
                sprintf(self::INSTALL, $binaries->child(Path::of(HookFramework::GrumPhp->value))->value(), 'git:init'),
                sprintf(self::GRUMPHP_PRE_PUSH, $gate),
            ),
            HookFramework::PreCommit => sprintf(self::INSTALL, 'pre-commit', 'install'),
        };
    }
}
