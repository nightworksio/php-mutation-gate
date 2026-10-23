<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Listing;
use NightWorksIO\MutationGate\Tests\Support\PhpSpecExamples;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('listens for each example\'s start, and for the suite\'s end', function (): void {
    expect(Listing::getSubscribedEvents())->toBe(['beforeExample' => 'listed', 'afterSuite' => 'written']);
});

it('writes every example it saw, in order, and each holding group\'s examples, once the suite is done', function (): void {
    $file = sprintf('%s/listing.json', Scratch::directory());
    $listing = new Listing($file);
    $listing->listed(PhpSpecExamples::event('it_is_held_by_its_spec'));
    $listing->listed(PhpSpecExamples::event('it_is_held_by_its_own_path_too'));
    $unwritten = is_file($file);
    $listing->written();

    expect($unwritten)->toBeFalse()
        ->and(json_decode((string) file_get_contents($file), associative: true))->toBe([
            'listed' => [PhpSpecExamples::id('it_is_held_by_its_spec'), PhpSpecExamples::id('it_is_held_by_its_own_path_too')],
            'groups' => [
                'holds:src/Money.php' => [PhpSpecExamples::id('it_is_held_by_its_spec'), PhpSpecExamples::id('it_is_held_by_its_own_path_too')],
                'holds:src/Held.php' => [PhpSpecExamples::id('it_is_held_by_its_own_path_too')],
            ],
        ]);
});

it('writes an empty list where the suite held no example', function (): void {
    $file = sprintf('%s/listing.json', Scratch::directory());
    new Listing($file)->written();

    expect(json_decode((string) file_get_contents($file), associative: true))->toBe(['listed' => [], 'groups' => []]);
});
