<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function chmod;
use function dirname;
use function file_exists;
use function fileowner;
use function fileperms;
use function is_dir;
use function is_int;
use function is_link;
use function lstat;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\FileMode;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeRefusal;

use function posix_geteuid;
use function posix_kill;
use function sprintf;
use function unlink;

/**
 * The Unix socket a daemon of `mutation-gate serve` listens on, in the
 * gate's directory of the project it serves (ADR-0078), named relative to
 * the project's root, the directory the gate runs in, so a deep root never
 * makes the name too long for a socket. A client sends its request only to
 * a socket that is the user's own, in a directory that is the user's own
 * and no other may write to, answered by a process of the user's own where
 * the platform tells the peer; anything else may be another user's, who
 * would read the request and forge the answer.
 *
 * @internal
 */
final readonly class ServeSocket
{
    private const string TAKEN = 'A daemon already serves this project on %s.';

    private const string LINKED = '%s is a symbolic link, which the daemon\'s socket never is.';

    private const string FOREIGN = '%s belongs to another user.';

    private const string LOOSE = 'Others may reach into %s, so another user could put a socket there.';

    private const string NOT_SOCKET = '%s is not a socket.';

    private const string OTHER_PEER = 'A process of another user answers on %s.';

    private const string UNMADE = 'The daemon could not make %s.';

    private const string UNTOLD
        = 'PHP cannot tell who answers on the socket: that needs its sockets extension, Linux or macOS, and not root.';

    /** The user id of root, which may signal any process, so a signal tells no owner. */
    private const int ROOT = 0;

    private function __construct(private string $path, private int $owner)
    {
    }

    /** The project's socket, which the user running the gate owns. */
    public static function ofProject(): self
    {
        return new self(Workspace::serveSocket()->value(), posix_geteuid());
    }

    /** The socket at this path, which this user owns. */
    public static function at(string $path, int $owner): self
    {
        return new self($path, $owner);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The socket listening, in its directory, made where it is not there and
     * kept to its owner alone where it is the owner's, where no daemon
     * answers on it; a file no daemon answers on removed first. Or why it
     * cannot listen: a daemon answers, the directory or the socket is not
     * the owner's own, or no peer can be told here.
     */
    public function listened(): Listener|CannotJudge
    {
        $directory = dirname($this->path);
        if (! Listener::directoryMade($directory)) {
            return CannotJudge::because(sprintf(self::UNMADE, $directory));
        }

        if (! is_link($directory) && fileowner($directory) === $this->owner) {
            chmod($directory, FileMode::OWNER_ONLY);
        }

        $other = $this->connected();

        return match (true) {
            $other instanceof Connection => CannotJudge::because(sprintf(self::TAKEN, $this->path)),
            $other instanceof ServeRefusal => CannotJudge::because($other->why()),
            default => $this->bound(),
        };
    }

    /**
     * A connection to the daemon listening on it; none, where none does; or
     * why no request may go to it: the socket or its directory is not the
     * owner's own, or a process of another user answers.
     */
    public function connected(): Connection|NotGiven|ServeRefusal
    {
        $untrusted = Connection::tellsPeers() && $this->owner !== self::ROOT ? $this->untrusted() : self::UNTOLD;

        if ($untrusted !== '') {
            return ServeRefusal::because($untrusted);
        }

        $connection = $this->isThere() ? Connection::to($this->path) : NotGiven::value();

        return $connection instanceof Connection ? $this->trusted($connection) : $connection;
    }

    /** The connection, where the process at the other end is the owner's; closed, and why not, otherwise. */
    public function trusted(Connection $connection): Connection|ServeRefusal
    {
        if ($this->isOwnPeer($connection)) {
            return $connection;
        }

        $connection->closed();

        return ServeRefusal::because(sprintf(self::OTHER_PEER, $this->path));
    }

    /**
     * Whether the process at the other end is the owner's: the platform
     * tells it, and the owner may signal it (see owns). A peer it cannot
     * tell is no peer of the owner's.
     */
    public function isOwnPeer(Connection $connection): bool
    {
        return $this->owns($connection->peer());
    }

    /**
     * Whether a process is the owner's, by its id: the owner runs the gate,
     * is not root, and may signal it; none is not.
     */
    public function owns(int|NotGiven $process): bool
    {
        return $this->owner === posix_geteuid() && $this->owner !== self::ROOT && is_int($process)
            && posix_kill($process, 0);
    }

    /** Whether the socket's file is there. */
    public function isThere(): bool
    {
        return file_exists($this->path) || is_link($this->path);
    }

    /** The socket's file removed, where it is there. */
    public function removed(): void
    {
        if ($this->isThere()) {
            unlink($this->path);
        }
    }

    /** The socket bound, a file no daemon answered on removed first. */
    private function bound(): Listener|CannotJudge
    {
        $this->removed();

        return Listener::boundTo($this->path);
    }

    /** Why the socket or its directory may be another user's; nothing where both are the owner's own. */
    private function untrusted(): string
    {
        $directory = dirname($this->path);
        $socket = $this->socketUntrusted();

        return match (true) {
            is_link($directory) => sprintf(self::LINKED, $directory),
            $socket !== '' => $socket,
            ! is_dir($directory) => '',
            fileowner($directory) !== $this->owner => sprintf(self::FOREIGN, $directory),
            (fileperms($directory) & FileMode::OTHERS) !== 0 => sprintf(self::LOOSE, $directory),
            default => '',
        };
    }

    /** Why the socket may be another user's: a link, no socket, or not the owner's; nothing where there is none. */
    private function socketUntrusted(): string
    {
        $status = $this->isThere() ? lstat($this->path) : false;

        return match (true) {
            $status === false => '',
            is_link($this->path) => sprintf(self::LINKED, $this->path),
            ($status['mode'] & FileMode::TYPE) !== FileMode::SOCKET => sprintf(self::NOT_SOCKET, $this->path),
            $status['uid'] !== $this->owner => sprintf(self::FOREIGN, $this->path),
            default => '',
        };
    }
}
