<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function count;
use function crc32;
use function implode;
use function pack;

/**
 * A zip archive that stores each file as it is, uncompressed, dated the
 * first day zip can date, so the same files always make the same bytes: the
 * shape an artifact of a workflow run is downloaded in.
 *
 * @internal
 */
final readonly class StoredZip
{
    private const string LOCAL = "PK\x03\x04";

    private const string CENTRAL = "PK\x01\x02";

    private const string END = "PK\x05\x06";

    /** The version of zip that reads a stored file. */
    private const int VERSION = 20;

    /** The first day zip can date, 1980-01-01, as it writes a date. */
    private const int FIRST_DAY = 0x21;

    /**
     * The archive of these files, each by its name, in the order given.
     *
     * @param array<string, string> $files
     */
    public static function of(array $files): string
    {
        $entries = '';
        $directory = '';

        foreach ($files as $name => $bytes) {
            $described = pack(
                'vvvvVVVv',
                0,
                0,
                0,
                self::FIRST_DAY,
                crc32($bytes),
                Bytes::length($bytes),
                Bytes::length($bytes),
                Bytes::length($name),
            );
            $directory = implode('', [
                $directory,
                self::CENTRAL,
                pack('vv', self::VERSION, self::VERSION),
                $described,
                pack('vvvvVV', 0, 0, 0, 0, 0, Bytes::length($entries)),
                $name,
            ]);
            $entries = implode('', [
                $entries,
                self::LOCAL,
                pack('v', self::VERSION),
                $described,
                pack('v', 0),
                $name,
                $bytes,
            ]);
        }

        $count = count($files);

        return implode('', [
            $entries,
            $directory,
            self::END,
            pack('vvvvVVv', 0, 0, $count, $count, Bytes::length($directory), Bytes::length($entries), 0),
        ]);
    }
}
