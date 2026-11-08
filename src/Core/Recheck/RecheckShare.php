<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Recheck;

use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;

/**
 * What one job of a shared re-check of the last run's survivors did
 * (ADR-0106): the files of survivors it ran again, the files its budget ran
 * out before, and every mutant its runs made. As
 * `.mutation-gate/survivors/<share>.json` holds it, `"format": 1`; a file that
 * cannot be read reached nothing, so its survivors read as not re-checked.
 *
 * @internal the shape of a survivor re-check share's file
 */
final readonly class RecheckShare
{
    private const int FORMAT = 1;

    private const string RAN = 'ran';

    private const string UNREACHED = 'unreached';

    private const string MUTANTS = 'mutants';

    /** @param list<Mutant> $mutants */
    private function __construct(private Paths $ran, private Paths $unreached, private array $mutants)
    {
    }

    /** A share that ran the survivors of these files again, made these mutants, and had no time for those files. */
    public static function of(Paths $ran, Paths $unreached, Mutant ...$mutants): self
    {
        return new self($ran, $unreached, array_values($mutants));
    }

    /** A share that reached nothing. */
    public static function none(): self
    {
        return new self(Paths::none(), Paths::none(), []);
    }

    /** The files of survivors it ran again. */
    public function ran(): Paths
    {
        return $this->ran;
    }

    /** The files of survivors its budget ran out before. */
    public function unreached(): Paths
    {
        return $this->unreached;
    }

    /** @return list<Mutant> every mutant its runs made */
    public function mutants(): array
    {
        return $this->mutants;
    }

    /** This share and another's, as one share that did what both did. */
    public function and(self $other): self
    {
        return new self(
            Paths::of(...$this->ran, ...$other->ran),
            Paths::of(...$this->unreached, ...$other->unreached),
            [...$this->mutants, ...$other->mutants],
        );
    }

    public function encode(): string
    {
        return JsonText::encode([
            'format' => self::FORMAT,
            self::RAN => $this->values($this->ran),
            self::UNREACHED => $this->values($this->unreached),
            self::MUTANTS => array_map(MutantRecord::full(...), $this->mutants),
        ]);
    }

    /** The share a file holds; one that reached nothing where it is of another format or not whole. */
    public static function decode(string $text): self
    {
        try {
            $file = Node::decode($text);

            return Lenient::integer($file->field('format')) === self::FORMAT
                ? new self(
                    self::pathsIn($file->field(self::RAN)),
                    self::pathsIn($file->field(self::UNREACHED)),
                    array_map(MutantRecord::readFull(...), $file->field(self::MUTANTS)->items()),
                )
                : self::none();
        } catch (NotInShape) {
            return self::none();
        }
    }

    /** @return list<string> */
    private function values(Paths $paths): array
    {
        return array_map(static fn(Path $path): string => $path->value(), [...$paths]);
    }

    /** @throws NotInShape */
    private static function pathsIn(Node $list): Paths
    {
        return Paths::of(...array_map(static fn(Node $path): Path => Path::of($path->text()), $list->items()));
    }
}
