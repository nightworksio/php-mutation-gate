<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\Hunk;

it('rewrites the lines a file ships into what they become', function (): void {
    $hunk = Hunk::in('MutationTest.php', "a();\n", "b();\n");

    expect($hunk->file())->toBe('MutationTest.php')
        ->and($hunk->fits("x();\na();\n"))->toBeTrue()
        ->and($hunk->fits("x();\n"))->toBeFalse()
        ->and($hunk->applyTo("x();\na();\n"))->toBe("x();\nb();\n")
        ->and($hunk->isAppliedTo("x();\nb();\n"))->toBeTrue()
        ->and($hunk->isAppliedTo("x();\na();\n"))->toBeFalse();
});

it('fits or finds its lines only where they are there once', function (): void {
    $hunk = Hunk::in('MutationTest.php', "a();\n", "b();\n");

    expect($hunk->fits("a();\na();\n"))->toBeFalse()
        ->and($hunk->isAppliedTo("b();\nb();\n"))->toBeFalse();
});
