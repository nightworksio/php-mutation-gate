<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\SettingPaths;

/**
 * Whether some of these settings is under a path, as Canonical read it by
 * scanning every setting: the reference SettingPaths must agree with.
 *
 * @param list<string> $settings
 */
function scannedUnder(array $settings, string $path): bool
{
    return array_any(
        $settings,
        static fn(string $setting): bool => $path === ''
            || str_starts_with($setting, sprintf('%s.', $path))
            || str_starts_with($setting, sprintf('%s[]', $path)),
    );
}

/**
 * Every path the walk over a config can ask about these settings: the top,
 * every string prefix of each, each whole, and one step past each.
 *
 * @param  list<string> $settings
 * @return list<string>
 */
function askedOf(array $settings): array
{
    $asked = [''];

    foreach ($settings as $setting) {
        for ($length = 1; $length <= mb_strlen($setting); $length++) {
            $asked[] = mb_substr($setting, 0, $length);
        }

        $asked[] = sprintf('%s.x', $setting);
        $asked[] = sprintf('%s[]', $setting);
    }

    return array_values(array_unique($asked));
}

it('is under a path where some setting is, as a scan of every setting the shipped schema declares says', function (Closure $kept): void {
    $settings = array_map(strval(...), array_keys(array_filter(Definition::effects(), $kept)));
    $paths = SettingPaths::of(...$settings);

    $disagreed = array_values(array_filter(
        askedOf(array_map(strval(...), array_keys(Definition::effects()))),
        static fn(string $path): bool => $paths->under($path) !== scannedUnder($settings, $path),
    ));

    expect($settings)->not->toBe([])
        ->and($disagreed)->toBe([]);
})->with([
    'every setting' => [static fn(): bool => true],
    'the settings that affect results' => [static fn(Effect $effect): bool => $effect->isKeyed()],
    'the settings that decide how the gate runs' => [static fn(Effect $effect): bool => $effect->decides()],
]);

it('is under each path before a step into a member or a list\'s entries, and not under a path that only begins a name', function (): void {
    $paths = SettingPaths::of('trees[].path', 'run.failFast');

    expect(array_map($paths->under(...), ['', 'trees', 'trees[]', 'run']))->toBe([true, true, true, true])
        ->and(array_map($paths->under(...), ['trees[].path', 'tree', 'trees[', 'run.fail', 'run.failFast', 'ru']))
        ->toBe([false, false, false, false, false, false])
        ->and(SettingPaths::of()->under(''))->toBeFalse();
});
