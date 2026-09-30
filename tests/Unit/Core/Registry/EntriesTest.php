<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Registry\Entries;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;

it('finds an entry by its name', function (): void {
    $entries = new Entries(ExtensionPoint::Reporter)->with('sarif', 'nightworksio/mutation-gate', Name::of('the sarif reporter'));

    expect($entries->find('sarif'))->toEqual(Name::of('the sarif reporter'));
});

it('cannot judge with a name nothing registered', function (): void {
    expect(new Entries(ExtensionPoint::Reporter)->find('slack'))->toEqual(CannotJudge::because('No reporter is registered as "slack".'));
});

it('lets a package register a name again, replacing its own entry', function (): void {
    $entries = new Entries(ExtensionPoint::Reporter)->with('sarif', 'acme/a', Name::of('first'))->with('sarif', 'acme/a', Name::of('second'));

    expect($entries->find('sarif'))->toEqual(Name::of('second'));
});

it('leaves the entries it came from as they were', function (): void {
    $entries = new Entries(ExtensionPoint::Reporter);
    $entries->with('sarif', 'acme/a', Name::of('first'));

    expect($entries->find('sarif'))->toBeInstanceOf(CannotJudge::class);
});

it('names each name both register, whichever package each says it comes from', function (): void {
    $ours = new Entries(ExtensionPoint::Reporter)->with('sarif', 'acme/a', Name::of('ours'))->with('json', 'acme/a', Name::of('ours'))->with('html', 'acme/a', Name::of('ours'));
    $theirs = new Entries(ExtensionPoint::Reporter)->with('json', 'acme/b', Name::of('theirs'))->with('html', 'acme/a', Name::of('theirs'))->with('junit', 'acme/b', Name::of('theirs'))->with('sarif', 'acme/c', Name::of('theirs'));

    expect($ours->conflictsWith($theirs))->toBe([
        'Two packages register a reporter named "json": acme/a and acme/b.',
        'Two packages register a reporter named "html": acme/a and acme/a.',
        'Two packages register a reporter named "sarif": acme/a and acme/c.',
    ])->and($ours->conflictsWith(new Entries(ExtensionPoint::Reporter)))->toBe([]);
});

it('merges another\'s entries into its own, the other\'s winning a shared name', function (): void {
    $merged = new Entries(ExtensionPoint::Reporter)->with('sarif', 'acme/a', Name::of('ours'))->with('json', 'acme/a', Name::of('ours'))
        ->merge(new Entries(ExtensionPoint::Reporter)->with('json', 'acme/a', Name::of('theirs'))->with('junit', 'acme/b', Name::of('theirs')));

    expect($merged->find('sarif'))->toEqual(Name::of('ours'))
        ->and($merged->find('json'))->toEqual(Name::of('theirs'))
        ->and($merged->find('junit'))->toEqual(Name::of('theirs'));
});
