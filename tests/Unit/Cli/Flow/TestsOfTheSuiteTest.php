<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\TestsOfTheSuite;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('places the map\'s tests in every file of test cases, past the suite\'s support', function (): void {
    $files = ['tests/Arrange/Builds.php' => "<?php\n\nnamespace Tests\\Arrange;\n\nfinal class Builds {}\n", ...Flows::FILES];
    $project = Flows::project();
    Scratch::write($project, 'tests/Arrange/Builds.php', $files['tests/Arrange/Builds.php']);
    $checkout = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [Revision::workingTree()->name() => $files]);
    $adapters = Flows::adapters($project, [], $checkout);
    $inventory = Inventory::of($adapters, Flows::settings());

    $places = $inventory instanceof Inventory
        ? TestsOfTheSuite::placed($adapters, $inventory->suite, Flows::map())
        : TestPlaces::none();

    expect([...$places->files()])->toEqual([Path::of('tests/MoneyTest.php')])
        ->and([...$places->in(Path::of('tests/MoneyTest.php'))])->toEqual([TestId::of('MoneyTest::adds')]);
});
