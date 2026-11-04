<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\PathGroup;
use NightWorksIO\MutationGate\Cli\Flow\PathGroups;
use NightWorksIO\MutationGate\Core\Config\PathRules;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** A shard of these files' units. */
$shard = static fn(string ...$files): Shard => Shard::of(
    ShardId::of(3),
    Package::at(Path::root()),
    Units::of(...array_map(static fn(string $file): Unit => Unit::file(Path::of($file)), $files)),
    Seconds::of(9.0),
    'everything',
);

/** The `paths` entries a config writes. */
$rules = static fn(array $paths): PathRules => Configs::valid(['paths' => $paths])->setup()->paths();

/** Each group's files, and whether no entry matches it. */
$shown = static fn(PathGroups $groups): array => array_map(
    static fn(PathGroup $group): array => [
        $group->rules()->isNone(),
        array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$group->shard()->units()]),
    ],
    [...$groups],
);

it('groups a shard\'s units by what the entries matching each set, those none matches first', function () use ($shard, $rules, $shown): void {
    $groups = PathGroups::of(
        $shard('src/Legacy/A.php', 'src/Money.php', 'src/Gen/B.php', 'src/Legacy/C.php', 'src/Clock.php'),
        $rules([
            ['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60]],
            ['glob' => 'src/Gen/**', 'timeouts' => ['seconds' => 90]],
        ]),
    );

    expect($shown($groups))->toBe([
        [true, ['src/Money.php', 'src/Clock.php']],
        [false, ['src/Legacy/A.php', 'src/Legacy/C.php']],
        [false, ['src/Gen/B.php']],
    ])
        ->and($groups->isWhole())->toBeFalse()
        ->and(array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$groups->unitsFrom(1)]))
        ->toBe(['src/Legacy/A.php', 'src/Legacy/C.php', 'src/Gen/B.php'])
        ->and([...$groups][1]->shard()->id())->toEqual(ShardId::of(3))
        ->and([...$groups][1]->shard()->label())->toBe('everything');
});

it('is whole where no entry matches a unit, or the shard holds none, and is not where every unit is matched', function () use ($shard, $rules, $shown): void {
    $legacy = $rules([['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60]]]);

    expect(PathGroups::of($shard('src/Money.php'), $legacy)->isWhole())->toBeTrue()
        ->and(PathGroups::of($shard(), $legacy)->isWhole())->toBeTrue()
        ->and(PathGroups::of($shard('src/Legacy/A.php'), $legacy)->isWhole())->toBeFalse()
        ->and($shown(PathGroups::of($shard('src/Legacy/A.php'), $legacy)))->toBe([[false, ['src/Legacy/A.php']]]);
});
