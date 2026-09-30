<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Diff;
use NightWorksIO\MutationGate\Adapter\Pest\Identities;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

it('ids each mutant by its file, mutator and change, counting those before it by file and line that share all three', function (): void {
    $pest = PestRun::diff('return $a + $b;', 'return $a - $b;');
    $planned = static fn(string $id, string $file, int $line): PlannedMutant => PestRun::mutant(
        $id,
        $file,
        $line,
        PlusToMinus::class,
        'return $a + $b;',
        'return $a - $b;',
    );
    $diff = Diff::fromPest($pest);
    $id = static fn(string $file, int $occurrence): MutantId => MutantId::hash(Path::of($file), PlusToMinus::class, $diff, $occurrence);

    expect(Identities::of(Root::of('/p'), [
        $planned('late', '/p/src/A.php', 20),
        $planned('other', '/p/src/B.php', 5),
        $planned('early', '/p/src/A.php', 10),
    ]))->toEqual([
        'early' => $id('src/A.php', 0),
        'late' => $id('src/A.php', 1),
        'other' => $id('src/B.php', 0),
    ]);
});

it('counts a mutant whose change differs from another only in whitespace as the same change', function (): void {
    $narrow = PestRun::mutant('narrow', '/p/src/A.php', 10, PlusToMinus::class, 'return $a + $b;', 'return $a - $b;');
    $wide = PestRun::mutant('wide', '/p/src/A.php', 20, PlusToMinus::class, 'return $a  +  $b;', 'return $a  -  $b;');
    $diff = Diff::fromPest(PestRun::diff('return $a  +  $b;', 'return $a  -  $b;'));

    expect(Identities::of(Root::of('/p'), [$narrow, $wide])['wide'])
        ->toEqual(MutantId::hash(Path::of('src/A.php'), PlusToMinus::class, $diff, 1));
});

it('ids apart the mutants Pest gives one id, by how many before each share the change', function (): void {
    $same = PestRun::mutant('same', '/p/src/A.php', 10, PlusToMinus::class, 'return $a + $b;', 'return $a - $b;');
    $diff = Diff::fromPest(PestRun::diff('return $a + $b;', 'return $a - $b;'));
    $id = static fn(int $occurrence): MutantId => MutantId::hash(Path::of('src/A.php'), PlusToMinus::class, $diff, $occurrence);

    expect(Identities::of(Root::of('/p'), PlannedMutant::numbered([$same, $same])))
        ->toEqual(['same' => $id(0), 'same#1' => $id(1)]);
});
