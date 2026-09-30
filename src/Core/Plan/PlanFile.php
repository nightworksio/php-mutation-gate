<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\DigestsRecord;
use NightWorksIO\MutationGate\Core\Proof\KeysRecord;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestNamesRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\UnitRecord;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

use stdClass;

/**
 * A plan as `.mutation-gate/plan.json` holds it, `"format": 1`: the full id of
 * the commit it was made on, the base its keys are built on, every considered
 * unit's key, and the shards, with the units it proved or carried, and the
 * lines a change added or modified with why it reached what it did, the
 * digests of the run's inputs each proof records its share of, and the
 * digest of all of that. Beside it, outside the digest since they judge
 * nothing, `names` holds the names the runner gives the suite's tests, or
 * `unnamed` why it gave none; a plan with neither was made without asking.
 * A plan that cannot be read, or whose digest does not match what it holds,
 * is refused: a shard never guesses at its units.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-import-type Written from KeysRecord as KeysWritten
 * @phpstan-import-type Written from UnitRecord as UnitWritten
 * @phpstan-import-type RunWritten from DigestsRecord as RunDigests
 *
 * @phpstan-type RunOnWritten array{ref?: string, defaultBranch?: string}
 * @phpstan-type ShardWritten array<string, int|string|float|list<UnitWritten>>
 * @phpstan-type ConsideredWritten array{proved?: list<UnitWritten>, carried?: list<UnitWritten>}
 * @phpstan-type ChangeWritten array{changed?: array<string, list<int>>, reach?: list<string>}
 * @phpstan-type Body array{
 *     format: int,
 *     commit: string,
 *     base: string,
 *     ref?: string,
 *     defaultBranch?: string,
 *     keys: KeysWritten|stdClass,
 *     shards: list<ShardWritten>,
 *     proved?: list<UnitWritten>,
 *     carried?: list<UnitWritten>,
 *     changed?: array<string, list<int>>,
 *     reach?: list<string>,
 *     digests?: RunDigests,
 * }
 */
