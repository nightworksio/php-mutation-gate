<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function count;
use function file_get_contents;
use function function_exists;
use function get_resources;
use function is_array;
use function is_dir;
use function is_link;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\SocketStreams;

use function octdec;
use function outbreak_descriptors;
use function preg_match;
use function readlink;
use function scandir;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function stream_get_meta_data;

/**
 * The descriptors a process holds that a forked child would share with it
 * (ADR-0117): each regular file and each pipe open for writing, and each
 * socket, past the three standard streams. A child that writes through one
 * moves the parent's offset, or writes into what the parent reads, and the
 * parent's run is the control that judges the child. `outbreak.so` counts
 * them where it is loaded, which sees a database client's own as well; on
 * Linux, the process's descriptors and their flags say; elsewhere, PHP's own
 * streams do, which see no client's.
 *
 * A stream forks no test while it holds a regular file open for writing, nor
 * one that holds more pipes or sockets than the stream held before its first
 * test: those are its runner's, the pipes it talks over among them.
 *
 * @internal
 */
final readonly class Descriptors
{
    /** Where Linux lists a process's file descriptors, and their flags. */
    private const string DESCRIPTORS = '/proc/self/fd';

    private const string FLAGS = '/proc/self/fdinfo/%s';

    /** How Linux names a socket and a pipe. */
    private const string SOCKET = 'socket:';

    private const string PIPE = 'pipe:';

    /** Where Linux names no file a test could write, though a descriptor points there. */
    private const array NO_FILE = ['/dev/', '/proc/'];

    /** A descriptor's flags, in octal, as fdinfo lists them. */
    private const string FLAGS_LINE = '/^flags:\s+([0-7]+)$/m';

    /** The bits of the flags that say how a descriptor is open: for reading alone where they are none. */
    private const int ACCESS = 3;

    /** The first descriptor past standard input, output and error. */
    private const int FIRST = 3;

    /** A stream's mode that writes. */
    private const array WRITES = ['w', 'a', 'x', 'c', '+'];

    private function __construct(private int $files, private int $pipes, private int $sockets)
    {
    }

    /** What this process holds now. */
    public static function held(): self
    {
        $counted = function_exists('outbreak_descriptors') ? outbreak_descriptors() : [];

        if (count($counted) === 3) {
            return new self((int) $counted[0], (int) $counted[1], (int) $counted[2]);
        }

        return is_dir(self::DESCRIPTORS) ? self::listed() : self::streams();
    }

    /** Whether a child forked now would share a descriptor with this process that it did not hold at the start. */
    public function shares(self $start): bool
    {
        return $this->files > 0 || $this->pipes > $start->pipes || $this->sockets > $start->sockets;
    }

    private static function listed(): self
    {
        $files = 0;
        $pipes = 0;
        $sockets = 0;
        $descriptors = scandir(self::DESCRIPTORS);

        foreach (is_array($descriptors) ? $descriptors : [] as $descriptor) {
            $path = sprintf('%s/%s', self::DESCRIPTORS, $descriptor);
            $target = is_link($path) && (int) $descriptor >= self::FIRST ? readlink($path) : false;

            if (! is_string($target)) {
                continue;
            }

            $writes = self::writes($descriptor);
            $sockets += str_starts_with($target, self::SOCKET) ? 1 : 0;
            $pipes += $writes && str_starts_with($target, self::PIPE) ? 1 : 0;
            $files += $writes && self::isFile($target) ? 1 : 0;
        }

        return new self($files, $pipes, $sockets);
    }

    /** Whether Linux says a descriptor is open for writing. */
    private static function writes(string $descriptor): bool
    {
        $info = file_get_contents(sprintf(self::FLAGS, $descriptor));

        return is_string($info)
            && preg_match(self::FLAGS_LINE, $info, $flags) === 1
            && (octdec($flags[1]) & self::ACCESS) !== 0;
    }

    /** Whether a descriptor's target is a file a test could write, a deleted one among them. */
    private static function isFile(string $target): bool
    {
        if (! str_starts_with($target, '/')) {
            return false;
        }

        foreach (self::NO_FILE as $prefix) {
            if (str_starts_with($target, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /** What PHP's own streams say, where the system lists no descriptors. */
    private static function streams(): self
    {
        $streams = get_resources(SocketStreams::STREAM);
        $files = 0;

        foreach ($streams as $stream) {
            $meta = stream_get_meta_data($stream);
            $plain = ($meta['wrapper_type'] ?? '') === 'plainfile';
            $files += $plain && self::modeWrites($meta['mode']) ? 1 : 0;
        }

        return new self($files, 0, SocketStreams::among($streams));
    }

    private static function modeWrites(string $mode): bool
    {
        foreach (self::WRITES as $writing) {
            if (str_contains($mode, $writing)) {
                return true;
            }
        }

        return false;
    }
}
