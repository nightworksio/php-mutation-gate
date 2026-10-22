<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Draw;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$candidates = static fn(): array => array_map(
    static fn(string $path): Candidate => Candidate::of(Unit::file(Path::of($path)), 10, NotGiven::value()),
    ['src/A.php', 'src/B.php', 'src/C.php', 'src/D.php'],
);

$paths = static fn(Candidate ...$ranked): array => array_map(
    static fn(Candidate $candidate): string => $candidate->unit()->path()->value(),
    $ranked,
);

it('ranks the units by the SHA-256 of the seed followed by each path', function () use ($candidates, $paths): void {
    expect($paths(...Draw::seeded('abc')->ranked(...$candidates())))->toBe(['src/A.php', 'src/C.php', 'src/D.php', 'src/B.php'])
        ->and($paths(...Draw::seeded('xyz')->ranked(...$candidates())))->toBe(['src/A.php', 'src/D.php', 'src/C.php', 'src/B.php']);
});

it('ranks the same units alike whatever order they come in', function () use ($candidates, $paths): void {
    expect($paths(...Draw::seeded('abc')->ranked(...array_reverse($candidates()))))
        ->toBe($paths(...Draw::seeded('abc')->ranked(...$candidates())));
});
