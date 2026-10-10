<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

use function count;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

/**
 * The keys an answer holds, read as untrusted input: in the protocol this
 * gate reads, one for each path asked about, each a SHA-256, in the order
 * asked (ADR-0029).
 */
final readonly class AnsweredKeys
{
    private const string UNREAD = 'The helper\'s answer is not what this gate reads: %s';

    private const string MISCOUNTED = 'The helper answered %d keys for %d paths.';

    private const string NOT_A_KEY = 'The helper answered %s for %s, which is no SHA-256.';

    private const string OTHER_PROTOCOL = 'The helper answered in protocol %d, and this gate reads %d.';

    private function __construct()
    {
    }

    /**
     * Each path's key, as the answer gives it; or why there is no answer, or it is not read.
     *
     * @param  list<Path>                     $asked the paths asked about, in order
     * @return array<int, Digest>|NotAccelerated each key, by the place of its path
     */
    public static function read(Answer|NotAccelerated $answer, array $asked): array|NotAccelerated
    {
        if ($answer instanceof NotAccelerated) {
            return $answer;
        }

        $read = Node::decode($answer->text(), Protocol::ANSWER);

        try {
            $protocol = $read->field('protocol')->integer();
            $keys = $read->field('keys')->items();
        } catch (NotInShape $unread) {
            return NotAccelerated::because(sprintf(self::UNREAD, $unread->getMessage()));
        }

        return match (true) {
            $protocol !== Protocol::VERSION
                => NotAccelerated::because(sprintf(self::OTHER_PROTOCOL, $protocol, Protocol::VERSION)),
            count($keys) !== count($asked)
                => NotAccelerated::because(sprintf(self::MISCOUNTED, count($keys), count($asked))),
            default => self::digests($keys, $asked),
        };
    }

    /**
     * @param  list<Node>                     $keys
     * @param  list<Path>                     $asked
     * @return array<int, Digest>|NotAccelerated
     */
    private static function digests(array $keys, array $asked): array|NotAccelerated
    {
        $digests = [];

        foreach ($keys as $at => $key) {
            try {
                $text = $key->text();
            } catch (NotInShape $unread) {
                return NotAccelerated::because(sprintf(self::UNREAD, $unread->getMessage()));
            }

            if (! Digest::isSha256($text)) {
                return NotAccelerated::because(sprintf(self::NOT_A_KEY, $text, $asked[$at]->value()));
            }

            $digests[$at] = Digest::of($text);
        }

        return $digests;
    }
}
