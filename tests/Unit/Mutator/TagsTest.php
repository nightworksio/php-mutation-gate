<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;

it('holds each tag once, by its name', function (): void {
    $tags = Tags::of(Tag::security(), Tag::named('output'), Tag::named('security'));

    expect($tags)->toHaveCount(2)
        ->and(array_map(static fn(Tag $tag): string => $tag->name(), iterator_to_array($tags, preserve_keys: false)))->toBe(['security', 'output'])
        ->and($tags->has(Tag::named('security')))->toBeTrue()
        ->and($tags->has(Tag::named('secure')))->toBeFalse();
});

it('holds nothing when a mutator is about nothing in particular', function (): void {
    expect(Tags::none())->toHaveCount(0)
        ->and(Tags::none()->has(Tag::security()))->toBeFalse();
});

it('is equal to a tag of the same name', function (): void {
    expect(Tag::security()->equals(Tag::named('security')))->toBeTrue()
        ->and(Tag::security()->equals(Tag::named('output')))->toBeFalse();
});
