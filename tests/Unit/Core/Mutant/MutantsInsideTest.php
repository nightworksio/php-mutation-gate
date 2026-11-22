<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantsInside;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('holds the mutants inside each unit, in their order: its own file\'s, or those of every file inside its held path, and none of a unit not asked of', function (): void {
    $mutant = static fn(string $file, int $line): Mutant => Mutant::of(
        MutantId::hash(Path::of($file), 'Minus', '- a + b', $line),
        sprintf('%s-%d', $file, $line),
        Location::of(Path::of($file), Line::of($line), Line::of($line)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '- a + b'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $inside = MutantsInside::of(
        Mutants::of($mutant('src/KernelX.php', 2), $mutant('src/Kernel/Deep/B.php', 7), $mutant('src/Kernel/A.php', 3), $mutant('2026', 1)),
        Path::of('src/Kernel'),
        Path::of('src/KernelX.php'),
        Path::of('2026'),
        Path::of('src/Other.php'),
    );
    $lines = static fn(string $unit): array => array_map(static fn(Mutant $one): int => $one->location()->start()->number(), [...$inside->in(Path::of($unit))]);

    expect(array_map($lines, ['src/Kernel', 'src/KernelX.php', '2026', 'src/Other.php', 'src']))->toBe([[7, 3], [2], [1], [], []]);
});
