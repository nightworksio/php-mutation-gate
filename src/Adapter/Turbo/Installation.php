<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Turbo;

use function hash_file;
use function is_file;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Turbo\Handshake;
use NightWorksIO\MutationGate\Core\Turbo\NotAccelerated;
use NightWorksIO\MutationGate\Core\Turbo\Protocol;
use NightWorksIO\MutationGate\Port\Accelerator;
use NightWorksIO\MutationGate\Port\Processes;

use function sprintf;

/**
 * The helper this run may ask, found and checked before anything is asked
 * (ADR-0029): `MUTATION_GATE_TURBO=off` turns it off; a binary
 * `MUTATION_GATE_TURBO_BINARY` names is the operator's own, run unpinned;
 * otherwise the optional package's binary for this platform runs only where
 * its SHA-256 is the one this gate pins. Every binary must then say it is
 * exactly the helper and protocol this gate expects. Anything else leaves
 * the gate in its own PHP, and says why.
 */
final readonly class Installation
{
    /** The variable that turns the helper off, set to {@see OFF}. */
    public const string SWITCH = 'MUTATION_GATE_TURBO';

    /** The variable that names a binary of the operator's own, which runs unpinned. */
    public const string BINARY = 'MUTATION_GATE_TURBO_BINARY';

    private const string OFF = 'off';

    /** How long the handshake may take. */
    private const float HANDSHAKE_LIMIT = 10.0;

    private const string PACKAGE = '%s/nightworksio/mutation-gate-turbo/bin/%s/%s';

    private const string TURNED_OFF = 'MUTATION_GATE_TURBO=off turns the helper off.';

    private const string NOT_INSTALLED
        = 'The helper is not installed: composer require --dev nightworksio/mutation-gate-turbo adds it.';

    private const string UNPINNED = 'This gate pins no build of the helper for %s, so it runs none.';

    private const string ALTERED
        = 'The helper at %s is not the build this gate pins: its SHA-256 is %s, and the pin is %s.';

    private const string NO_HANDSHAKE = 'The helper at %s did not say what it is: %s';

    /**
     * The helper for this platform, as PHP names it ({@see Platform::of()}),
     * checked against these pins; or why there is none to ask.
     */
    public static function found(
        Variables $variables,
        string $vendor,
        string $root,
        Processes $processes,
        Environment $environment,
        Platform|NotAccelerated $platform,
        Pinned $pins,
    ): Accelerator {
        $binary = match (true) {
            $variables->valueOf(self::SWITCH) === self::OFF => NotAccelerated::because(self::TURNED_OFF),
            $variables->has(self::BINARY) => $variables->valueOf(self::BINARY),
            $platform instanceof NotAccelerated => $platform,
            default => self::pinned($platform, $vendor, $pins),
        };

        return $binary instanceof NotAccelerated
            ? Unavailable::because($binary)
            : self::agreeing($binary, $root, $processes, $environment);
    }

    /** The package's binary for this platform, where it is the build this gate pins; or why not. */
    private static function pinned(Platform $platform, string $vendor, Pinned $pins): string|NotAccelerated
    {
        $binary = sprintf(self::PACKAGE, $vendor, $platform->value, $platform->binary());
        $pin = $pins->digestOf($platform);
        $digest = is_file($binary) ? hash_file('sha256', $binary) : false;

        return match (true) {
            $digest === false => NotAccelerated::because(self::NOT_INSTALLED),
            ! $pin instanceof Digest => NotAccelerated::because(sprintf(self::UNPINNED, $platform->value)),
            $digest !== $pin->value() => NotAccelerated::because(
                sprintf(self::ALTERED, $binary, $digest, $pin->value()),
            ),
            default => $binary,
        };
    }

    /** The helper, where it says it is exactly the helper and protocol this gate expects; or why not. */
    private static function agreeing(
        string $binary,
        string $root,
        Processes $processes,
        Environment $environment,
    ): Accelerator {
        $said = ChildProcess::of($processes->run(
            ProcessCommand::of($root, $binary, Protocol::HANDSHAKE_COMMAND)
                ->with($environment)
                ->within(Seconds::of(self::HANDSHAKE_LIMIT)),
        ));
        $handshake = $said->succeeded()
            ? Handshake::read($said->output())
            : NotAccelerated::because(sprintf(self::NO_HANDSHAKE, $binary, $said->said()));

        return $handshake instanceof NotAccelerated
            ? Unavailable::because($handshake)
            : Sidecar::of($processes, $root, [$binary], $environment);
    }
}
