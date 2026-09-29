<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\PestPatch;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
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
    $vendor = Scratch::directory();

    foreach (['MutationTest.php', 'Plugins/Mutate.php', 'Tester/MutationTestRunner.php'] as $file) {
        $installed = (string) file_get_contents(Tree::at(sprintf('vendor/pestphp/pest-plugin-mutate/src/%s', $file)));
        Scratch::write($vendor, sprintf('pestphp/pest-plugin-mutate/src/%s', $file), $installed);
    }

    $tester = new CommandTester(PestPatch::command($vendor));

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toBe("pest:patch patched 3 of the 3 files it changes in pest-plugin-mutate.\n");
});

it('fails the install, with the reason, when it cannot patch', function (): void {
    $vendor = Scratch::directory();
    $tester = new CommandTester(PestPatch::command($vendor));

    expect($tester->execute([]))->toBe(2)
        ->and($tester->getDisplay())->toBe(sprintf(
            "pest:patch cannot read %s/pestphp/pest-plugin-mutate/src/MutationTest.php. "
            . "Is pest-plugin-mutate installed?\n",
            $vendor,
        ));
});
