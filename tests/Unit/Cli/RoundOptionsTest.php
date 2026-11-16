<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\RoundOptions;
use NightWorksIO\MutationGate\Core\Format\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The keys of `local` a command line of these options sets, as JSON.
 *
 * @param array<string, string> $options
 */
function roundsLaid(array $options, bool $taking = true): string
{
    $command = $taking ? RoundOptions::on(new Command('watch')) : new Command('watch');

    return Json::object(...RoundOptions::laid(new ArrayInput($options, $command->getDefinition())))->line();
}

it('sets local.rounds and local.fromMap from --rounds and --from-map, a count that is no whole number as its text', function (): void {
    expect([
        roundsLaid(['--rounds' => '3', '--from-map' => '.mutation-gate/carried']),
        roundsLaid(['--rounds' => 'three']),
        roundsLaid(['--from-map' => 'carried']),
        roundsLaid([]),
        roundsLaid([], taking: false),
    ])->toBe([
        '{"rounds":3,"fromMap":".mutation-gate/carried"}',
        '{"rounds":"three"}',
        '{"fromMap":"carried"}',
        '{}',
        '{}',
    ]);
});
