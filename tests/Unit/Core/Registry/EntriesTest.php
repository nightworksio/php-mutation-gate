<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Registry\Entries;
use NightWorksIO\MutationGate\Core\Registry\Entry;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;

/**
 * @template T of object
 *
 * @param  T        $value
 * @return Entry<T>
 */
function registeredEntry(string $name, string $origin, object $value): Entry
{
    return Entry::of(Name::of($name), Origin::of($origin), $value);
}

it('finds an entry by its name', function (): void {
    $entries = Entries::none(ExtensionPoint::Reporter)->with(registeredEntry('sarif', 'nightworksio/mutation-gate', Name::of('the sarif reporter')));

    expect($entries->find(Name::of('sarif')))->toEqual(Name::of('the sarif reporter'));
});

it('cannot judge with a name nothing registered', function (): void {
    expect(Entries::none(ExtensionPoint::Reporter)->find(Name::of('slack')))->toEqual(CannotJudge::because('No reporter is registered as "slack".'));
});

it('suggests the registered name a missing one was most likely meant to be', function (): void {
    $entries = Entries::none(ExtensionPoint::Reporter)->with(registeredEntry('sarif', 'acme/a', Name::of('the sarif reporter')));

    expect($entries->find(Name::of('sarf')))->toEqual(CannotJudge::because('No reporter is registered as "sarf". Did you mean "sarif"?'))
        ->and($entries->find(Name::of('slack')))->toEqual(CannotJudge::because('No reporter is registered as "slack".'));
});

it('suggests a registered name that is a number as the name it is', function (): void {
    $entries = Entries::none(ExtensionPoint::Reporter)->with(registeredEntry('42', 'acme/a', Name::of('the answer')));

    expect($entries->find(Name::of('43')))->toEqual(CannotJudge::because('No reporter is registered as "43". Did you mean "42"?'));
});

it('keeps each entry\'s name, package and value', function (): void {
    $entry = registeredEntry('sarif', 'acme/a', Name::of('the sarif reporter'));

    expect($entry->name())->toEqual(Name::of('sarif'))
        ->and($entry->origin())->toEqual(Origin::of('acme/a'))
        ->and($entry->value())->toEqual(Name::of('the sarif reporter'));
});

it('lets a package register a name again, replacing its own entry', function (): void {
    $entries = Entries::none(ExtensionPoint::Reporter)->with(registeredEntry('sarif', 'acme/a', Name::of('first')))->with(registeredEntry('sarif', 'acme/a', Name::of('second')));

    expect($entries->find(Name::of('sarif')))->toEqual(Name::of('second'));
});

it('leaves the entries it came from as they were', function (): void {
    $entries = Entries::none(ExtensionPoint::Reporter);
    $entries->with(registeredEntry('sarif', 'acme/a', Name::of('first')));

    expect($entries->find(Name::of('sarif')))->toBeInstanceOf(CannotJudge::class);
});

it('names each name both register, whichever package each says it comes from', function (): void {
    $ours = Entries::none(ExtensionPoint::Reporter)->with(registeredEntry('sarif', 'acme/a', Name::of('ours')))->with(registeredEntry('json', 'acme/a', Name::of('ours')))->with(registeredEntry('html', 'acme/a', Name::of('ours')));
    $theirs = Entries::none(ExtensionPoint::Reporter)->with(registeredEntry('json', 'acme/b', Name::of('theirs')))->with(registeredEntry('html', 'acme/a', Name::of('theirs')))->with(registeredEntry('junit', 'acme/b', Name::of('theirs')))->with(registeredEntry('sarif', 'acme/c', Name::of('theirs')));

    expect($ours->conflictsWith($theirs))->toBe([
        'Two packages register a reporter named "json": acme/a and acme/b.',
        'Two packages register a reporter named "html": acme/a and acme/a.',
        'Two packages register a reporter named "sarif": acme/a and acme/c.',
    ])->and($ours->conflictsWith(Entries::none(ExtensionPoint::Reporter)))->toBe([]);
});

it('merges another\'s entries into its own, the other\'s winning a shared name', function (): void {
    $merged = Entries::none(ExtensionPoint::Reporter)->with(registeredEntry('sarif', 'acme/a', Name::of('ours')))->with(registeredEntry('json', 'acme/a', Name::of('ours')))
        ->merge(Entries::none(ExtensionPoint::Reporter)->with(registeredEntry('json', 'acme/a', Name::of('theirs')))->with(registeredEntry('junit', 'acme/b', Name::of('theirs'))));

    expect($merged->find(Name::of('sarif')))->toEqual(Name::of('ours'))
        ->and($merged->find(Name::of('json')))->toEqual(Name::of('theirs'))
        ->and($merged->find(Name::of('junit')))->toEqual(Name::of('theirs'));
});

it('names each entry in the order it was registered, once', function (): void {
    $entries = Entries::none(ExtensionPoint::CiPlan)
        ->with(registeredEntry('gitlab', 'nightworksio/mutation-gate', Name::of('GitLab')))
        ->with(registeredEntry('acme', 'acme/gate', Name::of('Acme')))
        ->with(registeredEntry('gitlab', 'nightworksio/mutation-gate', Name::of('GitLab again')));

    expect($entries->names())->toEqual(Listed::of(Name::of('gitlab'), Name::of('acme')))
        ->and(Entries::none(ExtensionPoint::CiPlan)->names())->toEqual(Listed::of());
});
