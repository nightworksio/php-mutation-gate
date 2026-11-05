<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Adapter\Serve\Listener;
use NightWorksIO\MutationGate\Adapter\Serve\ServeSocket;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeRefusal;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;

afterEach(function (): void {
    ServedProjects::left();
    Scratch::sweep();
});

/** Why a client refuses a socket, or what it connects to otherwise. */
$refusalOf = static function (ServeSocket $socket): string {
    $connected = $socket->connected();

    if ($connected instanceof Connection) {
        $connected->closed();
    }

    return $connected instanceof ServeRefusal ? $connected->why() : $connected::class;
};

it('listens where no daemon answers, keeping its directory to its owner alone and the socket to the owner, and refuses a second daemon', function (): void {
    ServedProjects::entered();
    chmod('.mutation-gate', 0o755);
    $listener = ServedProjects::socket()->listened();

    expect($listener)->toBeInstanceOf(Listener::class)
        ->and(fileperms('.mutation-gate') & 0o777)->toBe(0o700)
        ->and(fileperms(ServedProjects::SOCKET) & 0o077)->toBe(0)
        ->and(ServedProjects::socket()->listened())
        ->toEqual(CannotJudge::because('A daemon already serves this project on .mutation-gate/serve.sock.'));
});

it('makes the gate\'s directory where it is not there, and removes a socket file no daemon answers on before it binds', function (): void {
    $project = ServedProjects::entered();
    rmdir(sprintf('%s/.mutation-gate', $project));
    $first = ServedProjects::socket()->listened();

    if ($first instanceof Listener) {
        $first->closed();
    }

    expect(fileperms('.mutation-gate') & 0o777)->toBe(0o700)
        ->and(ServedProjects::socket()->listened())->toBeInstanceOf(Listener::class);
});

it('says why it cannot make the gate\'s directory', function (): void {
    $project = ServedProjects::entered();
    touch(sprintf('%s/blocked', $project));

    expect(ServeSocket::at('blocked/inner/serve.sock', posix_geteuid())->listened())
        ->toEqual(CannotJudge::because('The daemon could not make blocked/inner.'));
});

it('connects a client to the daemon listening, the peer its own process, and to nothing where none listens', function (): void {
    ServedProjects::entered();

    expect(ServedProjects::socket()->connected())->toBeInstanceOf(NotGiven::class)
        ->and(ServedProjects::socket()->isThere())->toBeFalse();

    $listener = ServedProjects::socket()->listened();
    $connected = ServedProjects::socket()->connected();

    expect($listener)->toBeInstanceOf(Listener::class)
        ->and($connected instanceof Connection ? $connected->peer() : 0)->toBe(getmypid());
});

/** How each case lays the project out, and the socket a client then looks at. */
$arrangements = [
    'linked socket' => static function (): ServeSocket {
        ServeSocket::at('.mutation-gate/real.sock', posix_geteuid())->listened();
        symlink('real.sock', ServedProjects::SOCKET);

        return ServedProjects::socket();
    },
    'no socket' => static function (): ServeSocket {
        touch(ServedProjects::SOCKET);

        return ServedProjects::socket();
    },
    'foreign socket' => static function (): ServeSocket {
        ServedProjects::socket()->listened();

        return ServeSocket::at(ServedProjects::SOCKET, posix_geteuid() + 1);
    },
    'foreign directory' => static fn(): ServeSocket => ServeSocket::at(ServedProjects::SOCKET, posix_geteuid() + 1),
    'loose directory' => static function (): ServeSocket {
        chmod('.mutation-gate', 0o750);

        return ServedProjects::socket();
    },
    'linked directory' => static function (): ServeSocket {
        symlink('.mutation-gate', 'linked');

        return ServeSocket::at('linked/serve.sock', posix_geteuid());
    },
    'root' => static fn(): ServeSocket => ServeSocket::at(ServedProjects::SOCKET, 0),
];

it('sends nothing to a socket that is a link, no socket, or another user\'s, or in a directory that is a link, another user\'s, or open to others', function (string $case, string $why) use ($refusalOf, $arrangements): void {
    ServedProjects::entered();

    expect($refusalOf($arrangements[$case]()))->toBe($why);
})->with([
    'a socket that is a symbolic link' => ['linked socket', '.mutation-gate/serve.sock is a symbolic link, which the daemon\'s socket never is.'],
    'a file that is no socket' => ['no socket', '.mutation-gate/serve.sock is not a socket.'],
    'a socket of another user' => ['foreign socket', '.mutation-gate/serve.sock belongs to another user.'],
    'a directory of another user' => ['foreign directory', '.mutation-gate belongs to another user.'],
    'a directory others may reach into' => ['loose directory', 'Others may reach into .mutation-gate, so another user could put a socket there.'],
    'a directory that is a symbolic link' => ['linked directory', 'linked is a symbolic link, which the daemon\'s socket never is.'],
    'root, for whom a signal tells no owner' => ['root', 'PHP cannot tell who answers on the socket: that needs its sockets extension, Linux or macOS, and not root.'],
]);

it('refuses to start a daemon where a client would refuse it', function (): void {
    ServedProjects::entered();
    touch(ServedProjects::SOCKET);

    expect(ServedProjects::socket()->listened())->toEqual(CannotJudge::because('.mutation-gate/serve.sock is not a socket.'));
});

it('owns only a process the owner, who runs the gate and is not root, may signal; none it cannot tell', function (): void {
    $self = (int) getmypid();

    expect(ServedProjects::socket()->owns($self))->toBeTrue()
        ->and(ServedProjects::socket()->owns(1))->toBeFalse()
        ->and(ServedProjects::socket()->owns(NotGiven::value()))->toBeFalse()
        ->and(ServeSocket::at(ServedProjects::SOCKET, 0)->owns($self))->toBeFalse()
        ->and(ServeSocket::at(ServedProjects::SOCKET, posix_geteuid() + 1)->owns($self))->toBeFalse()
        ->and(ServeSocket::ofProject()->path())->toBe(ServedProjects::SOCKET);
});

it('refuses a peer it cannot tell, as a stream that is no socket is', function (): void {
    $memory = fopen('php://memory', 'r+');

    expect(is_resource($memory) && ServedProjects::socket()->isOwnPeer(Connection::over($memory)))->toBeFalse();
});

it('closes a connection whose other end is not the owner\'s, and says so, and keeps one whose end is', function (): void {
    ServedProjects::entered();
    $listener = ServedProjects::socket()->listened();
    $refused = Connection::to(ServedProjects::SOCKET, 1.0);
    $kept = Connection::to(ServedProjects::SOCKET, 1.0);

    expect($listener)->toBeInstanceOf(Listener::class)
        ->and($refused instanceof Connection ? ServeSocket::at(ServedProjects::SOCKET, posix_geteuid() + 1)->trusted($refused) : null)
        ->toEqual(ServeRefusal::because('A process of another user answers on .mutation-gate/serve.sock.'))
        ->and($kept instanceof Connection ? ServedProjects::socket()->trusted($kept) : null)->toBe($kept);
});
