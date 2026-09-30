<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

use Traversable;

/**
 * Which tests killed each mutant first, and the mutants of each named
 * function, how often, keeping the five most of each (ADR-0013, decision 2).
 * A mutant the history has never seen takes its function's. The gate's
 * mutant id holds no line, so a mutant keeps its history when code above it
 * moves. Each is kept in the order it last learned something, the newest
 * last.
 */
final readonly class KillHistory
{
    /**
     * @param array<string, RankedMutant>   $mutants   by mutant id
     * @param array<string, RankedFunction> $functions by file and function
     */
    private function __construct(private array $mutants, private array $functions)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** This history, with a mutant's ranking as its newest. */
    public function withMutant(MutantId $mutant, Ranking $ranking): self
    {
        $mutants = $this->mutants;
        unset($mutants[$mutant->value()]);
        $mutants[$mutant->value()] = RankedMutant::of($mutant, $ranking);

        return new self($mutants, $this->functions);
    }

    /** This history, with a function's ranking as its newest. */
    public function withFunction(Enclosing $function, Ranking $ranking): self
    {
        $functions = $this->functions;
        unset($functions[$this->keyOf($function)]);
        $functions[$this->keyOf($function)] = RankedFunction::of($function, $ranking);

        return new self($this->mutants, $functions);
    }

    /**
     * This history, having learned the first killer of a mutant, in the
     * function it is in. A mutant that was not killed, or was killed by a
     * test nobody knows, teaches it nothing.
     */
    public function learnedFrom(Mutant $mutant, Enclosing|Nameless $in): self
    {
        $killers = [...$mutant->killers()];

        if ($mutant->status() !== MutantStatus::Killed || $killers === []) {
            return $this;
        }

        $learned = $this->withMutant($mutant->id(), $this->mutantRanking($mutant->id())->killedBy($killers[0]));

        return $in instanceof Enclosing
            ? $learned->withFunction($in, $this->functionRanking($in)->killedBy($killers[0]))
            : $learned;
    }

    /**
     * The tests most likely to kill a mutant, most likely first: those that
     * killed it before, or where it has no history, those that killed the
     * mutants of its function.
     */
    public function likelyKillers(MutantId $mutant, Enclosing|Nameless $in): TestIds
    {
        $own = $this->mutantRanking($mutant);

        return count($own) > 0 || $in instanceof Nameless ? $own->tests() : $this->functionRanking($in)->tests();
    }

    /**
     * This history and another scope's, read together: where both know a
     * mutant or a function, this one's, and this one's newer than the other's.
     */
    public function and(self $other): self
    {
        $mutants = $other->mutants;
        $functions = $other->functions;

        foreach ($this->mutants as $key => $mutant) {
            unset($mutants[$key]);
            $mutants[$key] = $mutant;
        }

        foreach ($this->functions as $key => $function) {
            unset($functions[$key]);
            $functions[$key] = $function;
        }

        return new self($mutants, $functions);
    }

    /**
     * This history, keeping of these mutants, and of its functions, the ones
     * that learned a killer most recently, at most so many of each.
     */
    public function keeping(MutantIds $held, Bound $mutants, Bound $functions): self
    {
        $ids = [];
        $kept = [];

        foreach ($held as $id) {
            $ids[$id->value()] = true;
        }

        foreach ($this->mutants as $key => $mutant) {
            $kept = array_key_exists($key, $ids) ? [...$kept, $key => $mutant] : $kept;
        }

        return new self(
            array_slice($kept, -$mutants->count(), preserve_keys: true),
            array_slice($this->functions, -$functions->count(), preserve_keys: true),
        );
    }

    /** This history, with the functions of these files only, which are the ones that still exist. */
    public function onlyIn(Paths $files): self
    {
        $kept = [];

        foreach ($this->functions as $key => $function) {
            $kept = $files->has($function->function()->file()) ? [...$kept, $key => $function] : $kept;
        }

        return new self($this->mutants, $kept);
    }

    /** @return Traversable<int, RankedMutant> each mutant and its ranking, the newest last */
    public function mutants(): Traversable
    {
        yield from array_values($this->mutants);
    }

    /** @return Traversable<int, RankedFunction> each function and its ranking, the newest last */
    public function functions(): Traversable
    {
        yield from array_values($this->functions);
    }

    private function mutantRanking(MutantId $mutant): Ranking
    {
        return array_key_exists($mutant->value(), $this->mutants)
            ? $this->mutants[$mutant->value()]->ranking()
            : Ranking::none();
    }

    private function functionRanking(Enclosing $function): Ranking
    {
        $key = $this->keyOf($function);

        return array_key_exists($key, $this->functions) ? $this->functions[$key]->ranking() : Ranking::none();
    }

    private function keyOf(Enclosing $function): string
    {
        return sprintf("%s\n%s", $function->file()->value(), $function->function());
    }
}
