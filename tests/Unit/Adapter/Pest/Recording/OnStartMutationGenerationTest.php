<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnStartMutationGeneration;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGeneration;
use Pest\Mutate\MutationSuite;

afterEach(function (): void {
    Scratch::sweep();
});

it('keeps the opening run\'s map as Pest starts making mutants', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'coverage.php', '<?php return [];');
    $recorder = Mutations::recorder(sprintf('%s/results.jsonl', $root), sprintf('%s/coverage.php', $root));

    new OnStartMutationGeneration($recorder)->notify(new StartMutationGeneration(new MutationSuite()));

    expect(file_get_contents(sprintf('%s/results.jsonl.coverage.php', $root)))->toBe('<?php return [];');
});
