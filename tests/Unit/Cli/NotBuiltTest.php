<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\NotBuilt;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use Symfony\Component\Console\Tester\CommandTester;

it('is a command with the name and description it was given', function (): void {
    $command = NotBuilt::command('plan', 'Cut the shards');

    expect($command->getName())->toBe('plan')
        ->and($command->getDescription())->toBe('Cut the shards');
});

it('says it is not built, whatever it is given, and cannot judge', function (): void {
    $tester = new CommandTester(NotBuilt::command('verdict', 'Judge'));

    expect($tester->execute(['--plan' => '.mutation-gate/plan.json', 'extra' => 'argument']))->toBe(2)
        ->and(Printed::by($tester->getOutput()))->toBe("mutation-gate verdict is not built yet, so it cannot judge anything.\n");
});
