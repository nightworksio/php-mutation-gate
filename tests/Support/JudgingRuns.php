<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_map;
use function array_slice;
use function iterator_to_array;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\Judging;
use NightWorksIO\MutationGate\Cli\Flow\Reporting;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Config\Floor as NewCodeFloor;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use RuntimeException;

/** Plans run and judged through the verdict's flow, as the tests of Judging set them up. */
final readonly class JudgingRuns
{
    /** The one tree, `src`, held to this floor. */
    public static function tree(Floor|Undeclared $floor): TreeSourceFake
    {
        return new TreeSourceFake(
            Trees::of(Tree::at(Path::of('src'), $floor, Package::at(Path::root()))),
        );
    }

    /** What chooses the verdict's reporters: this package's own, and one that remembers what it was handed. */
    public static function reporting(Reporter $recorded): Reporting
    {
        return new Reporting(
            new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))->withReporter(
                Name::of('recorded'),
                static fn(): Reporter => $recorded,
            )),
            Variables::of([]),
        );
    }

    /** The settings of the least config, reporting to the recorded reporter, and of these besides. */
    public static function settings(NewCodeFloor|Ignore|Setting ...$parts): Settings
    {
        return Flows::settings(Report::uses('recorded'), ...$parts);
    }

    /** Every shard of a plan run, each handed its map, and then judged. */
    public static function judged(Plan $plan, Adapters $adapters, Settings $settings, Reporting $reporting): Judged|Invalid|CannotJudge
    {
        new Handoff($adapters->project, HandedMaps::limits())->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());
        new Running($adapters, $settings, Flows::setup())->runAll($plan, Workspace::results());
        $results = Results::read($plan, Workspace::results(), $adapters->project);

        return $results instanceof Results
            ? new Judging($adapters, $settings, Flows::setup(), $reporting)->verdict($plan, $results)
            : $results;
    }

    /** The verdict judged, which a test that reaches one expects. */
    public static function verdictOf(Judged|Invalid|CannotJudge $judged): Verdict
    {
        return $judged instanceof Judged
            ? $judged->verdict
            : throw new RuntimeException($judged instanceof CannotJudge ? $judged->why() : 'The config is invalid.');
    }

    /** The results of a plan with no shard, which are none. */
    public static function noResults(string $project): Results
    {
        $results = Results::read(Planned::of(), Workspace::results(), Directory::at($project));

        return $results instanceof Results ? $results : throw new RuntimeException($results->why());
    }

    /**
     * @param iterable<Failure|Warning|Reason> $said
     *
     * @return list<string> the text of each
     */
    public static function texts(iterable $said): array
    {
        return array_map(
            static fn(Failure|Warning|Reason $each): string => $each->text(),
            iterator_to_array($said, preserve_keys: false),
        );
    }

    /** A runner whose every survivor, as an analyser checks it, is the project's src/Money.php laid out otherwise. */
    public static function moneyLaidOut(): ScriptedRunner
    {
        return ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of("<?php\n\nfinal  class Money\n{\n}\n")));
    }

    /**
     * @return array<string, string> each mutant's judgement, by its runner's id
     */
    public static function judgements(Verdict $verdict): array
    {
        $judgements = [];

        foreach ($verdict->trees()->mutants() as $judged) {
            if ($judged instanceof JudgedMutant) {
                $judgements[$judged->mutant()->nativeId()] = $judged->judgement()->value;
            }
        }

        return $judgements;
    }

    /** A budgeted run's plan: the two-shard plan, with its digests and the name of the test that kills in src/Money.php. */
    public static function digested(string $moneySource, string $moneyTest): Plan
    {
        return Planned::twoShards()
            ->digesting(Digests::of(Digest::sha256Of('mutation'))
                ->withSource(Path::of('src/Money.php'), Digest::sha256Of($moneySource))
                ->withSource(Path::of('src/Held.php'), Digest::sha256Of('held'))
                ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of($moneyTest)))
            ->naming(TestNames::none()->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds')));
    }

    /**
     * A store whose default branch proves both units at the plan's base, with
     * these digests: src/Money.php with a survivor and a kill by MoneyTest::adds.
     */
    public static function proven(string $moneySource, string $moneyTest): ProofStoreFake
    {
        $run = Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of(Planned::BASE));
        $mutants = Flows::mutantsOf('src/Money.php');
        $survivor = [...array_filter([...$mutants], static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived)][0];
        $kill = ProvedKill::of(
            MutantId::hash(Path::of('src/Money.php'), 'Plus', 'carried kill', 0),
            Path::of('src/Money.php'),
            Line::of(3),
            'Plus',
            TestIds::of(TestId::of('MoneyTest::adds')),
        );
        $store = new ProofStoreFake();
        $store->write(Scope::branch('main'), Ledger::empty()
            ->withProof(Proof::held(Digest::sha256Of('money before'), Path::of('src/Money.php'), Mutants::of($survivor), ProvedKills::of($kill), $run)
                ->withInputs(Inputs::of(Digest::sha256Of($moneySource), Digest::sha256Of('mutation'))
                    ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of($moneyTest))))
            ->withProof(Proof::of(Digest::sha256Of('held before'), Path::of('src/Held.php'), Mutants::none(), $run)
                ->withInputs(Inputs::of(Digest::sha256Of('held'), Digest::sha256Of('mutation')))));

        return $store;
    }

    /**
     * The project, where src/Money.php uses the trait src/Equals.php declares
     * and nothing names src/Tax.php, and a checkout whose working tree changed
     * this file since each of these commits.
     *
     * @return array{string, ChangeSourceFake}
     */
    public static function across(string $changed, Revision ...$commits): array
    {
        $files = [
            ...Flows::FILES,
            'src/Money.php' => "<?php\n\nfinal class Money\n{\n    use Equals;\n}\n",
            'src/Equals.php' => "<?php\n\ntrait Equals\n{\n}\n",
            'src/Tax.php' => "<?php\n\nfinal class Tax\n{\n}\n",
        ];
        $project = Scratch::directory();

        foreach ($files as $path => $contents) {
            Scratch::write($project, $path, $contents);
        }

        $byRevision = [Revision::workingTree()->name() => $files];

        foreach ($commits as $commit) {
            $byRevision[$commit->name()] = $files;
        }

        $checkout = new ChangeSourceFake($commits[0], Changes::of(Change::modified(Path::of($changed), Lines::of(Line::of(4)))), $byRevision);

        foreach (array_slice($commits, 1) as $commit) {
            $checkout = $checkout->alsoFrom($commit);
        }

        return [$project, $checkout];
    }

    /**
     * A store whose default branch proves both units at another base, each
     * result's digests taken at a commit: src/Money.php with a survivor and a
     * kill by MoneyTest::adds, and src/Held.php with a kill by MoneyTest::adds.
     */
    public static function provenAcross(Revision $money, Revision $held): ProofStoreFake
    {
        $run = Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('another base'));
        $kill = static fn(string $unit): ProvedKill => ProvedKill::of(
            MutantId::hash(Path::of($unit), 'Plus', 'carried kill', 0),
            Path::of($unit),
            Line::of(3),
            'Plus',
            TestIds::of(TestId::of('MoneyTest::adds')),
        );
        $inputs = static fn(string $source, Revision $commit): Inputs => Inputs::of(Digest::sha256Of($source), Digest::sha256Of('mutation'))
            ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
            ->takenAt($commit);
        $store = new ProofStoreFake();
        $store->write(Scope::branch('main'), Ledger::empty()
            ->withProof(Proof::held(Digest::sha256Of('money before'), Path::of('src/Money.php'), Mutants::none(), ProvedKills::of($kill('src/Money.php')), $run)
                ->withInputs($inputs('money', $money)))
            ->withProof(Proof::held(Digest::sha256Of('held before'), Path::of('src/Held.php'), Mutants::none(), ProvedKills::of($kill('src/Held.php')), $run)
                ->withInputs($inputs('held', $held))));

        return $store;
    }

    /**
     * A pull request's run of its shards, each in a job of its own handed its
     * map, in a store, with src held to this floor: shard 1, and shard 2 unless
     * it was cancelled, which leaves no result; then judged.
     *
     * @param list<object> $ports the ports in place of the fakes, such as a checkout
     */
    public static function doomed(ProofStoreFake $store, Floor $floor, bool $cancelled, string $project, array $ports = []): Judged|Invalid|CannotJudge
    {
        $plan = self::digested('money', 'money test')->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
        $adapters = Flows::adapters(
            $project,
            [],
            $store,
            new TreeSourceFake(Trees::of(Tree::at(Path::of('src'), $floor, Package::at(Path::root())))),
            ...$ports,
        );
        $settings = self::settings(Equivalence::notProvenStatically());
        new Handoff($adapters->project, HandedMaps::limits())->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());

        foreach ($cancelled ? [1] : [1, 2] as $shard) {
            new Running($adapters, $settings, Flows::setup())->run($plan, ShardId::of($shard), Workspace::results());
        }

        $results = Results::read($plan, Workspace::results(), $adapters->project);
        $reporting = new Reporting(new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))->withReporter(
            Name::of('recorded'),
            static fn(): Reporter => new ReporterFake(),
        )), Variables::of([]));

        return $results instanceof Results
            ? new Judging($adapters, $settings, Flows::setup(), $reporting)->verdict($plan, $results)
            : $results;
    }

    /** The doom of a run whose first survivor of this file fails src's floor of 100. */
    public static function doomOf(string $file = 'src/Money.php'): Doomed
    {
        $survivor = [...array_filter([...Flows::mutantsOf($file)], static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived)][0];

        return Doomed::of(Path::of($file), $survivor->id(), Path::of('src'), Floor::whole(), DoomedBy::Tree);
    }
}
