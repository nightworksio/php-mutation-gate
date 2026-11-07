<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\CommentToggles;
use NightWorksIO\MutationGate\Core\Config\CommentWrites;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

it('lays a later layer over an earlier one key by key, and keeps what the later leaves out', function (): void {
    $earlier = CommentWrites::of(Mode::New, Listed::of(State::Planned), Passing::Never, Listed::of(Occasion::DefaultBranch));
    $later = CommentWrites::of(Mode::NewHidingOld, Listed::of(State::Verdict), Passing::Always, Listed::of(Occasion::PullRequest));
    $kept = $earlier->over(CommentWrites::of());
    $laid = $earlier->over($later);
    $standard = [Occasion::PullRequest];

    expect($kept->mode())->toBe(Mode::New)
        ->and($kept->writes(State::Planned))->toBeTrue()
        ->and($kept->writes(State::Verdict))->toBeFalse()
        ->and($kept->passing())->toBe(Passing::Never)
        ->and($kept->commentsOn(Occasion::DefaultBranch, Listed::of(...$standard)))->toBeTrue()
        ->and($laid->mode())->toBe(Mode::NewHidingOld)
        ->and($laid->writes(State::Verdict))->toBeTrue()
        ->and($laid->writes(State::Planned))->toBeFalse()
        ->and($laid->passing())->toBe(Passing::Always)
        ->and($laid->commentsOn(Occasion::DefaultBranch, Listed::of(...$standard)))->toBeFalse()
        ->and($laid->commentsOn(Occasion::PullRequest, Listed::of(...$standard)))->toBeTrue();
});

it('writes each toggle of a run and calls the builder for it, where a layer sets it', function (string $name): void {
    $toggle = Toggle::from($name);
    $set = CommentWrites::of(toggles: CommentToggles::off($toggle));

    expect(Decoded::at($set->json()->line(), $toggle->value))->toBeFalse()
        ->and($set->php()->code())->toContain('Comment::')
        ->and($set->allows($toggle))->toBeFalse();
})->with([
    'drafts' => ['drafts'],
    'sampled' => ['sampled'],
    'cut short' => ['cutShort'],
]);

it('writes none of the toggles a comment\'s text says', function (string $name): void {
    $toggle = Toggle::from($name);
    expect(CommentWrites::of(toggles: CommentToggles::off($toggle))->json()->line())->toBe('{}');
})->with([
    'try' => ['try'],
    'reproduce' => ['reproduce'],
]);
