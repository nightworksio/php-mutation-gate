<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\FileList;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('reads each file a listing printed, each ended by a NUL', function (): void {
    expect(FileList::read(ChildProcess::exited(0, "src/A.php\0src/B.php\0", ''), new Unlimited()))
        ->toEqual(Paths::of(Path::of('src/A.php'), Path::of('src/B.php')));
});

it('cannot say which files a listing that failed names, or one that never ran', function (): void {
    expect(FileList::read(ChildProcess::exited(1, "src/A.php\0", 'no config'), new Unlimited()))
        ->toEqual(CannotJudge::because(sprintf(
            'Mago could not list the files it analyses (%s).',
            ChildProcess::exited(1, '', 'no config')->said(),
        )))
        ->and(FileList::read(CannotJudge::because('no binary'), new Unlimited()))
        ->toEqual(CannotJudge::because('Mago could not list the files it analyses (no binary).'));
});
