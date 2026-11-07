<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\CommentSettings;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Config\CommentToggles;
use NightWorksIO\MutationGate\Core\Config\CommentWrites;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Report\RecheckedMarkdown;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\CommentThreadFake;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\Sampled;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** The states of a comment in this thread, on a pull request, shaped by these keys. */
function commentStates(CommentThreadFake $thread, CommentWrites $writes, bool $draft = false): CommentStates
{
    return CommentStates::in(
        $thread,
        'https://ci.example/7',
        commentsHere: true,
        settings: CommentSettings::of(CommentShows::of(), $writes),
        draft: $draft,
    );
}

it('writes every state of a pull request by default, and a verdict that did not pass as any other comment', function (): void {
    $thread = new CommentThreadFake();
    $states = commentStates(new CommentThreadFake(), CommentWrites::of());
    $written = commentStates($thread, CommentWrites::of())->judged(Verdicts::failing());

    expect($written)->toEqual(Written::to('the thread'))
        ->and($thread->write?->isExistingOnly())->toBeFalse()
        ->and($thread->write?->isOverPlanned())->toBeFalse()
        ->and($states->writesIn(State::Planned))->toBeTrue()
        ->and($states->writesIn(State::Rechecked))->toBeTrue()
        ->and($states->writesIn(State::Verdict))->toBeTrue();
});

it('writes the planned state and the re-check through the thread, the re-check over the planned state alone', function (): void {
    $planned = new CommentThreadFake();
    $rechecked = new CommentThreadFake();

    expect(commentStates($planned, CommentWrites::of())->planned(ShardedPlan::planned(2)))->toEqual(Written::to('the thread'))
        ->and($planned->markdown)->toBe(PlannedMarkdown::comment(ShardedPlan::planned(2), 'https://ci.example/7'))
        ->and($planned->write?->isOverPlanned())->toBeFalse()
        ->and(commentStates($rechecked, CommentWrites::of())->rechecked(Rechecks::mixed()))->toEqual(Written::to('the thread'))
        ->and($rechecked->markdown)->toBe(RecheckedMarkdown::comment(Rechecks::mixed(), 'https://ci.example/7', CommentShows::of()))
        ->and($rechecked->write?->isOverPlanned())->toBeTrue();
});

it('writes no state comment.states leaves out, and says which, now or for deliver', function (): void {
    $thread = new CommentThreadFake();
    $states = commentStates($thread, CommentWrites::of(states: Listed::of(State::Verdict)));
    $delivery = Delivery::none();

    expect($states->planned(ShardedPlan::planned(2)))->toEqual(NotWritten::because('comment.states leaves out the planned state, so it is not written.'))
        ->and($states->rechecked(Rechecks::mixed()))->toEqual(NotWritten::because('comment.states leaves out the rechecked state, so it is not written.'))
        ->and($states->plannedLater(ShardedPlan::planned(2), $delivery))->toEqual($delivery)
        ->and($states->recheckedLater(Rechecks::mixed(), $delivery))->toEqual($delivery)
        ->and($states->writesIn(State::Planned))->toBeFalse()
        ->and($states->writesIn(State::Verdict))->toBeTrue()
        ->and($thread->write)->toBeNull()
        ->and(commentStates($thread, CommentWrites::of(states: Listed::of(State::Planned)))->judged(Verdicts::failing()))
        ->toEqual(NotWritten::because('comment.states leaves out the verdict state, so it is not written.'));
});

it('writes nothing on a pull request where comment.on leaves pull requests out, and writes there where it lists them', function (): void {
    $thread = new CommentThreadFake();
    $elsewhere = CommentWrites::of(on: Listed::of(Occasion::DefaultBranch));

    expect(commentStates($thread, $elsewhere)->judged(Verdicts::failing()))
        ->toEqual(NotWritten::because('comment.on leaves out pull requests, so no comment is written.'))
        ->and($thread->write)->toBeNull()
        ->and(commentStates($thread, CommentWrites::of(on: Listed::of(Occasion::DefaultBranch, Occasion::PullRequest)))->judged(Verdicts::failing()))
        ->toEqual(Written::to('the thread'));
});

it('writes nothing of a draft, a sampled or a cut-short run a toggle turns off, and writes each where it is left on', function (Toggle $toggle, bool $draft, Verdict $verdict): void {
    $off = new CommentThreadFake();
    $on = new CommentThreadFake();

    expect(commentStates($off, CommentWrites::of(toggles: CommentToggles::off($toggle)), $draft)->judged($verdict))
        ->toEqual(NotWritten::because(sprintf('comment.%s is false, so this run writes no comment.', $toggle->value)))
        ->and($off->write)->toBeNull()
        ->and(commentStates($on, CommentWrites::of(toggles: CommentToggles::on($toggle)), $draft)->judged($verdict))
        ->toEqual(Written::to('the thread'));
})->with([
    'a draft' => [Toggle::Drafts, true, fn(): Verdict => Verdicts::failing()],
    'a sampled run' => [Toggle::Sampled, false, fn(): Verdict => Sampled::verdict(Floor::of(60))],
    'a cut-short run' => [Toggle::CutShort, false, fn(): Verdict => Verdicts::failing()->cutShort()],
]);

it('writes a pull request that is no draft whatever comment.drafts says', function (): void {
    $thread = new CommentThreadFake();

    expect(commentStates($thread, CommentWrites::of(toggles: CommentToggles::off(Toggle::Drafts)))->judged(Verdicts::failing()))
        ->toEqual(Written::to('the thread'));
});

it('writes a passing run as comment.passing says: only over a comment there by default, as any other, or not at all', function (): void {
    $update = new CommentThreadFake();
    $always = new CommentThreadFake();
    $never = new CommentThreadFake();

    expect(commentStates($update, CommentWrites::of())->judged(Verdicts::passing()))->toEqual(Written::to('the thread'))
        ->and($update->write?->isExistingOnly())->toBeTrue()
        ->and(commentStates($always, CommentWrites::of(passing: Passing::Always))->judged(Verdicts::passing()))->toEqual(Written::to('the thread'))
        ->and($always->write?->isExistingOnly())->toBeFalse()
        ->and(commentStates($never, CommentWrites::of(passing: Passing::Never))->judged(Verdicts::passing()))
        ->toEqual(NotWritten::because('The run passed and comment.passing is never, so the comment is left as it is.'))
        ->and($never->write)->toBeNull()
        ->and(commentStates(new CommentThreadFake(), CommentWrites::of(passing: Passing::Never))->judged(Verdicts::failing()))
        ->toEqual(Written::to('the thread'));
});

it('marks a comment with its run where each run writes its own, and keeps the one comment unmarked', function (): void {
    $own = new CommentThreadFake();
    $sticky = new CommentThreadFake();

    commentStates($own, CommentWrites::of(mode: Mode::New))->judged(Verdicts::failing());
    commentStates($sticky, CommentWrites::of())->judged(Verdicts::failing());

    expect($own->write?->mode())->toBe(Mode::New)
        ->and($own->markdown)->toEndWith("\n\n<!-- mutation-gate run: https://ci.example/7 -->\n")
        ->and($sticky->write?->mode())->toBe(Mode::Sticky)
        ->and($sticky->markdown)->not->toContain('mutation-gate run:');
});
