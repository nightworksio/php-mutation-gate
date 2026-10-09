<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hook;

/**
 * A hook manager `init --hook` sets the gate up in, by the name it takes
 * (ADR-0024, decision 12). Each calls the gate's CLI from its own config,
 * which `init` writes only where the manager reads none.
 */
enum HookFramework: string
{
    case CaptainHook = 'captainhook';
    case GrumPhp = 'grumphp';
    case PreCommit = 'pre-commit';

    /** The config file `init` writes, where the manager reads none. */
    public function file(): string
    {
        return match ($this) {
            self::CaptainHook => 'captainhook.json',
            self::GrumPhp => 'grumphp.yml',
            self::PreCommit => '.pre-commit-config.yaml',
        };
    }

    /**
     * Every file the manager reads its config from, in the order it looks.
     *
     * @return non-empty-list<string>
     */
    public function files(): array
    {
        return match ($this) {
            self::GrumPhp => [
                $this->file(),
                'grumphp.yaml',
                'grumphp.yml.dist',
                'grumphp.yaml.dist',
                'grumphp.dist.yml',
                'grumphp.dist.yaml',
            ],
            self::CaptainHook, self::PreCommit => [$this->file()],
        };
    }
}
