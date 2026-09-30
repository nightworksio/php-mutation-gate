<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\PestPatch;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function (): void {
    Scratch::sweep();
});

it('is pest:patch, which applies the optional Pest patches', function (): void {
    $command = PestPatch::command('/nowhere');

    expect($command->getName())->toBe('pest:patch')
        ->and($command->getDescription())->toBe('Apply the optional Pest patches');
});

it('patches pest-plugin-mutate in the vendor directory and says so', function (): void {
    $vendor = MutatePlugin::pristine()->vendor();
    $tester = new CommandTester(PestPatch::command($vendor));

    expect($tester->execute([]))->toBe(0)
        ->and(Printed::by($tester->getOutput()))
        ->toBe("pest:patch patched 3 of the 3 files it changes in pest-plugin-mutate.\n");
});

it('fails the install, with the reason on its error output, when it cannot patch', function (): void {
    $vendor = Scratch::directory();
    $tester = new CommandTester(PestPatch::command($vendor));
    $status = $tester->execute([], ['capture_stderr_separately' => true]);
    $output = $tester->getOutput();
    $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

    expect($status)->toBe(2)
        ->and(Printed::by($output))->toBe('')
        ->and(Printed::by($errors))->toBe(sprintf(
            "pest:patch cannot read %s/pestphp/pest-plugin-mutate/src/MutationTest.php. "
            . "Is pest-plugin-mutate installed?\n",
            $vendor,
        ));
});
