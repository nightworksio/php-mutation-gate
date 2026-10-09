<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;

/**
 * The mutants the engine made that leave their file as one made before them
 * does (ADR-0025, decision 13): one program, so one run judges the first,
 * and each twin takes its verdict, run in no time.
 */
final readonly class Twins
{
    /**
     * @param list<MadeMutant>                   $first each mutant whose mutated file no mutant before it left
     * @param array<string, list<MadeMutant>>    $twins the rest, by the id of the first that left their file alike
     */
    private function __construct(private array $first, private array $twins)
    {
    }

    /** @param list<MadeMutant> $made in the order the engine made them */
    public static function of(array $made): self
    {
        $first = [];
        $firstOf = [];
        $twins = [];

        foreach ($made as $mutant) {
            $copy = sprintf(
                '%s %s',
                $mutant->location()->file()->value(),
                Digest::sha256Of($mutant->mutated()->text())->value(),
            );

            if (array_key_exists($copy, $firstOf)) {
                $twins[$firstOf[$copy]][] = $mutant;

                continue;
            }

            $firstOf[$copy] = $mutant->id()->value();
            $first[] = $mutant;
        }

        return new self($first, $twins);
    }

    /**
     * The mutants a run judges: the first of each that leave their file alike.
     *
     * @return list<MadeMutant>
     */
    public function first(): array
    {
        return $this->first;
    }

    /**
     * Each judged mutant, each followed by its twins, judged as it was.
     *
     * @param  list<Mutant> $judged
     * @return list<Mutant>
     */
    public function joined(array $judged): array
    {
        $joined = [];

        foreach ($judged as $mutant) {
            $joined[] = $mutant;

            foreach ($this->twinsOf($mutant) as $twin) {
                $joined[] = $this->judgedAs($twin, $mutant);
            }
        }

        return $joined;
    }

    /** The evidence of the judged mutants' kills, and of each twin's, which is its first's. */
    public function evidence(Evidences $judged): Evidences
    {
        $evidence = $judged;

        foreach ($this->first as $first) {
            foreach ($this->twinsOfId($first->id()->value()) as $twin) {
                $evidence = $evidence->with($twin->id(), $judged->of($first->id()));
            }
        }

        return $evidence;
    }

    /** @return list<MadeMutant> */
    private function twinsOf(Mutant $mutant): array
    {
        return $this->twinsOfId($mutant->id()->value());
    }

    /** @return list<MadeMutant> */
    private function twinsOfId(string $id): array
    {
        return array_key_exists($id, $this->twins) ? $this->twins[$id] : [];
    }

    /** A twin, judged as the first whose mutated file it leaves: its status, killers, limits and why. */
    private function judgedAs(MadeMutant $twin, Mutant $first): Mutant
    {
        $limit = $first->limit();
        $need = $first->unmutatedNeed();
        $reason = $first->reason();
        $judged = Mutant::of(
            $twin->id(),
            $twin->id()->value(),
            $twin->location(),
            $twin->mutation(),
            $first->status(),
            Seconds::of(0.0),
        )->killedBy($first->killers());
        $judged = $limit instanceof Unmeasured ? $judged : $judged->withLimit($limit);
        $judged = $need instanceof Unmeasured ? $judged : $judged->withUnmutatedNeed($need);

        return match (true) {
            $reason instanceof Reason => $judged->because($reason),
            $reason instanceof Rejection => $judged->rejected($reason),
            default => $judged,
        };
    }
}
