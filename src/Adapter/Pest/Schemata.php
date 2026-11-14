<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_map;
use function count;
use function dirname;
use function file_put_contents;
use function getenv;
use function hash;
use function is_dir;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WholeFile;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\Schema;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\Site;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\SwitchCall;
use Pest\Mutate\Mutation;
use PhpParser\Node;
use PhpParser\NodeVisitor;

use function sprintf;
use function strval;

/**
 * Each file's mutants compiled into one schema in Pest's parent process,
 * where the gate asks for schemata (ADR-0091): the patched generation loop
 * hands each mutant's node and what replaces it as Pest makes it, and the
 * file's schema is written once the file's mutants are made, beside the
 * folder of mutated copies, named by a digest of its code so no run is
 * served another's. A mutant's own run is told its file's schema and its
 * switch's id, serves the schema in its file's place and puts the mutant in
 * force; a mutant the schema does not switch keeps its own copy.
 *
 * @internal
 */
final class Schemata
{
    /** Where a file's schema is written, beside the folder of mutated copies, by the file's key. */
    private const string PATH = '%s/../schemata/%s%s';

    /** Where every schema is written, from the vendor directory: beside pest-plugin-mutate's `.temp/mutations`. */
    private const string IN_VENDOR = '%s/.temp/schemata';

    /** The digest of a file's real path that keys its schema's switch and names the schema. */
    private const string DIGEST = 'xxh3';

    /** The call each switch of a file's schema makes, by the file's key, `%d` where it takes the id. */
    private const string SWITCH = "\\%s::is('%s', %%d)";

    /**
     * The call a pure expression's site makes in a split stream, by the file's
     * key (ADR-0117): `%1$d` where it takes the id, `%2$s` the original and
     * `%3$s` the mutated code.
     */
    private const string VALUED = "\\%s::value('%s', %%1\$d, fn () => (%%2\$s), fn () => (%%3\$s))";

    /** The file in a run's warm directory that names each file's schema, by the file (ADR-0091). */
    private const string MAP = '%s/schemata.json';

    /** @var array<string, list<array{string, Site}>> each mutant's id and site, by the file it mutates */
    private static array $sites = [];

    /** @var array<string, array{string, int}> the schema and the switch's id of each mutant switched, by its id */
    private static array $switched = [];

    /** @var array<string, string> each file's schema, by the file's real path */
    private static array $written = [];

    /** Whether the gate asks this run for schemata. */
    public static function areOn(): bool
    {
        return getenv(GateVariable::Schemata->value) === '1';
    }

    /** Keeps a mutant Pest made, by the node it changed and what replaced it, false where it removed it. */
    public static function sited(Mutation $mutation, Node $original, Node|false $replacement): void
    {
        $file = strval($mutation->file->getRealPath());
        $id = array_key_exists($file, self::$sites) ? count(self::$sites[$file]) + 1 : 1;
        self::$sites[$file][] = [
            $mutation->id,
            Site::of($original, $replacement instanceof Node ? $replacement : NodeVisitor::REMOVE_NODE, $id),
        ];
    }

    /** Writes the schema of a file's mutants, made from this code, where it switches any; and forgets them. */
    public static function written(string $file, string $code, string $copies): void
    {
        $kept = array_key_exists($file, self::$sites) ? self::$sites[$file] : [];
        unset(self::$sites[$file]);
        $sites = array_map(static fn(array $kept): Site => $kept[1], $kept);
        $key = hash(self::DIGEST, $file);
        $switch = SwitchCall::of(sprintf(self::SWITCH, SchemaSwitch::class, $key));
        $streams = StreamSettings::fromEnvironment();
        $schema = Schema::of(
            $code,
            $streams->isOn() && $streams->checksChanges()
                ? $switch->valuing(sprintf(self::VALUED, SchemaSwitch::class, $key))
                : $switch,
            ...$sites,
        );

        if ($schema->isEmpty()) {
            return;
        }

        $path = sprintf(self::PATH, $copies, $key, Path::PHP);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        file_put_contents($path, $schema->text());
        self::$written[$file] = $path;

        foreach ($kept as [$mutant, $site]) {
            if ($schema->switches($site->id())) {
                self::$switched[$mutant] = [$path, $site->id()];
            }
        }
    }

    /**
     * What a mutant's own run is told to serve its file's schema with: the
     * schema and its switch's id; nothing where its file has none, or the
     * schema does not switch it.
     *
     * @return array<string, string>
     */
    public static function variablesOf(Mutation $mutation): array
    {
        return array_key_exists($mutation->id, self::$switched) && ! Bridged::isPinnedBySource($mutation->mutator)
            ? [
                GateVariable::Schema->value => self::$switched[$mutation->id][0],
                GateVariable::SchemaOn->value => sprintf('%d', self::$switched[$mutation->id][1]),
            ]
            : [];
    }

    /**
     * The key of a mutant's file and its switch's id, where its file's schema
     * switches it and its mutator is not pinned by source; nothing otherwise.
     *
     * @return array{string, int}|NotGiven
     */
    public static function switchOf(Mutation $mutation): array|NotGiven
    {
        return array_key_exists($mutation->id, self::$switched) && ! Bridged::isPinnedBySource($mutation->mutator)
            ? [hash(self::DIGEST, strval($mutation->file->getRealPath())), self::$switched[$mutation->id][1]]
            : NotGiven::value();
    }

    /** The directory every schema is written to, and served from, by its path from the vendor directory. */
    public static function inVendor(): Path
    {
        return Path::of(sprintf(self::IN_VENDOR, Package::PestMutate->value));
    }

    /** Leaves each file's schema, by the file, in a run's warm directory, for its workers to serve as they boot. */
    public static function leftIn(string $directory): void
    {
        $named = [];

        foreach (self::$written as $file => $schema) {
            $named[] = Member::of($file, $schema);
        }

        WholeFile::written(sprintf(self::MAP, $directory), Json::object(...$named)->line());
    }

    /**
     * Serves each file's schema a run left in this warm directory in the
     * file's place, as a mutants' worker does before it boots the project:
     * a file whose classes Composer loads is served its schema by an
     * autoloader, and any other is left to each child.
     */
    public static function servedIn(string $directory): void
    {
        foreach (self::schemataIn($directory) as [$file, $schema]) {
            ServedClasses::serve($file, $schema);
        }
    }

    /**
     * Each file a run left a schema for in this warm directory, with the
     * schema; none where it left none, or left them in a shape it cannot read.
     *
     * @return list<array{string, string}>
     */
    private static function schemataIn(string $directory): array
    {
        $map = WholeFile::read(sprintf(self::MAP, $directory));

        try {
            $schemata = [];

            foreach ($map instanceof NotGiven ? [] : $map->entries() as $file => $schema) {
                $schemata[] = [sprintf('%s', $file), $schema->text()];
            }

            return $schemata;
        } catch (NotInShape) {
            return [];
        }
    }
}
