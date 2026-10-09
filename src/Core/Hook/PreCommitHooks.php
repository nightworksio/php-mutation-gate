<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hook;

use function implode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * The hooks this repository offers the pre-commit framework, in the
 * `.pre-commit-hooks.yaml` at its top (ADR-0024, decision 12). Their ids are
 * public API (ADR-0011, decision 7). The framework has no PHP language: each
 * hook runs the gate Composer installed in the project, from the top of the
 * working tree, with git's refs in the variables the framework sets.
 */
final readonly class PreCommitHooks
{
    /** The file the framework reads a repository's hooks from. */
    public const string FILE = '.pre-commit-hooks.yaml';

    /** The gate as Composer links it, which each hook's `entry` runs. */
    private const string BINARY = 'vendor/bin/mutation-gate';

    /** The first release that names the language a command from the user's environment runs under. */
    private const string MINIMUM = '4.4.0';

    private const string ID = '%s-%s';

    private const string HOOK = <<<'YAML'
        - id: %s
          name: %s
          description: %s
          entry: %s
          language: unsupported
          stages: [%s]
          pass_filenames: false
          always_run: true
          minimum_pre_commit_version: '%s'
        YAML;

    /** The hook's id, by which a project's config names it. */
    public static function id(Hook $hook): string
    {
        return sprintf(self::ID, ThisPackage::NAME, $hook->value);
    }

    /** The command the hook runs. */
    public static function entry(Hook $hook): string
    {
        return HookCall::of(Path::root(), Path::of(self::BINARY))->called($hook);
    }

    /** The whole `.pre-commit-hooks.yaml`. */
    public static function manifest(): string
    {
        $hooks = [];

        foreach (Hook::cases() as $hook) {
            $hooks[] = sprintf(
                self::HOOK,
                self::id($hook),
                self::id($hook),
                self::description($hook),
                self::entry($hook),
                $hook->value,
                self::MINIMUM,
            );
        }

        return implode("\n", $hooks);
    }

    private static function description(Hook $hook): string
    {
        return match ($hook) {
            Hook::PrePush => 'Judges the commits being pushed, as CI will, and blocks a push that fails.',
            Hook::PreCommit => 'Shows the score change of each tree the commit reaches, and never blocks.',
        };
    }
}
