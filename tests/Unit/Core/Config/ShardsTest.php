<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\CostMode;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Ignores;
use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Config\LearnedStatistic;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Config\Part;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\Planning;
use NightWorksIO\MutationGate\Core\Config\Price;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Config\Publishing;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Config\ShardTail;
use NightWorksIO\MutationGate\Core\Config\Table;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Share;

describe('Shards', function (): void {
    it('stays as it is under a part of another kind', function (Part $part): void {
        expect(Shards::standard()->over($part))->toEqual(Shards::standard())
            ->and($part->over(Shards::standard()))->toEqual($part);
    })->with([
        'setup' => [fn(): Setup => Setup::standard()],
        'floors' => [fn(): Floors => Floors::standard()],
        'reach' => [fn(): Reach => Reach::standard()],
        'ci' => [fn(): Ci => Ci::standard()],
        'proofs' => [fn(): Proofs => Proofs::standard()],
        'triage' => [fn(): Triage => Triage::standard()],
        'ignores' => [fn(): Ignores => Ignores::standard()],
        'reports' => [fn(): Reports => Reports::standard()],
        'publishing' => [fn(): Publishing => Publishing::standard()],
        'pest' => [fn(): Pest => Pest::standard()],
        'local' => [fn(): Local => Local::standard()],
    ]);

    it('costs a line what the gate assumes before anything is measured, where no layer sets a cost', function (): void {
        expect(iterator_to_array(Shards::none()->costs()->secondsPerLine(), preserve_keys: true))->toBe(['' => 0.2]);
    });

    it('lets a later target replace the seconds a shard is cut to, a later seconds replace the target, and keeps both a part sets', function (Shards $earlier, Shards $later, Shards $laid): void {
        expect($earlier->over($later))->toEqual($laid);
    })->with([
        'a later target' => [fn(): Shards => Shards::of(seconds: Seconds::of(600)), fn(): Shards => Shards::of(target: Seconds::of(1800)), fn(): Shards => Shards::of(target: Seconds::of(1800))],
        'later seconds' => [fn(): Shards => Shards::of(target: Seconds::of(1800)), fn(): Shards => Shards::of(seconds: Seconds::of(600)), fn(): Shards => Shards::of(seconds: Seconds::of(600))],
        'both, later' => [
            fn(): Shards => Shards::of(seconds: Seconds::of(600)),
            fn(): Shards => Shards::of(seconds: Seconds::of(300), target: Seconds::of(900)),
            fn(): Shards => Shards::of(seconds: Seconds::of(300), target: Seconds::of(900)),
        ],
        'neither, later' => [fn(): Shards => Shards::of(seconds: Seconds::of(600)), fn(): Shards => Shards::of(max: 3), fn(): Shards => Shards::of(seconds: Seconds::of(600), max: 3)],
    ]);

    it('writes each setting it holds as the PHP config calls it, each rate a line costs before the price of a minute', function (): void {
        $shards = Shards::of(
            seconds: Seconds::of(600),
            max: 3,
            target: Seconds::of(1800),
            setup: Seconds::of(180),
            secondsPerLine: Table::row('', 0.25)->merged(Table::row('src/Legacy', 2)),
            perRunnerMinute: Price::of(0.008, 'USD'),
            savingsWindow: Seconds::days(7),
            jobLimit: Seconds::minutes(50),
            testsBeforeAKill: 3,
            surviving: 0.05,
            shardOverhead: 2,
            progress: Seconds::minutes(5),
            margin: Seconds::of(90),
            costMode: CostMode::Minutes,
            tail: ShardTail::from('run'),
        );

        expect($shards->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith(
            'Shards::seconds(600)',
            'Shards::max(3)',
            "Shards::target('30m')",
            "Shards::setup('3m')",
            "Shards::jobLimit('50m')",
            "Shards::progress('5m')",
            "Shards::margin('1m30s')",
            "Shards::tail('run')",
            "Costs::secondsPerLine('', 0.25)",
            "Costs::secondsPerLine('src/Legacy', 2)",
            "Costs::perRunnerMinute(0.008, 'USD')",
            "Costs::savingsWindow('7d')",
            'Costs::testsBeforeAKill(3)',
            'Costs::surviving(0.05)',
            'Costs::shardOverhead(2)',
            'Costs::minutes()',
        ));
    });

    it('cuts to the target costs.mode gives where neither shards.target nor shards.seconds is set, and stops its jobs there', function (Shards $shards, Seconds|Absent $target, float $stop): void {
        expect($shards->target())->toEqual($target)
            ->and($shards->stop()->target()->seconds())->toBe($stop)
            ->and(Shards::standard()->over($shards)->costs()->mode())->toBe($shards->costs()->mode());
    })->with([
        'the wall time, by default' => [fn(): Shards => Shards::none(), fn(): Absent => Absent::setting(), 600.0],
        'the wall time, as written' => [fn(): Shards => Shards::of(costMode: CostMode::Wall), fn(): Absent => Absent::setting(), 600.0],
        'the billed minutes' => [fn(): Shards => Shards::of(costMode: CostMode::Minutes), fn(): Seconds => Seconds::minutes(30), 1800.0],
        'the billed minutes, to a target set' => [
            fn(): Shards => Shards::of(target: Seconds::minutes(20), costMode: CostMode::Minutes),
            fn(): Seconds => Seconds::minutes(20),
            1200.0,
        ],
        'the billed minutes, by the seconds set' => [
            fn(): Shards => Shards::of(seconds: Seconds::of(300), costMode: CostMode::Minutes),
            fn(): Absent => Absent::setting(),
            1800.0,
        ],
    ]);

    it('leaves a CI shard\'s progress every three minutes where no layer says, and as often as one says', function (): void {
        expect(Shards::standard()->progress())->toEqual(Seconds::minutes(3))
            ->and(Shards::none()->progress())->toEqual(Seconds::minutes(3))
            ->and(Shards::none()->over(Shards::of(progress: Seconds::of(0)))->progress())->toEqual(Seconds::of(0))
            ->and(Shards::of(progress: Seconds::of(30))->over(Shards::none())->progress())->toEqual(Seconds::of(30));
    });

    it('learns shards.max from the statistic shards.learnedFrom names, the most by default, and from none where it is written', function (): void {
        expect(Shards::of(max: Learned::Learned, learnedFrom: LearnedStatistic::Median)->learnedFrom())->toBe(LearnedStatistic::Median)
            ->and(Shards::of(max: Learned::Learned)->learnedFrom())->toBe(LearnedStatistic::Max)
            ->and(Shards::of(max: 3, learnedFrom: LearnedStatistic::Median)->learnedFrom())->toEqual(NotGiven::value())
            ->and(Shards::none()->learnedFrom())->toEqual(NotGiven::value());
    });

    it('holds how a run plans its work, and plans as it always has where no layer says how', function (): void {
        expect(Shards::of(planning: Planning::of(chunkMinutes: 30))->planning()->chunk())->toEqual(Seconds::of(1800))
            ->and(Shards::none()->planning())->toEqual(Planning::of());
    });

    it('fills a job and confirms doubtful kills by the shares a layer sets, a later layer\'s first, and by the standard ones where none does', function (): void {
        $set = Planning::of(fill: 0.5, confirm: 0.25);

        expect(Planning::of()->confirm())->toEqual(Share::of(Planning::CONFIRM))
            ->and(Planning::standard()->confirm())->toEqual(Share::of(0.1))
            ->and($set->confirm())->toEqual(Share::of(0.25))
            ->and($set->fill())->toBe(0.5)
            ->and($set->over(Planning::of(confirm: 1))->confirm())->toEqual(Share::whole())
            ->and($set->over(Planning::of())->confirm())->toEqual(Share::of(0.25))
            ->and($set->confirmWritten())->toEqual(Member::of('confirm', 0.25))
            ->and($set->php())->toEqual(PhpCalls::inWith('ShardShares::fill(0.5)', 'ShardShares::confirm(0.25)'));
    });

    it('stops a job at shards.jobLimit alone where no target is set, and at the sooner of the two where one is', function (): void {
        $setup = Seconds::of(60.0);
        $windDown = Seconds::of(35.0);
        $limited = Shards::of(jobLimit: Seconds::minutes(50))->stop();
        $targeted = Shards::of(target: Seconds::minutes(20), jobLimit: Seconds::minutes(50))->stop();

        expect($limited->after($setup, $windDown))->toEqual(Seconds::minutes(50))
            ->and($targeted->after($setup, $windDown))->toEqual(Seconds::of(1200.0 - 60.0 - 35.0 - $targeted->margin()->seconds()));
    });
})->group('holds:src/Core/Config/Shards.php');
