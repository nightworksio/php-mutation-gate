<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

/**
 * What the helper says of itself, read as untrusted input, and accepted only
 * where its name, version and protocol are exactly what this gate expects.
 */
final readonly class Handshake
{
    private const string UNREAD = 'The helper\'s handshake is not what this gate reads: %s';

    private const string OTHER = 'The helper is %s %s on protocol %d, and this gate asks %s %s on protocol %d.';

    private function __construct()
    {
    }

    /** The handshake the helper printed, where it agrees with this gate; or why the helper is not used. */
    public static function read(string $printed): self|NotAccelerated
    {
        $said = Node::decode($printed, Protocol::HANDSHAKE);

        try {
            $name = $said->field('name')->text();
            $version = $said->field('version')->text();
            $protocol = $said->field('protocol')->integer();
        } catch (NotInShape $unread) {
            return NotAccelerated::because(sprintf(self::UNREAD, $unread->getMessage()));
        }

        $agrees = $name === Protocol::HELPER && $version === Protocol::HELPER_VERSION
            && $protocol === Protocol::VERSION;

        return $agrees ? new self() : NotAccelerated::because(sprintf(
            self::OTHER,
            $name,
            $version,
            $protocol,
            Protocol::HELPER,
            Protocol::HELPER_VERSION,
            Protocol::VERSION,
        ));
    }
}
