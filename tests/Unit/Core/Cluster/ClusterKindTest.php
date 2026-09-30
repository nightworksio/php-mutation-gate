<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\ClusterKind;

it('names each rule as a report shows it', function (): void {
    expect(ClusterKind::Expression->label())->toBe('one expression')
        ->and(ClusterKind::Gap->label())->toBe('one gap')
        ->and(ClusterKind::Expression->value)->toBe('expression')
        ->and(ClusterKind::Gap->value)->toBe('gap');
});

it('says why one test may kill every member, more loosely for a gap', function (): void {
    expect(ClusterKind::Expression->text())->toBe('They change one expression, so one test that pins its result kills them all.')
        ->and(ClusterKind::Gap->text())->toBe('They change one function the same way and the same tests judge them, so one assertion may kill them all.');
});
