<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\JudgedRun;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/** A project whose manifest names this package, autoloading from src/. */
function judgedRunProject(string $name): Directory
{
    $root = Scratch::directory();
    Scratch::write($root, 'composer.json', (string) json_encode(['name' => $name, 'autoload' => ['psr-4' => ['Acme\\' => 'src/']]]));

    return Directory::at($root);
}

it('leaves out a reach.everything glob whose files are all the gate\'s own, where another build of the gate judges it, and names each', function (): void {
    $judged = JudgedRun::of(judgedRunProject(ThisPackage::COMPOSER), OwnArchive::at('/opt/mutation-gate-judge.phar'));
    $globs = [Glob::of('src/Adapter/Pest/Warm/**'), Glob::of('bin/mutation-gate-pest-client'), Glob::of('tests/Fixtures/**'), Glob::of('**/*.php')];

    expect(array_map($judged->leavesOut(...), $globs))->toBe([true, true, false, false])
        ->and($judged->lines($globs))->toBe([
            "reach.everything `src/Adapter/Pest/Warm/**` reaches nothing by itself:\nits files are the gate's own, which the judge runs in their place (ADR-0140).",
            "reach.everything `bin/mutation-gate-pest-client` reaches nothing by itself:\nits files are the gate's own, which the judge runs in their place (ADR-0140).",
        ]);
});

it('leaves out nothing where the gate runs from its own installation, or the project is not the gate', function (): void {
    $glob = Glob::of('src/Adapter/Pest/Warm/**');

    expect(JudgedRun::of(judgedRunProject(ThisPackage::COMPOSER), NotGiven::value())->leavesOut($glob))->toBeFalse()
        ->and(JudgedRun::of(judgedRunProject('acme/library'), OwnArchive::at('/opt/mutation-gate.phar'))->leavesOut($glob))->toBeFalse()
        ->and(JudgedRun::of(Directory::at(Scratch::directory()), OwnArchive::at('/opt/mutation-gate.phar'))->lines([$glob]))->toBe([]);
});
