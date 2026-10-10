<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

/**
 * What the gate and the helper must agree on before the gate asks anything:
 * the helper's name, its exact version, and the protocol both speak, which
 * changes whenever a request or an answer does (ADR-0029).
 */
final readonly class Protocol
{
    /** The protocol's number, which every request and answer carries. */
    public const int VERSION = 1;

    /** The helper's name, which is also its binary's. */
    public const string HELPER = 'mutation-gate-turbo';

    /** The one version of the helper this gate asks, the version in `turbo/mutation-gate-turbo/Cargo.toml`. */
    public const string HELPER_VERSION = '0.1.0';

    /** The command that has the helper say what it is. */
    public const string HANDSHAKE_COMMAND = 'handshake';

    /** The command that has the helper answer the request in the file it is given. */
    public const string ANSWER_COMMAND = 'answer';

    /** What a message names the helper's handshake as, where it cannot be read. */
    public const string HANDSHAKE = 'the handshake';

    /** What a message names the helper's answer as, where it cannot be read. */
    public const string ANSWER = 'the answer';
}
