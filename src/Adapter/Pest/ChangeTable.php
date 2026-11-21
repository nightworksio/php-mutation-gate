<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_shift;
use function basename;

use Closure;
use Composer\InstalledVersions;

use function file_get_contents;
use function getenv;
use function hash;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use Pest\Mutate\Mutation;

use function sprintf;
use function strval;

use Symfony\Component\Finder\SplFileInfo;

/**
 * The plan's table of where each mutant stands among the mutants of its whole
 * file that make its change (ADR-0306), made once as the plan is made (see
 * ChangeTableMade) and kept beside the plan for every shard. A part of a cut
 * run that makes some lines of a file places each mutant it made by the
 * table, where the table holds the file's code as it is on disk, was made by
 * the same Pest, and holds every mutant the part made; otherwise the part
 * makes the whole file itself and says so (see UnplacedFiles). A file is held
 * by the digest of its code, a mutant by its mutator, the name of its mutated
 * copy, which Pest takes from the copy's code, and its lines.
 *
 * @internal
 */
final readonly class ChangeTable
{
    /** The table's file, in the gate's directory beside the plan. */
    public const string FILE = 'change-places.json';

    /** The member that names the table's shape. */
    public const string SHAPE = 'changePlaces';

    /** The table's shape, as this release writes it. */
    public const int CURRENT = 1;

    public const string MADE_BY = 'madeBy';

    public const string MUTATORS = 'mutatorClasses';

    public const string FILES = 'byDigest';

    /** What separates a file's entries, and the packages the table was made by. */
    public const string BETWEEN = ' ';

    /** A mutant's spot: its mutator's number in the table, its copy's name, its first and last line. */
    public const string SPOT = '%d|%s|%d|%d';

    /** An entry as the table holds it: a spot and its place. */
    public const string PLACED = '%s|%d';

    /** The packages whose code makes a file's mutants and their copies: a table another install made is not read. */
    private const array MAKERS = [Package::PestMutate->value, 'nikic/php-parser'];

    /**
     * @param array<string, int>    $mutators each mutator's number in a spot, by its class
     * @param array<string, string> $files    each file's entries, by the digest of its code
     */
    private function __construct(private array $mutators, private array $files)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** The table a part of a cut run places its mutants by, where the run is cut; none where it is not. */
    public static function forCut(bool $cut): self|NotGiven
    {
        return $cut ? self::told() : NotGiven::value();
    }

    /**
     * The table this run is told of, where it was made by the Pest this run
     * makes its mutants with; none where it is told of none or the table is
     * not one of these.
     */
    public static function told(): self
    {
        $file = strval(getenv(GateVariable::Places->value));

        return $file === '' ? self::none() : self::read($file, self::makers());
    }

    /** The table in a file, read as the gate reads it, whoever made it. */
    public static function in(string $file): self
    {
        return self::read($file, '');
    }

    /**
     * What a run is told of the plan's table: where it is, where the plan left
     * one in the gate's directory; nothing otherwise.
     *
     * @return array<string, string>
     */
    public static function variables(Project $project): array
    {
        $file = sprintf('%s/%s', $project->workspace(), self::FILE);

        return is_file($file) ? [GateVariable::Places->value => $file] : [];
    }

    /**
     * Places each of these mutants of a file at its place in the table, where
     * the table holds the file as it is and every one of them; or else among
     * the mutants of the whole file, made apart, and notes the file.
     *
     * @param list<Mutation>                  $made
     * @param Closure(): array<int, Mutation> $whole the whole file's mutants
     */
    public function place(SplFileInfo $file, array $made, Closure $whole): void
    {
        $places = $this->placesOf($file, $made);

        if ($places === [] && $made !== []) {
            UnplacedFiles::noted($file);
            ChangePlaces::among($made, $whole());

            return;
        }

        foreach ($made as $at => $mutant) {
            ChangePlaces::placing($mutant, $places[$at]);
        }
    }

    /**
     * Where each mutant the table holds of each of these files starts, by
     * the file as the project spells it; none of a file it does not hold.
     */
    public function sitesOf(Project $project, Paths $files): MutantSites
    {
        $sites = MutantSites::none();

        foreach ($files as $path) {
            $digest = self::digestOf($project->absolute($path));
            $sites = array_key_exists($digest, $this->files)
                ? $sites->and(MutantSites::inFile($path, ...ChangeEntries::of($this->files[$digest])->starts()))
                : $sites;
        }

        return $sites;
    }

    /** A mutant's spot, its mutator numbered as a table numbers it. */
    public static function spotOf(Mutation $mutant, int $mutator): string
    {
        return sprintf(
            self::SPOT,
            $mutator,
            basename($mutant->modifiedSourcePath),
            $mutant->startLine,
            $mutant->endLine,
        );
    }

    /** The digest of a file's code as it is on disk; '' where it cannot be read. */
    public static function digestOf(string $file): string
    {
        $code = is_file($file) ? file_get_contents($file) : false;

        return is_string($code) ? hash('sha256', $code) : '';
    }

    /**
     * The packages that make a file's mutants, each at the commit it is
     * installed from, by name alone where it is not installed.
     *
     * @param list<string> $packages
     */
    public static function makers(array $packages = self::MAKERS): string
    {
        $makers = [];

        foreach ($packages as $package) {
            $makers[] = InstalledVersions::isInstalled($package)
                ? sprintf('%s@%s', $package, strval(InstalledVersions::getReference($package)))
                : $package;
        }

        return implode(self::BETWEEN, $makers);
    }

    /**
     * Each of these mutants' place, where the table holds their file as it is
     * and every one of them; none otherwise.
     *
     * @param  list<Mutation>  $made
     * @return array<int, int>
     */
    private function placesOf(SplFileInfo $file, array $made): array
    {
        $digest = self::digestOf(strval($file->getRealPath()));
        $free = array_key_exists($digest, $this->files) ? ChangeEntries::of($this->files[$digest])->free() : [];
        $places = [];

        foreach ($made as $at => $mutant) {
            $known = array_key_exists($mutant->mutator, $this->mutators);
            $spot = $known ? self::spotOf($mutant, $this->mutators[$mutant->mutator]) : '';
            $place = array_key_exists($spot, $free) ? array_shift($free[$spot]) : null;

            if ($place === null) {
                return [];
            }

            $places[$at] = $place;
        }

        return $places;
    }

    /** The table a decoded file holds. */
    private static function held(Node $table): self
    {
        $mutators = [];
        $files = [];

        foreach ($table->field(self::MUTATORS)->items() as $number => $mutator) {
            $mutators[$mutator->text()] = $number;
        }

        foreach ($table->field(self::FILES)->entries() as $digest => $entries) {
            $files[strval($digest)] = $entries->text();
        }

        return new self($mutators, $files);
    }

    private static function read(string $file, string $madeBy): self
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        try {
            $table = is_string($text) ? Node::decode($text) : null;

            return ! $table instanceof Node
                || $table->field(self::SHAPE)->integer() !== self::CURRENT
                || ($madeBy !== '' && $table->field(self::MADE_BY)->text() !== $madeBy)
                ? self::none()
                : self::held($table);
        } catch (NotInShape) {
            return self::none();
        }
    }
}
