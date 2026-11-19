<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function array_key_exists;

use Closure;

use function getenv;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ProcessHold;

use const PHP_BINARY;

use function putenv;
use function sprintf;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What a process the gate starts is started under so it leads a session of
 * its own, whose id is its own and which every process it starts joins
 * unless it leaves it, so the gate stops all of them with it, even one whose
 * parent has ended (`processes.group`, ADR-0158): `setsid` where the system
 * has it, else this PHP with no ini of its own, where it can leave a session
 * and replace itself, else nothing. The gate tells its own process the
 * choice once, and every start reads it. A process asks the system once
 * where setsid is and whether this PHP can lead, as neither changes while
 * the gate runs (ADR-0262).
 *
 * @internal
 */
final class SessionLeader
{
    /** The variable of the gate's own process that names the leader: `setsid`'s path, or this PHP's. */
    public const string VARIABLE = 'MUTATION_GATE_LEADER';

    /** What this PHP runs to leave the session it was started in and become the command after it. */
    private const string LEAVES = <<<'PHP'
        posix_setsid();
        $command = array_slice($argv, 1);
        $program = $command[0];
        foreach (str_contains($program, '/') ? [] : explode(':', (string) getenv('PATH')) as $directory) {
            if (is_executable($directory . '/' . $program)) {
                $program = $directory . '/' . $program;
                break;
            }
        }
        pcntl_exec($program, array_slice($command, 1));
        exit(127);
        PHP;

    /** What this PHP runs to say whether, with no ini of its own, it can leave a session and replace itself. */
    private const string CAN = 'exit(function_exists("posix_setsid") && function_exists("pcntl_exec") ? 0 : 1);';

    private const string NONE = <<<'SAID'
        processes.group is require, but this system has no setsid,
        and %s cannot leave a session and replace itself without an ini of its own.
        Install util-linux, or set processes.group to auto or off.
        SAID;
    /** @var array<string, string> each program's path this process found, by its name; nothing where none */
    private static array $found = [];

    /** @var array<string, bool> whether each command this process asked about exited with 0, by its words */
    private static array $succeeded = [];

    /**
     * The leader `processes.group` chooses, told to this process; or why it
     * requires one this system has not.
     *
     * @param Closure(string): string      $find     the path of a program by its name, or nothing
     * @param Closure(list<string>): bool $succeeds whether a command exits with 0
     */
    public static function told(ProcessHold $hold, Closure $find, Closure $succeeds): true|CannotJudge
    {
        $setsid = $hold->holds() ? $find('setsid') : '';
        $leader = match (true) {
            ! $hold->holds() => '',
            $setsid !== '' => $setsid,
            $succeeds([PHP_BINARY, '-n', '-r', self::CAN]) => PHP_BINARY,
            default => '',
        };
        putenv($leader === '' ? self::VARIABLE : sprintf('%s=%s', self::VARIABLE, $leader));

        return $leader === '' && $hold === ProcessHold::Require
            ? CannotJudge::because(sprintf(self::NONE, PHP_BINARY))
            : true;
    }

    /** The leader `processes.group` chooses on this system, told to this process; or why it has none it requires. */
    public static function toldHere(ProcessHold $hold): true|CannotJudge
    {
        return self::told($hold, self::foundOnce(...), self::succeedsOnce(...));
    }

    /**
     * What a command is started under, as this process was told; nothing
     * where it was told no leader.
     *
     * @return list<string>
     */
    public static function prefix(): array
    {
        $leader = getenv(self::VARIABLE);

        return match ($leader) {
            false, '' => [],
            PHP_BINARY => [PHP_BINARY, '-n', '-r', self::LEAVES, '--'],
            default => [$leader],
        };
    }

    /** Whether this process was told a leader, so each process it starts leads a session whose id is its own. */
    public static function leads(): bool
    {
        return self::prefix() !== [];
    }

    /** The path of a program by its name, as this process first found it; nothing where it found none. */
    private static function foundOnce(string $program): string
    {
        if (! array_key_exists($program, self::$found)) {
            $found = new ExecutableFinder()->find($program);
            self::$found[$program] = is_string($found) ? $found : '';
        }

        return self::$found[$program];
    }

    /**
     * Whether a command exited with 0 the first time this process ran it.
     *
     * @param list<string> $command
     */
    private static function succeedsOnce(array $command): bool
    {
        $words = implode("\0", $command);

        if (! array_key_exists($words, self::$succeeded)) {
            self::$succeeded[$words] = new Process($command)->run() === 0;
        }

        return self::$succeeded[$words];
    }
}
