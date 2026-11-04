<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// symfony/yaml is suggested, not required, so only the package's conflict
// keeps a user's project off the releases an advisory names. The package
// locks the release it was last checked against, and the conflict refuses
// every release of that major before it, so the two cannot drift apart.

/** The package symfony/yaml is held to by conflict alone. */
const YAML = 'symfony/yaml';

/** The release a lock holds of a package, without its leading v. */
function lockedRelease(string $lock, string $package): string
{
    $json = (string) file_get_contents(Tree::at($lock));
    $names = [...Decoded::column($json, 'name', 'packages'), ...Decoded::column($json, 'name', 'packages-dev')];
    $versions = [...Decoded::column($json, 'version', 'packages'), ...Decoded::column($json, 'version', 'packages-dev')];
    $at = array_search($package, $names, strict: true);
    $version = $at === false ? '' : $versions[$at];

    return is_string($version) ? ltrim($version, 'v') : '';
}

/** The upper end of the clause of a conflict that names a major, as `>=8.0 <8.1.11` gives `8.1.11` for 8. */
function conflictFloorFor(string $conflict, int $major): string
{
    foreach (explode('||', $conflict) as $clause) {
        if (preg_match(sprintf('/^\s*>=%d\.\d+\s+<(\S+)\s*$/', $major), $clause, $parts) === 1) {
            return $parts[1];
        }
    }

    return '';
}

it('refuses every release of symfony/yaml the package locks a later one of, in the locked major', function (): void {
    $locked = lockedRelease('composer.lock', YAML);
    $conflict = Decoded::at((string) file_get_contents(Tree::at('composer.json')), 'conflict', YAML);
    $floor = conflictFloorFor(is_string($conflict) ? $conflict : '', (int) explode('.', $locked)[0]);

    expect($locked)->not->toBe('')
        ->and($floor)->not->toBe('')
        ->and(version_compare($floor, $locked, '>='))->toBeTrue();
});
