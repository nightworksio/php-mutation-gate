<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use NightWorksIO\MutationGate\Core\Import\Carried;

/**
 * The top-level keys of Infection's config an import leaves where they are:
 * what the Infection adapter goes on reading, or the gate overrides, stays;
 * what the gate does in its own way is dropped (ADR-0016, decision 1).
 */
enum Kept
{
    case Threads;
    case TmpDir;
    case Mutators;
    case Bootstrap;
    case PhpUnit;
    case TestFramework;
    case InitialTestsPhpOptions;
    case TestFrameworkOptions;
    case TestFrameworkExtraArgs;
    case StaticAnalysisTool;
    case StaticAnalysisToolOptions;
    case MaxTimeouts;
    case IgnoreMsiWithNoMutations;

    /** The key of this name, where it is one of these. */
    public static function named(string $key): self|Unknown
    {
        foreach (self::cases() as $kept) {
            if ($kept->key() === $key) {
                return $kept;
            }
        }

        return Unknown::key();
    }

    /** The key as Infection's config spells it. */
    public function key(): string
    {
        return match ($this) {
            self::Threads => 'threads',
            self::TmpDir => 'tmpDir',
            self::Mutators => 'mutators',
            self::Bootstrap => 'bootstrap',
            self::PhpUnit => 'phpUnit',
            self::TestFramework => 'testFramework',
            self::InitialTestsPhpOptions => 'initialTestsPhpOptions',
            self::TestFrameworkOptions => 'testFrameworkOptions',
            self::TestFrameworkExtraArgs => 'testFrameworkExtraArgs',
            self::StaticAnalysisTool => 'staticAnalysisTool',
            self::StaticAnalysisToolOptions => 'staticAnalysisToolOptions',
            self::MaxTimeouts => 'maxTimeouts',
            self::IgnoreMsiWithNoMutations => 'ignoreMsiWithNoMutations',
        };
    }

    public function carried(): Carried
    {
        return match ($this) {
            self::Threads, self::TmpDir => Carried::stays($this->key(), 'the gate overrides it for each run'),
            self::MaxTimeouts => Carried::dropped($this->key(), 'timeouts are triaged, not capped'),
            self::IgnoreMsiWithNoMutations => Carried::dropped($this->key(), 'nothing to mutate already passes'),
            self::Mutators,
            self::Bootstrap,
            self::PhpUnit,
            self::TestFramework,
            self::InitialTestsPhpOptions,
            self::TestFrameworkOptions,
            self::TestFrameworkExtraArgs,
            self::StaticAnalysisTool,
            self::StaticAnalysisToolOptions => Carried::stays($this->key(), 'the Infection adapter reads it'),
        };
    }
}
