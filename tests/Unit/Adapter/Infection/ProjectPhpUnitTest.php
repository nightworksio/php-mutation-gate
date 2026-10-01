<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Infection\ProjectPhpUnit;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A project whose PHPUnit config, in `config`, has this text, beside each file named.
 *
 * @param array<string, string> $files
 * @return array{Project, OwnConfig}
 */
function infectionPhpUnit(array $files): array
{
    $root = Scratch::directory();
    Scratch::write($root, 'infection.json5', '{"phpUnit": {"configDir": "config"}}');

    foreach ($files as $name => $text) {
        Scratch::write($root, sprintf('config/%s', $name), $text);
    }

    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));
    $config = OwnConfig::in($project);

    return [$project, $config instanceof OwnConfig ? $config : throw new RuntimeException('no config')];
}

it('finds the first of PHPUnit\'s names in phpUnit.configDir, and none where there is none', function (): void {
    [$project, $config] = infectionPhpUnit(['phpunit.xml.dist' => '<phpunit/>', 'phpunit.xml' => '<phpunit/>']);
    [$bare, $none] = infectionPhpUnit([]);

    expect(ProjectPhpUnit::file($project, $config))->toBe(sprintf('%s/config/phpunit.xml', $project->root()))
        ->and(ProjectPhpUnit::file($bare, $none))->toEqual(NotGiven::value());
});

it('reads where the config has PHP print errors: the last display_errors its php section sets', function (
    string $config,
    ErrorDisplay|NotGiven $display,
): void {
    [$project, $own] = infectionPhpUnit(['phpunit.xml' => $config]);

    expect(ProjectPhpUnit::display($project, $own))->toEqual($display);
})->with([
    'hidden' => ['<phpunit><php><ini name="display_errors" value="0"/></php></phpunit>', ErrorDisplay::Nowhere],
    'shown again, last' => [
        '<phpunit><php><ini name="display_errors" value="Off"/><ini name="display_errors" value="On"/></php></phpunit>',
        ErrorDisplay::Stdout,
    ],
    'another setting' => ['<phpunit><php><ini name="memory_limit" value="0"/></php></phpunit>', NotGiven::value()],
    'outside php' => ['<phpunit><ini name="display_errors" value="0"/></phpunit>', NotGiven::value()],
    'not XML' => ['<phpunit>', NotGiven::value()],
]);

it('reads no display where the project has no PHPUnit config', function (): void {
    [$project, $config] = infectionPhpUnit([]);

    expect(ProjectPhpUnit::display($project, $config))->toEqual(NotGiven::value());
});
