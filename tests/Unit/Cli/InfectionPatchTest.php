<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Release;
use NightWorksIO\MutationGate\Cli\InfectionPatch;
use NightWorksIO\MutationGate\Tests\Support\InfectionSource;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function (): void {
    Scratch::sweep();
});

it('is infection:patch, which gives Infection the gate\'s mutant limit', function (): void {
    $command = InfectionPatch::command('/nowhere');

    expect($command->getName())->toBe('infection:patch')
        ->and($command->getDescription())->toBe('Give Infection the gate\'s mutant limit');
});

it('patches Infection in the vendor directory and says so', function (): void {
    $vendor = InfectionSource::pristine()->vendor();
    $tester = new CommandTester(InfectionPatch::command($vendor));

    expect($tester->execute([]))->toBe(0)
        ->and(Printed::by($tester->getOutput()))
        ->toBe("infection:patch patched 3 of the 3 files it changes in infection.\n");
});

it('fails the install, with the reason on its error output, when it cannot patch', function (): void {
    $vendor = InfectionSource::pristine()->vendor('0.34.0');
    $tester = new CommandTester(InfectionPatch::command($vendor));
    $status = $tester->execute([], ['capture_stderr_separately' => true]);
    $output = $tester->getOutput();
    $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

    expect($status)->toBe(2)
        ->and(Printed::by($output))->toBe('')
        ->and(Printed::by($errors))->toBe(sprintf(
            "infection:patch patched nothing: it patches Infection %s, and %s holds Infection 0.34.0. "
            . "Install a supported release.\n",
            Release::listed(),
            $vendor,
        ));
});
