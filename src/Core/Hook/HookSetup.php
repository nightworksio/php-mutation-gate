<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hook;

use function implode;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where `init --hook` sets the gate's hooks up (ADR-0017 decision 1, ADR-0024
 * decision 12): in git's own hooks, as `hook install` writes them, in a hook
 * framework's config, or nowhere.
 */
enum HookSetup: string
{
    case Git = 'git';
    case CaptainHook = 'captainhook';
    case GrumPhp = 'grumphp';
    case PreCommit = 'pre-commit';
    case None = 'none';

    /** What separates the names `init --hook` takes, where it lists them. */
    private const string OR = '|';

    /** The names `init --hook` takes. */
    public static function names(): string
    {
        $names = [];

        foreach (self::cases() as $setup) {
            $names[] = $setup->value;
        }

        return implode(self::OR, $names);
    }

    /** The framework whose config calls the gate; none for git's own hooks, or none set up. */
    public function framework(): HookFramework|NotGiven
    {
        return match ($this) {
            self::Git, self::None => NotGiven::value(),
            self::CaptainHook => HookFramework::CaptainHook,
            self::GrumPhp => HookFramework::GrumPhp,
            self::PreCommit => HookFramework::PreCommit,
        };
    }
}
