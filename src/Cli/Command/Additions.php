<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Editor\Editor;
use NightWorksIO\MutationGate\Core\Hook\HookFramework;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * What `init` makes beside the config (ADR-0015 decisions 7 and 13, ADR-0024
 * decision 12): the CI definition `--ci` asks for, the editor files
 * `--editor` names and the hook manager's config `--hook` names, written, or
 * printed with `--stdout`.
 */
final readonly class Additions
{
    private function __construct(
        private CiRequest|NotGiven $ci,
        private Editor|NotGiven $editor,
        private HookFramework|NotGiven $hook,
        private Output $output,
    ) {
    }

    /** Nothing beside the config, as `import` makes. */
    public static function none(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), NotGiven::value(), Output::Written);
    }

    public static function options(Command $command): Command
    {
        return Output::option(HookFrameworkFiles::option(EditorFiles::option(CiRequest::options($command))));
    }

    /** What the command line asks for beside the config; or why it cannot be made. */
    public static function asked(InputInterface $input, string $project): self|CannotJudge
    {
        $ci = CiRequest::from($input, $project);
        $editor = EditorFiles::asked($input);
        $hook = HookFrameworkFiles::asked($input);

        return match (true) {
            $ci instanceof CannotJudge => $ci,
            $editor instanceof CannotJudge => $editor,
            $hook instanceof CannotJudge => $hook,
            default => new self($ci, $editor, $hook, Output::asked($input)),
        };
    }

    /** Whether it asks for anything, which `init` then makes where a config is already here. */
    public function any(): bool
    {
        return $this->ci instanceof CiRequest
            || $this->editor instanceof Editor
            || $this->hook instanceof HookFramework;
    }

    public function ci(): CiRequest|NotGiven
    {
        return $this->ci;
    }

    /** The editor's files in this project, said; nothing where no editor is named; or why they could not be. */
    public function editorMade(string $project): string|CannotJudge
    {
        return $this->editor instanceof Editor ? EditorFiles::made($project, $this->output) : '';
    }

    /** The hook manager's config in this project, said; nothing where no manager is named; or why it could not be. */
    public function hookMade(string $project, GatePin $gate): string|CannotJudge
    {
        return $this->hook instanceof HookFramework
            ? HookFrameworkFiles::made($project, $this->hook, $gate, $this->output)
            : '';
    }
}
