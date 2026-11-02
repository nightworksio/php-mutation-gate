<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Api;

// D13: the boundary of what semver covers, marked in the code. Every type the
// package ships outside the API surface says `@internal`, so an extension
// author's analyser reports a use of it, and no type on the surface does
// (ADR-0043). The surface is computed, never listed: the public layers, and
// every core type their public faces reach, natively or in a doc block.

/** Whether a doc block carries the `@internal` tag. */
function saysInternal(string|false $docBlock): bool
{
    return is_string($docBlock) && preg_match('/^\s*(?:\/\*\*|\*)\s*@internal\b/m', $docBlock) === 1;
}

it('marks every type outside the public API internal, and none on it', function (): void {
    $public = array_map(static fn(ReflectionClass $class): string => $class->getName(), Api::surface());
    $offenders = [];

    foreach (Api::shippedClasses() as $class) {
        $isPublic = in_array($class->getName(), $public, strict: true);
        $isMarked = saysInternal($class->getDocComment());

        if ($isPublic === $isMarked) {
            $offenders[] = sprintf('%s %s', $class->getName(), $isPublic ? 'is on the public API and says @internal' : 'is not on the public API and does not say @internal');
        }
    }

    // D13
    expect($offenders)->toBe([], sprintf(
        "These types are marked against the boundary of what semver covers:\n  %s\n\nA type no public signature reaches is internal, and its doc block says `@internal`; a type a public signature reaches is part of the promise, and says nothing of the kind (D13).",
        implode("\n  ", $offenders),
    ));
});

it('reads the internal tag on a doc block of one line and of many, and not in prose', function (): void {
    expect(saysInternal('/** @internal */'))->toBeTrue()
        ->and(saysInternal("/**\n * A value.\n *\n * @internal the ledger's own\n */"))->toBeTrue()
        ->and(saysInternal("/**\n * Never @internal in prose.\n */"))->toBeFalse()
        ->and(saysInternal(docBlock: false))->toBeFalse();
});
