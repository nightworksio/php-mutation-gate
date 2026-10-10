<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Turbo\Sample;

$answered = static fn(int $count): array => array_map(
    static fn(int $at): Path => Path::of(sprintf('tests/T%dTest.php', $at)),
    range(1, $count),
);

it('samples fifty at least, every one of fewer, and a fiftieth of many', function () use ($answered): void {
    expect(count(Sample::of('b', $answered(10))))->toBe(10)
        ->and(count(Sample::of('b', $answered(120))))->toBe(50)
        ->and(count(Sample::of('b', $answered(5000))))->toBe(100)
        ->and(count(Sample::of('b', [])))->toBe(0);
});

it('samples the same for the same base, and others for another', function () use ($answered): void {
    expect(Sample::of('b', $answered(120)))->toEqual(Sample::of('b', $answered(120)))
        ->and(Sample::of('b', $answered(120)))->not->toEqual(Sample::of('c', $answered(120)));
});

it('samples the paths whose digest beside the base comes first', function (): void {
    $paths = array_map(static fn(int $at): Path => Path::of(sprintf('p%d', $at)), range(1, 60));
    $ranked = $paths;
    usort($ranked, static fn(Path $a, Path $b): int => hash('sha256', sprintf("1:b\n%d:%s\n", strlen($a->value()), $a->value()))
        <=> hash('sha256', sprintf("1:b\n%d:%s\n", strlen($b->value()), $b->value())));

    expect([...Sample::of('b', $paths)])->toEqualCanonicalizing(array_slice($ranked, 0, 50))
        ->and(Sample::of('b', $paths))->toBeInstanceOf(Paths::class);
});
