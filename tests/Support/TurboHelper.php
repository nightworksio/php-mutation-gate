<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function getenv;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Turbo\Installation;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Turbo\Protocol;

use function sprintf;

/** The helper a test runs: the binary `MUTATION_GATE_TURBO_BINARY` names, or the release build under `turbo/`. */
final class TurboHelper
{
    /** Why a test of the helper is skipped where no build of it is there. */
    public const string NOT_BUILT = 'the turbo job builds the helper; locally, cargo build --release in turbo/ or name a build with MUTATION_GATE_TURBO_BINARY';

    /** The binary, by its absolute path; an empty string where none is built. */
    public static function binary(): string
    {
        $named = getenv(Installation::BINARY);
        $built = sprintf('%s/turbo/target/release/%s', dirname(__DIR__, 2), Protocol::HELPER);

        return match (true) {
            is_string($named) && $named !== '' => $named,
            is_file($built) => $built,
            default => '',
        };
    }

    public static function isBuilt(): bool
    {
        return self::binary() !== '' && is_file(self::binary());
    }

    /** The processes the helper runs in, as the gate starts them. */
    public static function processes(): LocalProcesses
    {
        return new LocalProcesses(new SystemClock());
    }
}
