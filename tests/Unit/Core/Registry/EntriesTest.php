<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Registry\Entries;

it('finds an entry by its name', function (): void {
    $entries = new Entries('reporter')->with('sarif', 'nightworksio/mutation-gate', 'the sarif reporter');

    expect($entries->find('sarif'))->toBe('the sarif reporter');
});

it('cannot judge with a name nothing registered', function (): void {
    expect(new Entries('reporter')->find('slack'))->toEqual(CannotJudge::because('No reporter is registered as "slack".'));
});

it('lets a package register a name again, replacing its own entry', function (): void {
    $entries = new Entries('reporter')->with('sarif', 'acme/a', 'first')->with('sarif', 'acme/a', 'second');

    expect($entries->find('sarif'))->toBe('second');
});

it('leaves the entries it came from as they were', function (): void {
    $entries = new Entries('reporter');
    $entries->with('sarif', 'acme/a', 'first');

    expect($entries->find('sarif'))->toBeInstanceOf(CannotJudge::class);
});

it('names each name two packages both register', function (): void {
    $ours = new Entries('reporter')->with('sarif', 'acme/a', 'ours')->with('json', 'acme/a', 'ours')->with('html', 'acme/a', 'ours');
    $theirs = new Entries('reporter')->with('json', 'acme/b', 'theirs')->with('html', 'acme/a', 'theirs')->with('junit', 'acme/b', 'theirs')->with('sarif', 'acme/c', 'theirs');

    expect($ours->conflictsWith($theirs))->toBe([
        'Two packages register a reporter named "json": acme/a and acme/b.',
        'Two packages register a reporter named "sarif": acme/a and acme/c.',
    ])->and($ours->conflictsWith(new Entries('reporter')))->toBe([]);
});

it('merges another\'s entries into its own, the other\'s winning a shared name', function (): void {
    $merged = new Entries('reporter')->with('sarif', 'acme/a', 'ours')->with('json', 'acme/a', 'ours')
        ->merge(new Entries('reporter')->with('json', 'acme/a', 'theirs')->with('junit', 'acme/b', 'theirs'));

    expect($merged->find('sarif'))->toBe('ours')
        ->and($merged->find('json'))->toBe('theirs')
        ->and($merged->find('junit'))->toBe('theirs');
});
