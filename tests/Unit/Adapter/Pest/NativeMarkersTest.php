<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\NativeMarkers;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('finds @pest-mutate-ignore in a comment of the PHP files the paths name, on its own line', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', implode("\n", [
        '<?php',
        'final class Money',
        '{',
        '    /**',
        '     * @pest-mutate-ignore: PlusToMinus',
        '     */',
        '    public function add(): int { return 1 + 1; } // @pest-mutate-ignore',
        '    public function name(): string { return "@pest-mutate-ignore"; }',
        '}',
    ]));
    Scratch::write($root, 'src/Deeper/Held.php', "<?php\n// @pest-mutate-ignore\n");
    Scratch::write($root, 'src/notes.txt', '// @pest-mutate-ignore');
    Scratch::write($root, 'lib/Tax.php', "<?php\n// @pest-mutate-ignore\n");
    $project = Project::at($root, Paths::none(), Path::of('.gate'), Path::of('vendor'));
    $markers = NativeMarkers::in($project, Paths::of(Path::of('src'), Path::of('nowhere')));
    $replaces = '{"mutant": "<the id of each mutant it hides>", "reason": "<why no test can tell>"}';

    expect(array_map(
        static fn(Marker $marker): array => [$marker->where(), $marker->marker(), $marker->replacement()],
        iterator_to_array($markers, preserve_keys: false),
    ))->toBe([
        ['src/Deeper/Held.php:2', '@pest-mutate-ignore', $replaces],
        ['src/Money.php:5', '@pest-mutate-ignore', $replaces],
        ['src/Money.php:7', '@pest-mutate-ignore', $replaces],
    ]);
});

it('does not walk into a linked directory, which may lead back up into a loop', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/Held.php', "<?php\n// @pest-mutate-ignore\n");
    symlink('..', sprintf('%s/src/loop', $root));
    $project = Project::at($root, Paths::none(), Path::of('.gate'), Path::of('vendor'));

    expect(iterator_to_array(NativeMarkers::in($project, Paths::of(Path::of('src'))), preserve_keys: false))->toHaveCount(1);
});
