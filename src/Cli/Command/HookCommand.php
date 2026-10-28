<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function implode;

use NightWorksIO\MutationGate\Adapter\Git\Command as Git;
use NightWorksIO\MutationGate\Adapter\Git\Hooks;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\StartedGate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\Hook\Occupant;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verb;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate hook install` writes the pre-push hook, and with
 * `--pre-commit` the pre-commit hook too, into the directory git runs hooks
 * from. A hook the gate did not write is left as it is, and so is every hook
 * of a directory outside the repository, which other repositories run their
 * hooks from too; for each, the line that calls the gate is printed.
 * `mutation-gate hook uninstall` removes the hooks the gate wrote, and only
 * those (ADR-0010).
 */
final readonly class HookCommand
{
    /** Why the command does nothing with an action it does not know. */
    private const string UNKNOWN = 'hook takes install or uninstall, not "%s".';

    /** What install says of a hook someone else wrote. */
    private const string FOREIGN = <<<'SAID'
        %s is a hook the gate did not write, so it is left as it is. To run the gate from it, add: %s
        SAID;

    /** What install says of a hook in a directory other repositories run their hooks from too: where, and why. */
    private const string SHARED_WHERE = '%s is outside this repository, so other repositories run its hooks too;';

    /** What install says of such a hook: what it does with it, and the line to add there. */
    private const string SHARED_LINE = 'it is left as it is. To run the gate from a hook there, add: %s';


    /** What uninstall says where the gate wrote no hook. */
    private const string NONE = 'There is no hook the gate wrote to remove.';

    public static function command(string $project): Command
    {
        return new Command(Verb::Hook->value)
            ->setDescription('Add or remove the pre-push hook: hook install, hook uninstall')
            ->addArgument(Argument::Action->value, InputArgument::REQUIRED, 'install or uninstall')
            ->addOption(
                Hook::PreCommit->value,
                mode: InputOption::VALUE_NONE,
                description: 'Also add the pre-commit hook, which shows the score change and never blocks',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($project): int {
                $named = CommandText::argument($input, Argument::Action);
                $action = HookAction::tryFrom($named);
                $hooks = Hooks::of(Git::in($project));

                return match (true) {
                    ! $action instanceof HookAction => Failed::because(
                        $output,
                        CannotJudge::because(sprintf(self::UNKNOWN, $named)),
                    ),
                    $hooks instanceof CannotTell => Failed::because($output, CannotJudge::because($hooks->why())),
                    $action === HookAction::Install => self::install($output, $hooks, $project, self::chosen($input)),
                    default => self::uninstall($output, $hooks),
                };
            });
    }

    /**
     * These hooks written into the directory git runs hooks from, each where
     * the gate may write it, and otherwise the line that calls the gate from
     * it, said; or, after what was said, why one could not be written.
     *
     * @param list<Hook> $chosen
     */
    public static function installed(Hooks $hooks, string $project, array $chosen): string|CannotJudge
    {
        $call = $hooks->call(StartedGate::in($project));
        $said = [];

        foreach ($chosen as $hook) {
            $left = self::leftAlone($hooks, $hook);
            $written = $left === '' ? $hooks->write($hook, $hook->script($call)) : NotGiven::value();

            if ($written instanceof CannotJudge) {
                return CannotJudge::because(implode("\n", [...$said, $written->why()]));
            }

            $said[] = $written instanceof NotGiven
                ? sprintf($left, $hooks->where($hook), $call->line($hook))
                : $written->said();
        }

        return implode("\n", $said);
    }

    /**
     * What is said of a hook the gate may not write, with where it is and
     * the line that calls the gate from it: in a shared directory of hooks,
     * or one someone else wrote; nothing where the gate may write it.
     */
    private static function leftAlone(Hooks $hooks, Hook $hook): string
    {
        return match (true) {
            $hooks->isShared() => sprintf(Fit::JOINED, self::SHARED_WHERE, self::SHARED_LINE),
            $hook->occupant($hooks->read($hook)) === Occupant::Someone => self::FOREIGN,
            default => '',
        };
    }

    /** @return list<Hook> */
    private static function chosen(InputInterface $input): array
    {
        return $input->getOption(Hook::PreCommit->value) === true ? [Hook::PrePush, Hook::PreCommit] : [Hook::PrePush];
    }

    /** @param list<Hook> $chosen */
    private static function install(OutputInterface $output, Hooks $hooks, string $project, array $chosen): int
    {
        $said = self::installed($hooks, $project, $chosen);

        if ($said instanceof CannotJudge) {
            return Failed::because($output, $said);
        }

        $output->writeln($said, OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    private static function uninstall(OutputInterface $output, Hooks $hooks): int
    {
        $removedAny = false;

        foreach (Hook::cases() as $hook) {
            if ($hook->occupant($hooks->read($hook)) !== Occupant::TheGate) {
                continue;
            }

            $removed = $hooks->remove($hook);

            if ($removed instanceof CannotJudge) {
                return Failed::because($output, $removed);
            }

            $output->writeln($removed->said(), OutputInterface::OUTPUT_RAW);
            $removedAny = true;
        }

        if (! $removedAny) {
            $output->writeln(self::NONE, OutputInterface::OUTPUT_RAW);
        }

        return ExitCode::Passed->value;
    }
}
