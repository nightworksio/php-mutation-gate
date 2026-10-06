<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_filter;
use function array_intersect;
use function array_values;
use function count;
use function file_exists;
use function get_included_files;
use function get_resources;
use function getcwd;
use function is_array;
use function is_dir;
use function is_string;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function realpath;
use function scandir;
use function sprintf;
use function stat;
use function str_contains;
use function str_starts_with;
use function stream_get_meta_data;

/**
 * The guard a warm worker passes once it has booted, before it forks a
 * child (ADR-0023, decision 13): no socket open, which every child would
 * share, such as a database connection the bootstrap made, and no file the
 * run mutates loaded, which no child could serve its mutant in place of.
 * Sockets are counted among the process's open files where the system lists
 * them, so a connection held by an object, as PHP 8 holds `pgsql` and
 * `mysqli`'s, counts too; elsewhere among PHP's own stream resources.
 */
final readonly class BootCheck
{
    /** Where the system lists a process's open files by their numbers. */
    private const string OPEN_FILES = '/dev/fd';

    /** The bits of a file's mode that say what it is, and what they are for a socket. */
    private const int TYPE = 0o170000;

    private const int SOCKET = 0o140000;

    /** How the system names an open file: by its number. */
    private const string NUMBER = '/\A\d+\z/';

    /** The first open file a process opens itself: after its standard input, output and error. */
    private const int FIRST_OWN = 3;

    /** The resource type of PHP's streams, and the word in a stream's type that says it is a socket. */
    private const string STREAM = 'stream';

    private const string SOCKET_STREAM = 'socket';

    private const string SOCKETS
        = 'The boot left %d socket open once %s ran, which every forked child would share, so each mutant ran fresh.';

    private const string EVENTS
        = 'The boot started PHPUnit\'s events once %s ran, which every child would inherit, so each mutant ran fresh.';

    private const string LOADED = 'The boot loaded %s, which this run mutates, at %s, so each mutant ran fresh.';

    /** @param list<string> $mutated each file the run mutates, by its absolute path */
    private function __construct(private array $mutated, private Boot $boot, private string $openFiles)
    {
    }

    /**
     * The guard for a boot of a run that mutates these files, reading the
     * process's open files where the system lists them, or where a test does.
     *
     * @param list<string> $mutated each file the run mutates, by its absolute path
     */
    public static function of(array $mutated, Boot $boot, string $openFiles = self::OPEN_FILES): self
    {
        return new self($mutated, $boot, $openFiles);
    }

    /** Why the booted process must not be forked from; or none, where it may. */
    public function refusal(): Refusal|NotGiven
    {
        $sockets = $this->sockets();
        $loaded = $this->loaded();

        return match (true) {
            $sockets > 0 => Refusal::guarded(sprintf(self::SOCKETS, $sockets, $this->shown($this->boot->ran()))),
            $this->boot->startedEvents() => Refusal::guarded(sprintf(self::EVENTS, $this->shown($this->boot->ran()))),
            $loaded !== [] => Refusal::guarded(sprintf(
                self::LOADED,
                $this->shown($loaded[0]),
                $this->shown($this->boot->whereLoaded($loaded[0])),
            )),
            default => NotGiven::value(),
        };
    }

    /** A path as the project names it, where it is inside the project the worker runs in. */
    private function shown(string $path): string
    {
        $root = sprintf('%s/', getcwd());

        return str_starts_with($path, $root) ? mb_substr($path, mb_strlen($root)) : $path;
    }

    /** How many sockets the process holds open beyond its standard streams. */
    private function sockets(): int
    {
        $listed = is_dir($this->openFiles) ? scandir($this->openFiles) : false;

        return is_array($listed) ? $this->socketsAmong($listed) : $this->socketStreams();
    }

    /** @param list<string> $listed the names the system lists the process's open files by */
    private function socketsAmong(array $listed): int
    {
        $directory = $this->openFiles;
        $sockets = array_filter($listed, static function (string $name) use ($directory): bool {
            $path = sprintf('%s/%s', $directory, $name);
            $own = preg_match(self::NUMBER, $name) === 1 && (int) $name >= self::FIRST_OWN;
            $stat = $own && file_exists($path) ? stat($path) : false;

            return is_array($stat) && ($stat['mode'] & self::TYPE) === self::SOCKET;
        });

        return count($sockets);
    }

    /** How many of PHP's own stream resources are sockets. */
    private function socketStreams(): int
    {
        $sockets = 0;

        foreach (get_resources(self::STREAM) as $stream) {
            $sockets += str_contains(stream_get_meta_data($stream)['stream_type'], self::SOCKET_STREAM) ? 1 : 0;
        }

        return $sockets;
    }

    /** @return list<string> each file the run mutates that the process has loaded */
    private function loaded(): array
    {
        return array_values(array_intersect($this->real($this->mutated), $this->real(get_included_files())));
    }

    /**
     * @param  list<string> $files
     * @return list<string> each file that resolves, by its real path
     */
    private function real(array $files): array
    {
        $real = [];

        foreach ($files as $file) {
            $path = realpath($file);
            $real = is_string($path) ? [...$real, $path] : $real;
        }

        return $real;
    }
}
