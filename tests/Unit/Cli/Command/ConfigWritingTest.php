<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\ConfigWriting;
use NightWorksIO\MutationGate\Cli\Command\Destination;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Cli\Command\Setting;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes nothing, and says what to install, where the format needs a library the project lacks', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $extensions = new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)));
    $now = new DateTimeImmutable(Configs::NOW);
    $detected = new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)));
    $effective = new Effective($project, $extensions, $detected, $now);
    $formats = new Formats(static fn(): bool => false);
    $setting = new Setting($project, $extensions, $effective, $formats, $now, GatePin::unknown());
    $destination = Destination::of($project, NotGiven::value(), 'yaml');
    $settings = $effective->settings(CommandLine::nothing());

    expect($destination instanceof Destination && $settings instanceof Settings
        ? ConfigWriting::written($setting, $settings, $destination, NotGiven::value(), Layer::none(), Output::Written)
        : $destination)
        ->toEqual(CannotJudge::because('--format=yaml needs symfony/yaml. Install it: composer require --dev symfony/yaml'))
        ->and(is_file(sprintf('%s/mutation-gate.yaml', $project)))->toBeFalse();
});
