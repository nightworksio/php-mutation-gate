<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\MachineCommand;
use NightWorksIO\MutationGate\Cli\Flow\Machines;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @param list<string> $program
 *
 * @return array{int, mixed, string} the command's exit code, what it printed, decoded, and what it said on the error stream
 */
function machinePrinted(array $program): array
{
    $tester = new CommandTester(MachineCommand::command());
    $code = $tester->execute($program === [] ? [] : ['program' => $program], ['capture_stderr_separately' => true]);

    return [$code, json_decode($tester->getDisplay(), associative: true), $tester->getErrorOutput()];
}

function machineKeys(mixed $printed): mixed
{
    return is_array($printed) ? array_values(array_diff(array_keys($printed), ['cpu'])) : $printed;
}

it('prints this machine\'s cores as one JSON object, and times nothing unasked', function (): void {
    [$code, $printed] = machinePrinted([]);

    expect($code)->toBe(0)
        ->and(machineKeys($printed))->toBe(['cores']);
});

it('times a program it is given, adding its rounds and their median seconds', function (): void {
    [$code, $printed] = machinePrinted([PHP_BINARY, '-r', 'echo str_repeat("a", 10);']);

    expect($code)->toBe(0)
        ->and(machineKeys($printed))->toBe(['cores', 'rounds', 'seconds'])
        ->and(is_array($printed) ? $printed['rounds'] : $printed)->toBe(Machines::ROUNDS)
        ->and(is_array($printed) ? $printed['seconds'] : $printed)->toBeGreaterThan(0.0);
});

it('ends with 2 where a round of the program does not end with 0', function (): void {
    [$code, , $errors] = machinePrinted([PHP_BINARY, '-r', 'exit(3);']);

    expect($code)->toBe(2)
        ->and($errors)->toContain('did not end with 0');
});
