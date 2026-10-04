<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\NativeMarkers as InfectionMarkers;
use NightWorksIO\MutationGate\Adapter\Pest\NativeMarkers as PestMarkers;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\SourceMarkers;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Tests\Support\Tree;

/**
 * Each marker found in a source as its place and the function it speaks for, `-` for none.
 *
 * @return list<string>
 */
function sourceMarkers(string ...$lines): array
{
    return array_map(
        static fn(Marker $marker): string => sprintf(
            '%s %s',
            $marker->where(),
            $marker->enclosing() instanceof Enclosing ? $marker->enclosing()->function() : '-',
        ),
        [...SourceMarkers::in(Path::of('src/Money.php'), Contents::of(implode("\n", $lines)), '@mark')],
    );
}

it('finds a marker in a comment on the line it is on, in the function it is in', function (): void {
    expect(sourceMarkers(
        '<?php',
        '// @mark',
        'final class Money',
        '{',
        '    public function add(int $a): int',
        '    {',
        '        return $a + 1; // @mark',
        '    }',
        '    public function name(): string { return "@mark"; }',
        '    /* one',
        '       @mark */',
        '}',
    ))->toBe(['src/Money.php:2 -', 'src/Money.php:7 add', 'src/Money.php:11 -']);
});

it('speaks for the function a doc comment documents, and for none where it documents a class', function (): void {
    expect(sourceMarkers(
        '<?php',
        '/** @mark */',
        'final class Money',
        '{',
        '    /**',
        '     * Adds.',
        '     *',
        '     * @mark',
        '     */',
        '    #[Pure]',
        '    public function add(int $a): int',
        '    {',
        '        /** @mark */',
        '        $b = 1;',
        '        return $a + $b;',
        '    }',
        '}',
    ))->toBe(['src/Money.php:2 -', 'src/Money.php:8 add', 'src/Money.php:13 add']);
});

it('speaks for no function past the end of what its doc comment documents', function (): void {
    expect(sourceMarkers(
        '<?php',
        'final class Money',
        '{',
        '    /** @mark */',
        '    public int $cents = 0;',
        '    public function add(): void',
        '    {',
        '        /** @mark */',
        '    }',
        '    public function name(): void {}',
        '}',
    ))->toBe(['src/Money.php:4 -', 'src/Money.php:8 add']);
});

it('finds nothing in a source with no marker', function (): void {
    expect(sourceMarkers('<?php', 'echo "@mark";'))->toBe([]);
});

it('finds no runner\'s marker in the package\'s own source, which a run of the gate over itself would refuse', function (): void {
    $found = [];

    foreach (Tree::filesUnder('src') as $file) {
        foreach ([PestMarkers::MARKER, InfectionMarkers::IN_SOURCE] as $marker) {
            $markers = SourceMarkers::in(Path::of($file), Contents::of((string) file_get_contents(Tree::at($file))), $marker);
            $found = [...$found, ...array_map(static fn(Marker $each): string => $each->where(), [...$markers])];
        }
    }

    expect($found)->toBe([]);
});
