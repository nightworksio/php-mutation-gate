<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\SessionLeader;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ProcessHold;

afterEach(function (): void {
    putenv(SessionLeader::VARIABLE);
});

$found = static fn(string $path): Closure => static fn(string $program): string => $program === 'setsid' ? $path : '';

it('starts each process under setsid where the system has it, asking nothing of PHP', function () use ($found): void {
    $ran = [];

    expect(SessionLeader::told(ProcessHold::Auto, $found('/usr/bin/setsid'), static function (array $command) use (&$ran): bool {
        $ran[] = $command;

        return true;
    }))->toBeTrue()
        ->and(getenv(SessionLeader::VARIABLE))->toBe('/usr/bin/setsid')
        ->and(SessionLeader::prefix())->toBe(['/usr/bin/setsid'])
        ->and(SessionLeader::leads())->toBeTrue()
        ->and($ran)->toBe([]);
});

it('starts each process under this PHP with no ini where the system has no setsid and that PHP can leave a session', function () use ($found): void {
    $ran = [];

    expect(SessionLeader::told(ProcessHold::Require, $found(''), static function (array $command) use (&$ran): bool {
        $ran[] = $command;

        return true;
    }))->toBeTrue()
        ->and(getenv(SessionLeader::VARIABLE))->toBe(PHP_BINARY)
        ->and(array_slice(SessionLeader::prefix(), 0, 3))->toBe([PHP_BINARY, '-n', '-r'])
        ->and(SessionLeader::prefix()[4])->toBe('--')
        ->and($ran)->toBe([[PHP_BINARY, '-n', '-r', 'exit(function_exists("posix_setsid") && function_exists("pcntl_exec") ? 0 : 1);']]);
});

it('starts each process as before where neither can lead, under auto, and refuses under require, saying why', function () use ($found): void {
    $failing = static fn(array $command): bool => $command === [];
    putenv(sprintf('%s=elsewhere', SessionLeader::VARIABLE));

    expect(SessionLeader::told(ProcessHold::Auto, $found(''), $failing))->toBeTrue()
        ->and(getenv(SessionLeader::VARIABLE))->toBeFalse()
        ->and(SessionLeader::prefix())->toBe([])
        ->and(SessionLeader::leads())->toBeFalse()
        ->and(SessionLeader::told(ProcessHold::Require, $found(''), $failing))->toEqual(CannotJudge::because(sprintf(<<<'SAID'
            processes.group is require, but this system has no setsid,
            and %s cannot leave a session and replace itself without an ini of its own.
            Install util-linux, or set processes.group to auto or off.
            SAID, PHP_BINARY)));
});

it('starts each process as before under off, looking for no leader', function (): void {
    $asked = [];
    putenv(sprintf('%s=elsewhere', SessionLeader::VARIABLE));

    expect(SessionLeader::told(ProcessHold::Off, static function (string $program) use (&$asked): string {
        $asked[] = $program;

        return '/usr/bin/setsid';
    }, static function (array $command) use (&$asked): bool {
        $asked[] = implode(' ', $command);

        return true;
    }))->toBeTrue()
        ->and(getenv(SessionLeader::VARIABLE))->toBeFalse()
        ->and($asked)->toBe([]);
});
