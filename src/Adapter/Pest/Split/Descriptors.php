<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_any;
use function count;
use function file_get_contents;
use function fstat;
use function function_exists;
use function get_resources;
use function is_array;
use function is_dir;
use function is_link;
use function is_string;

use NightWorksIO\MutationGate\Core\File\FileDescriptor;
use NightWorksIO\MutationGate\Core\Runner\SocketStreams;

use function octdec;
use function outbreak_descriptors;
use function preg_match;
use function readlink;
use function scandir;
use function sprintf;
use function stat;
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
 * A stream forks no test while it holds a regular file open for writing,
 * nor one that holds more pipes or sockets than the stream held before its
 * first test: those are its runner's, the pipes it talks over among them.
 * The files its own command line names are its runner's output, which a
 * child, killed as its test ends, never writes into what the parent reads
 * (see RunnerFiles); they are not counted.
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

    /** How many counts `outbreak_descriptors()` answers: the files, the pipes and the sockets. */
    private const int COUNTS = 3;

    /** A stream's mode that writes. */
    private const array WRITES = ['w', 'a', 'x', 'c', '+'];

    /** How PHP names the wrapper of a stream on a plain file. */
    private const string PLAIN_FILE = 'plainfile';

    private function __construct(private int $files, private int $pipes, private int $sockets)
    {
    }

    /** What this process holds now. */
    public static function held(): self
    {
        $runner = RunnerFiles::ofThisProcess();
        $counted = function_exists('outbreak_descriptors') ? outbreak_descriptors($runner->identities()) : [];

        if (count($counted) === self::COUNTS) {
            return new self(...$counted);
        }

        return is_dir(self::DESCRIPTORS) ? self::listed($runner) : self::streams($runner);
    }

    /** Whether a child forked now would share a descriptor with this process that it did not hold at the start. */
    public function shares(self $start): bool
    {
        return $this->files > 0 || $this->pipes > $start->pipes || $this->sockets > $start->sockets;
    }

    private static function listed(RunnerFiles $runner): self
    {
        $held = new self(0, 0, 0);
        $descriptors = scandir(self::DESCRIPTORS);

        foreach (is_array($descriptors) ? $descriptors : [] as $descriptor) {
            $held = $held->plus(self::one($descriptor, $runner));
        }

        return $held;
    }

    /** What one descriptor Linux lists counts as: a file, a pipe or a socket of a test's, or none of them. */
    private static function one(string $descriptor, RunnerFiles $runner): self
    {
        $path = sprintf('%s/%s', self::DESCRIPTORS, $descriptor);
        $target = is_link($path) && (int) $descriptor >= FileDescriptor::FIRST ? readlink($path) : false;

        if (! is_string($target)) {
            return new self(0, 0, 0);
        }

        $writes = self::writes($descriptor);

        return new self(
            $writes && self::isFile($target) && ! $runner->hold(stat($path)) ? 1 : 0,
            $writes && str_starts_with($target, self::PIPE) ? 1 : 0,
            str_starts_with($target, self::SOCKET) ? 1 : 0,
        );
    }

    private function plus(self $more): self
    {
        return new self($this->files + $more->files, $this->pipes + $more->pipes, $this->sockets + $more->sockets);
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
        return str_starts_with($target, '/')
            && ! array_any(self::NO_FILE, static fn(string $prefix): bool => str_starts_with($target, $prefix));
    }

    /** What PHP's own streams say, where the system lists no descriptors. */
    private static function streams(RunnerFiles $runner): self
    {
        $streams = get_resources(SocketStreams::STREAM);
        $files = 0;

        foreach ($streams as $stream) {
            $meta = stream_get_meta_data($stream) + ['wrapper_type' => ''];
            $plain = $meta['wrapper_type'] === self::PLAIN_FILE;
            $files += $plain && self::modeWrites($meta['mode']) && ! $runner->hold(fstat($stream)) ? 1 : 0;
        }

        return new self($files, 0, SocketStreams::among($streams));
    }

    private static function modeWrites(string $mode): bool
    {
        return array_any(self::WRITES, static fn(string $writing): bool => str_contains($mode, $writing));
    }
}
