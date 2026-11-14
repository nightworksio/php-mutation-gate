<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\FromArchive;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The gate's own autoload, as its manifest declares it. */
$gate = ['psr-4' => ['NightWorksIO\\MutationGate\\' => 'src/']];

$installing = static fn(string ...$packages): string => (string) json_encode(['packages' => array_map(
    static fn(string $name): array => [
        'name' => $name,
        'version' => '1.0.0',
        'type' => 'library',
        'autoload' => $name === ThisPackage::COMPOSER ? $gate : [],
    ],
    $packages,
)]);

it('runs in a project that installs no copy of the gate, and loads the project\'s autoloader', function () use ($installing): void {
    $project = Scratch::directory();
    Scratch::write($project, 'vendor/composer/installed.json', $installing('phpunit/phpunit'));
    Scratch::write($project, 'vendor/autoload.php', '<?php');
    $started = new FromArchive(OwnArchive::at('/opt/mutation-gate.phar'), $project);

    expect($started->refusing())->toEqual(NotGiven::value())
        ->and($started->vendor())->toBe(sprintf('%s/vendor', $project))
        ->and($started->autoloader())->toBe(sprintf('%s/vendor/autoload.php', $project));
});

it('runs in a project Composer installed nothing in, and loads no autoloader there', function (): void {
    $started = new FromArchive(OwnArchive::at('/opt/mutation-gate.phar'), Scratch::directory());

    expect($started->refusing())->toEqual(NotGiven::value())
        ->and($started->autoloader())->toEqual(NotGiven::value());
});

it('finds the project\'s packages where its manifest says Composer installed them', function () use ($installing): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"config": {"vendor-dir": "lib"}}');
    Scratch::write($project, 'lib/composer/installed.json', $installing('nightworksio/mutation-gate'));

    expect(new FromArchive(OwnArchive::at('/opt/mutation-gate.phar'), $project)->vendor())->toBe(sprintf('%s/lib', $project));
});

it('refuses a project that installs the gate with Composer', function () use ($installing): void {
    $project = Scratch::directory();
    Scratch::write($project, 'vendor/composer/installed.json', $installing('phpunit/phpunit', 'nightworksio/mutation-gate'));

    expect(new FromArchive(OwnArchive::at('/opt/mutation-gate.phar'), $project)->refusing())->toEqual(CannotJudge::because(
        'This project installs the gate with Composer, and two copies cannot coexist: run vendor/bin/mutation-gate.',
    ));
});

it('refuses the gate\'s own repository, whose autoloader declares the archive\'s classes', function () use ($gate, $installing): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', (string) json_encode(['name' => ThisPackage::COMPOSER, 'autoload' => $gate]));
    Scratch::write($project, 'vendor/composer/installed.json', $installing('phpunit/phpunit'));

    expect(new FromArchive(OwnArchive::at('/opt/mutation-gate.phar'), $project)->refusing())->toEqual(CannotJudge::because(
        'This project is the gate, whose classes the archive\'s would clash with: run bin/mutation-gate, or a judge\'s archive.',
    ));
});

it('runs beside a copy of the gate whose classes are not the archive\'s own, as a judge\'s scoped classes are not', function (): void {
    $project = Scratch::directory();
    $elsewhere = ['psr-4' => ['Acme\\Gate\\' => 'src/']];
    Scratch::write($project, 'composer.json', (string) json_encode(['name' => ThisPackage::COMPOSER, 'autoload' => $elsewhere]));
    Scratch::write($project, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => ThisPackage::COMPOSER, 'version' => '1.0.0', 'type' => 'library', 'autoload' => $elsewhere],
    ]]));

    expect(new FromArchive(OwnArchive::at('/opt/mutation-gate.phar'), $project)->refusing())->toEqual(NotGiven::value());
});

it('refuses an archive PHP would read its path as ini syntax in', function (string $file): void {
    expect(new FromArchive(OwnArchive::at($file), Scratch::directory())->refusing())->toEqual(CannotJudge::because(sprintf(
        'PHP reads a ", a ${ or a \\\\ in a setting as ini syntax, so it cannot load the PHAR at %s. Move it.',
        $file,
    )));
})->with(['/opt/a"b/mutation-gate.phar', '/opt/${HOME}/mutation-gate.phar']);