final readonly class PlanFile
{
    private const int FORMAT = 1;

    private const string DIGEST = 'digest';

    private const string BASE = 'base';

    private const string REF = 'ref';

    private const string DEFAULT_BRANCH = 'defaultBranch';

    private const string PROVED = 'proved';

    private const string CARRIED = 'carried';

    private const string CHANGED = 'changed';

    private const string REACH = 'reach';

    private const string NAMES = 'names';

    private const string UNNAMED = 'unnamed';

    private const string DIGESTS = DigestsRecord::FIELD;

    public static function encode(Plan $plan): string
    {
        $names = $plan->names();

        return JsonText::encode([
            ...self::body($plan),
            ...$names instanceof TestNames
                ? [self::NAMES => TestNamesRecord::of($names)]
                : [self::UNNAMED => $names->why()],
            self::DIGEST => self::digestOf($plan)->value(),
        ]);
    }

    public static function decode(string $json): Plan|CannotJudge
    {
        try {
            return self::planIn(Node::decode($json));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf(
                'The plan cannot be read, so no shard can follow it: %s',
                $refused->getMessage(),
            ));
        }
    }

    /** The digest of everything a plan holds but the test names, which judge nothing. */
    public static function digestOf(Plan $plan): Digest
    {
        return Digest::sha256Of(JsonText::encode(self::body($plan)));
    }

    /** @return Body */
    private static function body(Plan $plan): array
    {
        return [
            'format' => self::FORMAT,
            'commit' => $plan->commit()->name(),
            self::BASE => $plan->base()->value(),
            ...self::runOn($plan->runOn()),
            'keys' => KeysRecord::of($plan->keys()),
            'shards' => array_map(self::shard(...), [...$plan]),
            ...self::considered($plan->considered()),
            ...self::change($plan->considered()),
            ...self::digests($plan->digests()),
        ];
    }

    /** @return array{digests?: RunDigests} the digests of the run's inputs, where the plan has them */
    private static function digests(Digests|Undigested $digests): array
    {
        return $digests instanceof Digests ? [self::DIGESTS => DigestsRecord::ofRun($digests)] : [];
    }

    /** @return RunOnWritten the ref and the default branch, each where there is one */
    private static function runOn(RunOn $runOn): array
    {
        $scope = $runOn->scope();
        $defaultBranch = $runOn->defaultBranch();

        return [
            ...$scope instanceof Scope ? [self::REF => $scope->ref()] : [],
            ...$defaultBranch instanceof Scope ? [self::DEFAULT_BRANCH => $defaultBranch->ref()] : [],
        ];
    }

    /** @return ConsideredWritten the units proved and those carried, each where there are any */
    private static function considered(Considered $considered): array
    {
        return [
            ...count($considered->proved()) > 0 ? [self::PROVED => UnitRecord::all($considered->proved())] : [],
            ...count($considered->carried()) > 0 ? [self::CARRIED => UnitRecord::all($considered->carried())] : [],
        ];
    }

    /** @return ChangeWritten the lines a change added or modified, by file, and why it reached what it did */
    private static function change(Considered $considered): array
    {
        $changed = [];

        foreach ($considered->changed() as $change) {
            $changed[$change->path()->value()] = array_map(
                static fn(Line $line): int => $line->number(),
                [...$change->lines()],
            );
        }

        return $changed === [] && count($considered->reach()) === 0 ? [] : [
            self::CHANGED => $changed,
            self::REACH => array_map(static fn(Reason $reason): string => $reason->text(), [...$considered->reach()]),
        ];
    }

    /** @return ShardWritten */
    private static function shard(Shard $shard): array
    {
        return [
            'id' => $shard->id()->number(),
            'label' => $shard->label(),
            'seconds' => $shard->cost()->seconds(),
            ...EstimateRecord::of($shard->estimate()),
            'package' => $shard->package()->path()->value(),
            'units' => UnitRecord::all($shard->units()),
        ];
    }

    /** @throws NotInShape */
    private static function planIn(Node $file): Plan|CannotJudge
    {
        if ($file->field('format')->integer() !== self::FORMAT) {
            throw NotInShape::at($file->field('format')->at(), sprintf('format %d', self::FORMAT));
        }

        $shards = [];

        foreach ($file->field('shards')->items() as $shard) {
            $shards[] = self::shardIn($shard);
        }

        $commit = Commit::parse($file->field('commit')->text());
        $plan = Plan::of(
            $commit instanceof Commit
                ? $commit->revision()
                : throw NotInShape::at($file->field('commit')->at(), 'a commit'),
            Digest::of($file->field(self::BASE)->text()),
            KeysRecord::read($file->field('keys')),
            Shards::of(...$shards),
        )
            ->on(self::runOnIn($file))
            ->considering(
                Considered::everything()
                    ->reaching(self::changedIn($file), self::reachIn($file))
                    ->proving(self::unitsIn($file->field(self::PROVED)))
                    ->carrying(self::unitsIn($file->field(self::CARRIED))),
            );
        $digests = $file->field(self::DIGESTS);
        $plan = $digests->isPresent() ? $plan->digesting(DigestsRecord::readRun($digests)) : $plan;
        $named = self::namedIn($plan, $file);

        return $plan->digest()->value() === $file->field(self::DIGEST)->text()
            ? $named
            : CannotJudge::because(
                'The plan does not match its digest, so it was changed after it was made. Plan again.',
            );
    }

    /**
     * The plan, with the names it holds, or why the runner gave none; a plan
     * that holds neither was made without asking.
     *
     * @throws NotInShape
     */
    private static function namedIn(Plan $plan, Node $file): Plan
    {
        $names = $file->field(self::NAMES);
        $unnamed = $file->field(self::UNNAMED);

        return match (true) {
            $names->isPresent() => $plan->naming(TestNamesRecord::read($names)),
            $unnamed->isPresent() => $plan->naming(CannotJudge::because($unnamed->text())),
            default => $plan,
        };
    }

    /** @throws NotInShape */
    private static function unitsIn(Node $units): Units
    {
        return $units->isPresent() ? UnitRecord::readAll($units) : Units::none();
    }

    /** @throws NotInShape */
    private static function changedIn(Node $file): Changes
    {
        $changes = Changes::none();
        $changed = $file->field(self::CHANGED);

        foreach ($changed->isPresent() ? $changed->entries() : [] as $path => $numbers) {
            $lines = Lines::none();

            foreach ($numbers->items() as $number) {
                $lines = $lines->with(self::lineIn($number));
            }

            $changes = $changes->with(Change::modified(Path::of($path), $lines));
        }

        return $changes;
    }

    /** @throws NotInShape */
    private static function reachIn(Node $file): Reasons
    {
        $reasons = Reasons::of();
        $reach = $file->field(self::REACH);

        foreach ($reach->isPresent() ? $reach->items() : [] as $reason) {
            $reasons = $reasons->with(Reason::that($reason->text()));
        }

        return $reasons;
    }

    /** @throws NotInShape */
    private static function lineIn(Node $line): Line
    {
        $number = $line->integer();

        return $number > 0 ? Line::of($number) : throw NotInShape::at($line->at(), 'a line');
    }

    /** @throws NotInShape */
    private static function runOnIn(Node $file): RunOn
    {
        $named = $file->field(self::DEFAULT_BRANCH);
        $defaultBranch = $named->isPresent()
            ? self::scopeIn($named)
            : CannotTell::because('The plan names no default branch.');
        $ref = $file->field(self::REF);

        return $ref->isPresent() ? RunOn::at(self::scopeIn($ref), $defaultBranch) : RunOn::detached($defaultBranch);
    }

    /** @throws NotInShape */
    private static function scopeIn(Node $ref): Scope
    {
        $scope = Scope::parse($ref->text());

        return $scope instanceof CannotJudge ? throw NotInShape::at($ref->at(), 'a scope') : $scope;
    }

    /** @throws NotInShape */
    private static function shardIn(Node $shard): Shard
    {
        $id = $shard->field('id')->integer();

        $read = Shard::of(
            $id > 0 ? ShardId::of($id) : throw NotInShape::at($shard->field('id')->at(), 'a shard number'),
            Package::at(Path::of($shard->field('package')->text())),
            UnitRecord::readAll($shard->field('units')),
            Seconds::of($shard->field('seconds')->number()),
            $shard->field('label')->text(),
        );

        return $read->estimated(EstimateRecord::read($shard, $read->cost()));
    }
}
