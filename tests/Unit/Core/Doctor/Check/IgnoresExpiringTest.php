<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\IgnoresExpiring;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$ignoring = static fn(string ...$expires): Observations => Observations::none()->withSettings(Configs::settings([
    'runner' => 'pest',
    'ignores' => ['entries' => [
        ...array_map(
            static fn(string $day): array => ['path' => sprintf('src/%s.php', $day), 'mutator' => 'Plus', 'reason' => 'Equivalent', 'expires' => $day],
            $expires,
        ),
        ['path' => 'src/Forever.php', 'mutator' => 'Plus', 'reason' => 'Equivalent'],
    ]],
]));

it('lists each ignore that has expired or expires within 14 days, by its path and day', function () use ($ignoring): void {
    $observed = $ignoring('2026-09-29', '2026-09-30', '2026-10-14', '2026-10-15')->at(new DateTimeImmutable(Configs::NOW));

    expect(IgnoresExpiring::in($observed))->toEqual(Findings::of(Finding::of(
        Slug::IgnoresExpiring,
        Severity::Advice,
        'ignores.entries[0] expired on 2026-09-29; ignores.entries[1] expires on 2026-09-30; ignores.entries[2] expires on 2026-10-14.',
        'An ignore lasts only as long as its reason, and once it expires its mutants count again.',
        'Check each reason again: where it holds, move expires later; where a test now tells, remove the entry.',
    )));
});

it('finds nothing where every ignore lasts longer, or it does not know the day', function () use ($ignoring): void {
    expect(IgnoresExpiring::in($ignoring('2026-10-15')->at(new DateTimeImmutable(Configs::NOW))))->toEqual(Findings::none())
        ->and(IgnoresExpiring::in($ignoring('2026-09-29')))->toEqual(Findings::none())
        ->and(IgnoresExpiring::in(Observations::none()->at(new DateTimeImmutable(Configs::NOW))))->toEqual(Findings::none());
});
