<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Comment\Detail;
use NightWorksIO\MutationGate\Core\Comment\Lines;
use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\CommentCaps;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Config\CommentToggles;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

it('lays a later layer over an earlier one key by key, and keeps what the later leaves out', function (): void {
    $earlier = CommentShows::of(
        Listed::of(Section::Verdict),
        Listed::of(Section::Trees),
        CommentCaps::capping(Cap::Survivors, 3),
        CommentToggles::off(Toggle::Try),
        Lines::All,
        Detail::Row,
    );
    $later = CommentShows::of(
        Listed::of(Section::Sets),
        Listed::of(Section::Sets),
        CommentCaps::capping(Cap::Ignored, 4),
        CommentToggles::off(Toggle::Reproduce),
        Lines::Changed,
        Detail::Full,
    );
    $kept = $earlier->over(CommentShows::of());
    $laid = $earlier->over($later);

    expect([...$kept->sections()])->toBe([Section::Verdict])
        ->and($kept->isOpen(Section::Trees))->toBeTrue()
        ->and($kept->isOpen(Section::Sets))->toBeFalse()
        ->and($kept->most(Cap::Survivors))->toBe(3)
        ->and($kept->allows(Toggle::Try))->toBeFalse()
        ->and($kept->lines())->toBe(Lines::All)
        ->and($kept->detail())->toBe(Detail::Row)
        ->and([...$laid->sections()])->toBe([Section::Sets])
        ->and($laid->isOpen(Section::Sets))->toBeTrue()
        ->and($laid->isOpen(Section::Trees))->toBeFalse()
        ->and($laid->most(Cap::Survivors))->toBe(3)
        ->and($laid->most(Cap::Ignored))->toBe(4)
        ->and($laid->allows(Toggle::Try))->toBeFalse()
        ->and($laid->allows(Toggle::Reproduce))->toBeFalse()
        ->and($laid->lines())->toBe(Lines::Changed)
        ->and($laid->detail())->toBe(Detail::Full);
});

it('writes each toggle of a comment\'s text and calls the builder for it, where a layer sets it', function (string $name): void {
    $toggle = Toggle::from($name);
    $set = CommentShows::of(toggles: CommentToggles::off($toggle));

    expect(Decoded::at($set->json()->line(), $toggle->value))->toBeFalse()
        ->and($set->php()->code())->toContain('Comment::');
})->with([
    'try' => ['try'],
    'reproduce' => ['reproduce'],
]);

it('writes none of the toggles of a run', function (string $name): void {
    $toggle = Toggle::from($name);
    expect(CommentShows::of(toggles: CommentToggles::off($toggle))->json()->line())->toBe('{}');
})->with([
    'drafts' => ['drafts'],
    'sampled' => ['sampled'],
    'cut short' => ['cutShort'],
]);

it('writes each cap where a layer sets it, and calls the builder for it', function (string $name): void {
    $cap = Cap::from($name);
    $set = CommentShows::of(caps: CommentCaps::capping($cap, 2));

    expect(Decoded::at($set->json()->line(), $cap->value, 'most'))->toBe(2)
        ->and($set->php()->code())->toContain('Comment::');
})->with(['survivors', 'security', 'unjudged', 'ignored', 'heatmap', 'text']);

it('shows the verdict, the trees and the heatmap open where no layer lists comment.open, and folds the rest', function (): void {
    $standard = CommentShows::of();

    expect(array_map($standard->isOpen(...), [Section::Verdict, Section::Trees, Section::Heatmap, Section::Survivors]))
        ->toBe([true, true, true, false]);
});

it('turns a toggle back on where a later layer sets it on', function (): void {
    $laid = CommentToggles::off(Toggle::Try)->over(CommentToggles::on(Toggle::Try));

    expect([$laid->allows(Toggle::Try), $laid->set(Toggle::Try)])->toBe([true, true]);
});
