<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\SourceFunctions;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A mutant of a file at a line. */
function sourceMutantAt(string $file, int $line): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of($file), 'Plus', '@@ @@', 0),
        'Plus-1',
        Location::of(Path::of($file), Line::of($line), Line::of($line)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::Killed,
        Seconds::of(0.1),
    );
}

/** The functions of these files of a project, which it can read. */
function sourceFunctionsIn(string $project, Paths $files): SourceFunctions
{
    $functions = SourceFunctions::read(Directory::at($project), $files);

    return $functions instanceof SourceFunctions ? $functions : throw new RuntimeException($functions->why());
}

it('names the function a mutant is in, and nameless code where its file is gone', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', "<?php\n\nfunction add(): int\n{\n    return 1 + 1;\n}\n");
    $files = Paths::of(Path::of('src/Money.php'), Path::of('src/Gone.php'));
    $read = sourceFunctionsIn($project, $files);

    expect($read->around(sourceMutantAt('src/Money.php', 5)))
        ->toEqual(Enclosing::named(Path::of('src/Money.php'), 'add'))
        ->and($read->around(sourceMutantAt('src/Gone.php', 5)))->toEqual(Nameless::code())
        ->and($read->files())->toEqual(Paths::of(Path::of('src/Money.php')));
});

it('cannot judge a source file it cannot read', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php/Inside.php', "<?php\n");

    expect(SourceFunctions::read(Directory::at($project), Paths::of(Path::of('src/Money.php'))))
        ->toEqual(CannotJudge::because(sprintf('%s/src/Money.php could not be read.', $project)));
});
