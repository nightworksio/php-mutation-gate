<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\MutatedFiles;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads each file the mutants are in that the project holds', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    Scratch::write($project, 'src/Log.php', '<?php // log');

    expect(MutatedFiles::in(Directory::at($project))->of(Verdicts::everyJudgement()))->toEqual(Sources::none()
        ->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY))
        ->with(Path::of('src/Log.php'), Contents::of('<?php // log')));
});

it('reads nothing of a project without the files', function (): void {
    expect(MutatedFiles::in(Directory::at(Scratch::directory()))->of(Verdicts::everyJudgement()))->toEqual(Sources::none());
});
