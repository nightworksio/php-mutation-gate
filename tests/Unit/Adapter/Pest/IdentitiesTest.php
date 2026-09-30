<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Diff;
use NightWorksIO\MutationGate\Adapter\Pest\Identities;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

it('ids each mutant by its file, mutator and change, counting those before it by file and line that share all three', function (): void {
    $pest = PestRun::diff('return $a + $b;', 'return $a - $b;');
    $planned = static fn(string $id, string $file, int $line): array => [
        'file' => $file,
        'start' => $line,
        'end' => $line,
        'mutator' => PlusToMinus::class,
        'diff' => $pest,
        'mutated' => PestRun::mutated($id),
    ];
    $diff = Diff::fromPest($pest);
    $id = static fn(string $file, int $occurrence): MutantId => MutantId::hash(Path::of($file), PlusToMinus::class, $diff, $occurrence);

    expect(Identities::of(Root::of('/p'), [
        'late' => $planned('late', '/p/src/A.php', 20),
        'other' => $planned('other', '/p/src/B.php', 5),
        'early' => $planned('early', '/p/src/A.php', 10),
    ]))->toEqual([
        'early' => $id('src/A.php', 0),
        'late' => $id('src/A.php', 1),
        'other' => $id('src/B.php', 0),
    ]);
});
