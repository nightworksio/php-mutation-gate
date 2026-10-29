<?php

declare(strict_types=1);

use Library\Lettering;
use Library\Marked;
use Library\Screen;

it('letters a short title large', function (): void {
    expect(Lettering::for(5, 3))->toBe(Lettering::Large);
});

it('letters a long title small', function (): void {
    expect(Lettering::for(19, 3))->toBe(Lettering::Small);
});

it('makes a screen closed', function (): void {
    expect(new ReflectionClass(Screen::class)->newInstance('Home')->open)->toBeFalse();
});

it('marks a screen\'s title', function (): void {
    $marks = new ReflectionProperty(Screen::class, 'title')->getAttributes(Marked::class);

    expect($marks[0]->newInstance())->toBeInstanceOf(Marked::class);
});
