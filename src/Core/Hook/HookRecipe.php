<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hook;

use function implode;
use function json_encode;

use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * What each hook manager runs to call the gate, as `init --hook` writes it
 * into the manager's config or prints it to add there (ADR-0024, decision
 * 12): CaptainHook's actions, GrumPHP's shell task, and the pre-commit
 * framework's hooks from this repository, pinned to the gate's own commit.
 * No code of the gate depends on any of them.
 */
final readonly class HookRecipe
{
    /** CaptainHook hands git's lines on, shell-quoted, in its `{$STDIN}` placeholder. */
    private const string CAPTAINHOOK_STDIN = '%s --stdin={$STDIN}';

    private const string CAPTAINHOOK_ADD = "Add to the actions of pre-push:\n%s\nAnd to the actions of pre-commit:\n%s";

    /** GrumPHP's shell task runs a command through `sh -c`. */
    private const string GRUMPHP_SCRIPT = '["-c", %s]';

    private const string GRUMPHP = <<<'YAML'
        grumphp:
            tasks:
                shell:
                    scripts:
                        - %s
        YAML;

    private const string GRUMPHP_ADD = 'Add to the scripts of the shell task: %s';

    private const string PRE_COMMIT = <<<'YAML'
        default_install_hook_types: [pre-commit, pre-push]
        repos:
            - repo: %s
              rev: %s
              hooks:
        %s
        YAML;

    private const string PRE_COMMIT_ADD = "Add pre-push to default_install_hook_types, and this to the repos:\n%s";

    /** One hook the pre-commit framework takes from the repository, at the depth a config lists it. */
    private const string PRE_COMMIT_HOOK = '        - id: %s';

    /** The same hook, running the gate by another path than its `entry` names. */
    private const string PRE_COMMIT_ENTRY = "%s\n          entry: %s";

    private const string ACTION = 'action';

    private const string ACTIONS = 'actions';

    private const string ENABLED = 'enabled';

    private function __construct(private HookCall $call, private GatePin $gate)
    {
    }

    /** What calls the gate by this call, from the release this pin names. */
    public static function of(HookCall $call, GatePin $gate): self
    {
        return new self($call, $gate);
    }

    /** The manager's whole config, calling the gate and nothing else. */
    public function whole(HookFramework $manager): string
    {
        return match ($manager) {
            HookFramework::CaptainHook => JsonText::encode([
                Hook::PrePush->value => [self::ENABLED => true, self::ACTIONS => [$this->action(Hook::PrePush)]],
                Hook::PreCommit->value => [self::ENABLED => true, self::ACTIONS => [$this->action(Hook::PreCommit)]],
            ]),
            HookFramework::GrumPhp => sprintf(self::GRUMPHP, $this->script()),
            HookFramework::PreCommit => sprintf(
                self::PRE_COMMIT,
                ThisPackage::REPOSITORY,
                $this->gate->pin(),
                implode("\n", [$this->listed(Hook::PrePush), $this->listed(Hook::PreCommit)]),
            ),
        };
    }

    /** The part of the manager's config that calls the gate, said as what to add to a config it reads already. */
    public function addition(HookFramework $manager): string
    {
        return match ($manager) {
            HookFramework::CaptainHook => sprintf(
                self::CAPTAINHOOK_ADD,
                JsonText::encode($this->action(Hook::PrePush)),
                JsonText::encode($this->action(Hook::PreCommit)),
            ),
            HookFramework::GrumPhp => sprintf(self::GRUMPHP_ADD, $this->script()),
            HookFramework::PreCommit => sprintf(self::PRE_COMMIT_ADD, $this->whole($manager)),
        };
    }

    /** @return array{action: string} */
    private function action(Hook $hook): array
    {
        $called = $this->call->called($hook);

        return [self::ACTION => $hook === Hook::PrePush ? sprintf(self::CAPTAINHOOK_STDIN, $called) : $called];
    }

    private function script(): string
    {
        return sprintf(self::GRUMPHP_SCRIPT, json_encode($this->call->called(Hook::PreCommit), JsonText::FLAGS));
    }

    /** A hook from the repository, with the path the project runs the gate by, where its `entry` names another. */
    private function listed(Hook $hook): string
    {
        $listed = sprintf(self::PRE_COMMIT_HOOK, PreCommitHooks::id($hook));
        $called = $this->call->called($hook);

        return $called === PreCommitHooks::entry($hook)
            ? $listed
            : sprintf(self::PRE_COMMIT_ENTRY, $listed, json_encode($called, JsonText::FLAGS));
    }
}
