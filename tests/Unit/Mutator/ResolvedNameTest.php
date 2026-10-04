<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\ResolvedName;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;

it('reads the full name a class name resolves to, or the name as written where none was resolved', function (): void {
    $aliased = new Name('Gate', [ResolvedName::ATTRIBUTE => new FullyQualified('Illuminate\Support\Facades\Gate')]);

    expect(ResolvedName::of($aliased))->toBe('Illuminate\Support\Facades\Gate')
        ->and(ResolvedName::of(new FullyQualified('Acme\Gate')))->toBe('Acme\Gate')
        ->and(ResolvedName::of(new Name('Gate')))->toBe('Gate');
});
