<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\File\Paths;
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
     * @param array<string, RankedMutant>   $mutants   by its id's key
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
        return $this->withMutants(RankedMutant::of($mutant, $ranking));
    }

    /** This history, with these mutants' rankings as its newest, each newer than the one before it. */
    public function withMutants(RankedMutant ...$ranked): self
    {
        $mutants = $this->mutants;

        foreach ($ranked as $one) {
            unset($mutants[$one->mutant()->key()]);
            $mutants[$one->mutant()->key()] = $one;
        }

        return new self($mutants, $this->functions);
    }

    /** This history, with a function's ranking as its newest. */
    public function withFunction(Enclosing $function, Ranking $ranking): self
    {
        return $this->withFunctions(RankedFunction::of($function, $ranking));
    }

    /** This history, with these functions' rankings as their newest, each newer than the one before it. */
    public function withFunctions(RankedFunction ...$ranked): self
    {
        $functions = $this->functions;

        foreach ($ranked as $one) {
            unset($functions[$this->keyOf($one->function())]);
            $functions[$this->keyOf($one->function())] = $one;
        }

        return new self($this->mutants, $functions);
    }

    /**
     * This history, having learned, in turn, the first killer of each mutant,
     * in the function it is in. A mutant that was not killed, or was killed
     * by a test nobody knows, teaches it nothing.
     */
    public function learnedFrom(Lesson ...$lessons): self
    {
        $mutants = $this->mutants;
        $functions = $this->functions;

        foreach ($lessons as $lesson) {
            $mutant = $lesson->mutant();
            $killers = [...$mutant->killers()];
            $teaches = $mutant->status() === MutantStatus::Killed && $killers !== [];
            $in = $lesson->in();

            if ($teaches) {
                $ranking = $this->rankingIn($mutants, $mutant->id()->key())->killedBy($killers[0]);
                unset($mutants[$mutant->id()->key()]);
                $mutants[$mutant->id()->key()] = RankedMutant::of($mutant->id(), $ranking);
            }

            if ($teaches && $in instanceof Enclosing) {
                $ranking = $this->rankingIn($functions, $this->keyOf($in))->killedBy($killers[0]);
                unset($functions[$this->keyOf($in)]);
                $functions[$this->keyOf($in)] = RankedFunction::of($in, $ranking);
            }
        }

        return new self($mutants, $functions);
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
            $ids[$id->key()] = true;
        }

        foreach ($this->mutants as $key => $mutant) {
            if (array_key_exists($key, $ids)) {
                $kept[$key] = $mutant;
            }
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
        return $this->rankingIn($this->mutants, $mutant->key());
    }

    private function functionRanking(Enclosing $function): Ranking
    {
        return $this->rankingIn($this->functions, $this->keyOf($function));
    }

    /**
     * The ranking held under a key; none where nothing is.
     *
     * @param array<RankedMutant|RankedFunction> $ranked
     */
    private function rankingIn(array $ranked, string $key): Ranking
    {
        return array_key_exists($key, $ranked) ? $ranked[$key]->ranking() : Ranking::none();
    }

    private function keyOf(Enclosing $function): string
    {
        return sprintf("%s\n%s", $function->file()->value(), $function->function());
    }
}
