<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\ForkedChild;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The first numbers each of two children draws from `mt_rand()`, both forked
 * from one parent that seeded its generator, reseeding first where asked.
 *
 * @return list<string>
 */
function forkedDraws(bool $reseeded): array
{
    $directory = Scratch::directory();
    $script = sprintf('%s/forking.php', $directory);
    file_put_contents($script, sprintf(
        "<?php\nrequire %s;\nmt_srand(7);\nmt_rand();\n"
        . "foreach ([1, 2] as \$child) {\n"
        . "    if (pcntl_fork() === 0) {\n"
        . "        if (%s) {\n            %s::reseeded();\n        }\n"
        . "        file_put_contents(\$argv[1] . '/' . \$child, implode(',', [mt_rand(), mt_rand(), mt_rand()]));\n"
        . "        exit(0);\n    }\n}\n"
        . "while (pcntl_wait(\$status) > 0) {\n}\n",
        var_export(Tree::at('vendor/autoload.php'), return: true),
        var_export($reseeded, return: true),
        ForkedChild::class,
    ));
    exec(sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($script), escapeshellarg($directory)));

    return [
        (string) file_get_contents(sprintf('%s/1', $directory)),
        (string) file_get_contents(sprintf('%s/2', $directory)),
    ];
}

it('gives each child forked from one parent a sequence of its own, where unseeded children replay the parent\'s', function (): void {
    $copied = forkedDraws(reseeded: false);
    $reseeded = forkedDraws(reseeded: true);

    expect($copied[0])->toBe($copied[1])
        ->and($reseeded[0])->not->toBe($reseeded[1])
        ->and($reseeded[0])->not->toBe($copied[0]);
})->skip(fn(): bool => ! function_exists('pcntl_fork'), 'pcntl is not loaded')->group('holds:src/Adapter/Runtime/ForkedChild.php');
