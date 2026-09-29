<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Tree;

// R3: the analyser, the refactorer and the Arch suite read the same trees. The
// Arch suite reads src, tests and phpstan; a tree one of the other two leaves
// out is a tree whose rules nothing enforces.

/** The trees every one of them reads. */
const THE_TREES = ['phpstan', 'src', 'tests'];

/**
 * The trees a configuration file names, in the shape it names them.
 *
 * @return list<string>
 */
function treesNamedIn(string $file, string $pattern): array
{
    preg_match_all($pattern, (string) file_get_contents(Tree::at($file)), $found);
    $trees = $found[1];
    sort($trees);

    return $trees;
}

it('reads the same trees with the analyser, the refactorer and the Arch suite', function (): void {
    $analysed = treesNamedIn('phpstan.neon', '/^        - ([a-z]+)$/mu');
    $refactored = treesNamedIn('rector.php', "/__DIR__ \\. '\\/([a-z]+)',/u");

    // R3
    expect($analysed)->toBe(THE_TREES, 'phpstan.neon does not analyse the trees the Arch suite reads (R3)')
        ->and($refactored)->toBe(THE_TREES, 'rector.php does not refactor the trees the Arch suite reads (R3)');
});
