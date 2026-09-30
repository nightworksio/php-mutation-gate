<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\KeysRecord;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\UnitRecord;

use function sprintf;

use stdClass;

/**
 * A plan as `.mutation-gate/plan.json` holds it, `"format": 1`: the commit it
 * was made on, every considered unit's key, and the shards, with the digest
 * of all of that. A plan that cannot be read, or whose digest does not match
 * what it holds, is refused: a shard never guesses at its units.
 *
 * @internal the shape of the plan file
 *
 * @phpstan-import-type Written from KeysRecord as KeysWritten
 * @phpstan-import-type Written from UnitRecord as UnitWritten
 *
 * @phpstan-type RunOnWritten array{ref?: string, defaultBranch?: string}
 * @phpstan-type ShardWritten array{id: int, label: string, seconds: float, package: string, units: list<UnitWritten>}
 * @phpstan-type Body array{
 *     format: int,
 *     commit: string,
 *     ref?: string,
 *     defaultBranch?: string,
 *     keys: KeysWritten|stdClass,
 *     shards: list<ShardWritten>,
 * }
 */
final readonly class PlanFile
{
    private const int FORMAT = 1;

    private const string DIGEST = 'digest';

    private const string REF = 'ref';

    private const string DEFAULT_BRANCH = 'defaultBranch';

    public static function encode(Plan $plan): string
    {
        return JsonText::encode([...self::body($plan), self::DIGEST => self::digestOf($plan)->value()]);
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

    /** The digest of everything a plan holds. */
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
            ...self::runOn($plan->runOn()),
            'keys' => KeysRecord::of($plan->keys()),
            'shards' => array_map(self::shard(...), [...$plan]),
        ];
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

    /** @return ShardWritten */
    private static function shard(Shard $shard): array
    {
        return [
            'id' => $shard->id()->number(),
            'label' => $shard->label(),
            'seconds' => $shard->cost()->seconds(),
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

        $plan = Plan::of(
            Revision::ref($file->field('commit')->text()),
            KeysRecord::read($file->field('keys')),
            Shards::of(...$shards),
        )->on(self::runOnIn($file));

        return $plan->digest()->value() === $file->field(self::DIGEST)->text()
            ? $plan
            : CannotJudge::because(
                'The plan does not match its digest, so it was changed after it was made. Plan again.',
            );
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

        return Shard::of(
            $id > 0 ? ShardId::of($id) : throw NotInShape::at($shard->field('id')->at(), 'a shard number'),
            Package::at(Path::of($shard->field('package')->text())),
            UnitRecord::readAll($shard->field('units')),
            Seconds::of($shard->field('seconds')->number()),
            $shard->field('label')->text(),
        );
    }
}
