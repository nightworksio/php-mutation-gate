<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_map;
use function count;
use function dirname;
use function file_exists;
use function file_put_contents;
use function getenv;
use function hash;
use function intval;
use function is_dir;
use function is_string;
use function mkdir;

use NightWorksIO\MutationGate\Core\WholeNumber;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\Schema;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\Site;
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
    /** Where a file's schema is written, beside the folder of mutated copies, by the digest of its code. */
    private const string PATH = '%s/../schemata/%s.php';

    /** The digest a schema is named by. */
    private const string DIGEST = 'xxh3';

    /** The static method each schema's switches call. */
    private const string SWITCH = '\\%s::is';

    /** @var array<string, list<array{string, Site}>> each mutant's id and site, by the file it mutates */
    private static array $sites = [];

    /** @var array<string, array{string, int}> the schema and the switch's id of each mutant switched, by its id */
    private static array $switched = [];

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
        $schema = Schema::of($code, sprintf(self::SWITCH, SchemaSwitch::class), ...$sites);

        if ($schema->isEmpty()) {
            return;
        }

        $path = sprintf(self::PATH, $copies, hash(self::DIGEST, $schema->text()));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        file_put_contents($path, $schema->text());

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
        return array_key_exists($mutation->id, self::$switched)
            ? [
                GateVariable::Schema->value => self::$switched[$mutation->id][0],
                GateVariable::SchemaOn->value => sprintf('%d', self::$switched[$mutation->id][1]),
            ]
            : [];
    }

    /**
     * The file a mutant's own run serves in its file's place, its mutant put
     * in force: the schema it was told, where it was told one that is there,
     * and its own copy otherwise.
     */
    public static function inForce(string $copy): string
    {
        $schema = getenv(GateVariable::Schema->value);
        $on = getenv(GateVariable::SchemaOn->value);

        if (! is_string($schema) || ! file_exists($schema) || ! is_string($on) || ! WholeNumber::isPositive($on)) {
            return $copy;
        }

        SchemaSwitch::on(intval($on));

        return $schema;
    }
}
