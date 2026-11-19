<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sodium_base642bin;

use const SODIUM_BASE64_VARIANT_ORIGINAL;

use function sodium_bin2base64;

use SodiumException;

/**
 * Bytes written as text another process reads back whole: JSON holds only
 * UTF-8, and a diff or a path of a file that is not UTF-8 would lose what
 * is not.
 *
 * @internal
 */
final readonly class ByteText
{
    public static function of(string $bytes): string
    {
        return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /** The bytes a text of {@see of()} holds; refused where it holds none. */
    public static function read(Node $written): string
    {
        return self::bytes($written->text(), $written->at());
    }

    /** The bytes a text of {@see of()} at this place holds; refused where it holds none. */
    public static function bytes(string $text, string $where): string
    {
        try {
            $bytes = sodium_base642bin($text, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            $bytes = false;
        }

        return $bytes === false ? throw NotInShape::at($where, 'base64 bytes') : $bytes;
    }
}
