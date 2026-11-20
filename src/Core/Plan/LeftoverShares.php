<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_filter;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * The shares the mutants a plan's shards left at their deadline are cut
 * into, for a second wave of jobs to judge (ADR-0190), as
 * `.mutation-gate/results/leftovers.json` holds them: the digest of the plan
 * they were cut from, and each share's shard, number and mutants, each
 * mutant by the gate's id, the runner's own and the file it mutates; and,
 * as they are cut, why none is, where the shards' results alone decide the
 * verdict (ADR-0301).
 *
 * @internal the shape of the file
 */
final readonly class LeftoverShares
{
    private const int FORMAT = 1;

    /** The field of a share holding the wall seconds its job's passes were predicted to take. */
    private const string PREDICTED = 'predicted';

    private const string UNREAD = 'The shares of the mutants the shards left cannot be read: %s';

    /**
     * @param list<LeftoverShare> $shares
     * @param string|NotGiven     $decided why the verdict is decided without a second wave, where it is
     */
    private function __construct(
        private string $plan,
        private array $shares,
        private string|NotGiven $decided = new NotGiven(),
    ) {
    }

    /** No shares, as the verdict is decided without a second wave, for this reason (ADR-0301). */
    public static function decided(Digest $plan, string $why): self
    {
        return new self($plan->value(), [], $why);
    }

    /** Why the verdict is decided without a second wave, so none is cut; none where the shares were cut. */
    public function decidedBy(): string|NotGiven
    {
        return $this->decided;
    }

    /** These shares, cut from the mutants this plan's shards left. */
    public static function cut(Digest $plan, LeftoverShare ...$shares): self
    {
        return new self($plan->value(), array_values($shares));
    }

    public static function decode(string $json): self|CannotJudge
    {
        try {
            return self::read(Node::decode($json));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf(self::UNREAD, $refused->getMessage()));
        }
    }

    public function encode(): string
    {
        $shares = [];

        foreach ($this->shares as $share) {
            $shares[] = $this->shareRecord($share);
        }

        return JsonText::compact(['format' => self::FORMAT, 'plan' => $this->plan, 'shares' => $shares]);
    }

    /**
     * The matrix of the second wave's jobs: each share's shard and number, in their order.
     *
     * @return list<array{shard: int, share: int}>
     */
    public function matrix(): array
    {
        $matrix = [];

        foreach ($this->shares as $share) {
            $matrix[] = ['shard' => $share->shard()->number(), 'share' => $share->number()];
        }

        return $matrix;
    }

    /** Whether they were cut from this plan. */
    public function cutFrom(Digest $plan): bool
    {
        return $this->plan === $plan->value();
    }

    /** @return list<LeftoverShare> the shares of this shard's mutants, in their order */
    public function ofShard(ShardId $shard): array
    {
        return array_values(array_filter(
            $this->shares,
            static fn(LeftoverShare $share): bool => $share->shard()->number() === $shard->number(),
        ));
    }

    /**
     * @return array{
     *     shard: int,
     *     share: int,
     *     mutants: list<array{id: string, native: string, file: string}>,
     *     predicted: array{first: float, second: float},
     * }
     */
    private function shareRecord(LeftoverShare $share): array
    {
        $mutants = [];

        foreach ($share->listed() as $mutant) {
            $mutants[] = [
                'id' => $mutant->id()->value(),
                'native' => $mutant->native(),
                'file' => $mutant->file()->value(),
            ];
        }

        return [
            'shard' => $share->shard()->number(),
            'share' => $share->number(),
            'mutants' => $mutants,
            self::PREDICTED => $share->predicted()->record(),
        ];
    }

    /** @throws NotInShape */
    private static function read(Node $file): self
    {
        if ($file->field('format')->integer() !== self::FORMAT) {
            throw NotInShape::at($file->field('format')->at(), sprintf('format %d', self::FORMAT));
        }

        $shares = [];

        foreach ($file->field('shares')->items() as $share) {
            $shares[] = self::shareOf($share);
        }

        return new self($file->field('plan')->text(), $shares);
    }

    /** @throws NotInShape */
    private static function shareOf(Node $share): LeftoverShare
    {
        $mutants = [];

        foreach ($share->field('mutants')->items() as $item) {
            $id = MutantId::parse($item->field('id')->text());
            $mutants[] = LeftMutant::of(
                $id instanceof MutantId ? $id : throw NotInShape::at($item->field('id')->at(), 'a mutant\'s id'),
                $item->field('native')->text(),
                Path::of($item->field('file')->text()),
            );
        }

        $predicted = PassSeconds::read($share->field(self::PREDICTED));

        return LeftoverShare::of(
            ShardId::of($share->field('shard')->integer()),
            $share->field('share')->integer(),
            ...$mutants,
        )->predicting($predicted->first(), $predicted->second());
    }
}
