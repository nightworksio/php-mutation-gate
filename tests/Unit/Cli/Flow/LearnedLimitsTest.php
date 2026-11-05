<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\LearnedLimits;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\ShardsAtOnce;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LimitHistory;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** The ledgers a pull request's plan reads, its own scope's and the default branch's each holding this history. */
function learnedLedgers(LimitHistory $own, LimitHistory $main): Ledgers
{
    $store = new ProofStoreFake();
    $store->write(Scope::pullRequest(7), Ledger::empty()->withLearned($own));
    $store->write(Scope::branch('main'), Ledger::empty()->withLearned($main));
    $ci = new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $standing = Standing::of($ci, RepositoryFake::onMain(Revision::ref('head')), Absent::setting());

    return $standing instanceof Standing
        ? Ledgers::read($store, $standing, Writing::Auto)
        : throw new RuntimeException($standing->why());
}

/** The settings of a config that learns every limit, reading the most shards by this statistic. */
function learningSettings(string $from = 'max'): Settings
{
    return Configs::settings([
        'runner' => 'pest',
        'timeouts' => ['seconds' => 'learned', 'multiple' => 'learned'],
        'shards' => ['max' => 'learned', 'learnedFrom' => $from],
    ]);
}

it('learns the multiple from its own scope\'s kills, and the most shards from the default branch\'s runs alone, saying what each is and why', function (): void {
    $learned = LearnedLimits::of(
        learningSettings(),
        learnedLedgers(
            LimitHistory::none()->afterKills(Multiple::of(4.5))->afterRuns(ShardsAtOnce::of(15)),
            LimitHistory::none()->afterKills(Multiple::of(9.0))->afterRuns(ShardsAtOnce::of(5))->afterRuns(ShardsAtOnce::of(7)),
        ),
    );

    expect($learned->briefing(Briefing::standard())->multiple())->toEqual(Multiple::of(4.5))
        ->and($learned->cut(Cut::bySize(600, Seconds::of(60.0), 20)))->toEqual(Cut::bySize(600, Seconds::of(60.0), 7))
        ->and($learned->cut(Cut::exactly(12)))->toEqual(Cut::exactly(12))
        ->and($learned->lines())->toBe([
            'timeouts.seconds learned: 3 times each shard\'s start-up, at least 1 s; 10 s where none is measured.',
            'timeouts.multiple learned: 4.5, what 99 in 100 of 1 kills took over their tests\' time, in place of 3.',
            'shards.max learned: 7, the most shards the last 2 runs ran at once, in place of 20.',
        ]);
});

it('reads the default branch\'s kills where its own scope holds none, the median where asked, and keeps the most shards between 1 and 20', function (): void {
    $kills = LimitHistory::none()->afterKills(Multiple::of(1.2));
    $runs = LimitHistory::none()->afterRuns(ShardsAtOnce::of(30))->afterRuns(ShardsAtOnce::of(40))->afterRuns(ShardsAtOnce::of(25));

    expect(LearnedLimits::of(learningSettings('median'), learnedLedgers(LimitHistory::none(), $kills->and($runs)))->lines())
        ->toBe([
            'timeouts.seconds learned: 3 times each shard\'s start-up, at least 1 s; 10 s where none is measured.',
            'timeouts.multiple learned: 2, what 99 in 100 of 1 kills took over their tests\' time, in place of 3.',
            'shards.max learned: 20, the median of the shards the last 3 runs ran at once, in place of 20.',
        ]);
});

it('takes the fixed limits from a cold ledger, and says so', function (): void {
    $learned = LearnedLimits::of(learningSettings(), learnedLedgers(LimitHistory::none(), LimitHistory::none()));

    expect($learned->briefing(Briefing::standard())->multiple())->toEqual(Multiple::of(3.0))
        ->and($learned->cut(Cut::bySize(600, Seconds::of(60.0), 20)))->toEqual(Cut::bySize(600, Seconds::of(60.0), 20))
        ->and($learned->lines())->toBe([
            'timeouts.seconds learned: 3 times each shard\'s start-up, at least 1 s; 10 s where none is measured.',
            'timeouts.multiple learned: 3, the fixed one: the ledger holds no kill yet.',
            'shards.max learned: 20, the fixed one: the ledger holds no sharded run yet.',
        ]);
});

it('learns nothing and says nothing where the config writes every limit', function (): void {
    $learned = LearnedLimits::of(
        Configs::settings(['runner' => 'pest']),
        learnedLedgers(LimitHistory::none()->afterKills(Multiple::of(5.0)), LimitHistory::none()->afterRuns(ShardsAtOnce::of(3))),
    );

    expect($learned->lines())->toBe([])
        ->and($learned->briefing(Briefing::standard()))->toEqual(Briefing::standard())
        ->and($learned->cut(Cut::bySize(600, Seconds::of(60.0), 20)))->toEqual(Cut::bySize(600, Seconds::of(60.0), 20));
});

it('says the learned multiple to two places, and learns a single shard from runs that ran one at a time', function (): void {
    $learned = LearnedLimits::of(
        learningSettings(),
        learnedLedgers(LimitHistory::none()->afterKills(Multiple::of(4.256)), LimitHistory::none()->afterRuns(ShardsAtOnce::of(1))),
    );

    expect($learned->cut(Cut::bySize(600, Seconds::of(60.0), 20)))->toEqual(Cut::bySize(600, Seconds::of(60.0), 1))
        ->and($learned->lines())->toBe([
            'timeouts.seconds learned: 3 times each shard\'s start-up, at least 1 s; 10 s where none is measured.',
            'timeouts.multiple learned: 4.26, what 99 in 100 of 1 kills took over their tests\' time, in place of 3.',
            'shards.max learned: 1, the most shards the last 1 runs ran at once, in place of 20.',
        ]);
});
