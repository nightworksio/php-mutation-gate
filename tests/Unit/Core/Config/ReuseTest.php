<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\IdenticalReuse;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\OwnScopeTrust;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Reuse;

it('reuses an identical verdict everywhere by default, reading nothing of a fork, reaching own-scope proofs, with no delta, no variable and ten passes kept', function (): void {
    $reuse = Layer::standard()->running()->ci()->reuse();

    expect([$reuse->identical(), $reuse->forks(), $reuse->ownScopeProofs(), $reuse->delta(), [...$reuse->environment()], $reuse->passes()])
        ->toBe([IdenticalReuse::On, false, OwnScopeTrust::Reach, false, [], 10]);
});

it('reuses on pull requests under on and pulls, and on the default branch under on alone', function (): void {
    expect([
        IdenticalReuse::On->reusesOn(defaultBranch: true),
        IdenticalReuse::On->reusesOn(defaultBranch: false),
        IdenticalReuse::Pulls->reusesOn(defaultBranch: true),
        IdenticalReuse::Pulls->reusesOn(defaultBranch: false),
        IdenticalReuse::Off->reusesOn(defaultBranch: true),
        IdenticalReuse::Off->reusesOn(defaultBranch: false),
    ])->toBe([true, true, false, true, false, false]);
});

it('lays each reuse key a later layer sets over an earlier one\'s, and joins their variables', function (): void {
    $earlier = Layer::of(Ci::of(reuse: Reuse::of(IdenticalReuse::Pulls, forks: true, environment: Listed::of('A', 'B'), passes: 3)));
    $later = Layer::of(Ci::of(reuse: Reuse::of(ownScopeProofs: OwnScopeTrust::Trust, delta: true, environment: Listed::of('B', 'C'))));
    $reuse = $earlier->over($later)->running()->ci()->reuse();
    $laid = $later->over(Layer::of(Ci::of(reuse: Reuse::of(passes: 7))))->running()->ci()->reuse();

    expect([$reuse->identical(), $reuse->forks(), $reuse->ownScopeProofs(), $reuse->delta(), [...$reuse->environment()], $reuse->passes()])
        ->toBe([IdenticalReuse::Pulls, true, OwnScopeTrust::Trust, true, ['A', 'B', 'C'], 3])
        ->and($laid->passes())->toBe(7);
});

it('writes only the reuse keys a layer sets, and the builder calls for them', function (): void {
    $layer = Layer::of(Ci::of(reuse: Reuse::of(
        IdenticalReuse::Off,
        forks: false,
        ownScopeProofs: OwnScopeTrust::Refuse,
        delta: true,
        environment: Listed::of('APP_ENV'),
        passes: 4,
    )));
    $code = $layer->php(ProjectRoot::origin())->code();

    expect($layer->written(ProjectRoot::origin())->line())
        ->toBe('{"reuse":{"identical":"off","forks":false,"ownScopeProofs":"refuse","delta":true,"environment":["APP_ENV"],"passes":4}}')
        ->and($code)->toContain('Reuse::neverIdentical()')
        ->and($code)->toContain('Reuse::notFromForks()')
        ->and($code)->toContain('Reuse::refusingOwnScopeProofs()')
        ->and($code)->toContain('Reuse::delta()')
        ->and($code)->toContain("Reuse::environment('APP_ENV')")
        ->and($code)->toContain('Reuse::passes(4)')
        ->and(Layer::of(Ci::of(reuse: Reuse::of()))->written(ProjectRoot::origin())->line())->toBe('{}')
        ->and(Layer::of(Ci::of(reuse: Reuse::of()))->php(ProjectRoot::origin())->code())->not->toContain('Reuse::');
});

it('writes the builder call of each identical reuse and own-scope trust', function (): void {
    expect(array_map(static fn(IdenticalReuse $reuse): string => $reuse->php(), IdenticalReuse::cases()))
        ->toBe(['Reuse::identical()', 'Reuse::identicalOnPullRequests()', 'Reuse::neverIdentical()'])
        ->and(array_map(static fn(OwnScopeTrust $trust): string => $trust->php(), OwnScopeTrust::cases()))
        ->toBe(['Reuse::reachingOwnScopeProofs()', 'Reuse::refusingOwnScopeProofs()', 'Reuse::trustingOwnScopeProofs()']);
});
