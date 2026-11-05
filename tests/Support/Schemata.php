<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_values;

use Closure;

use function file_put_contents;

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\Schema;
use NightWorksIO\MutationGate\Mutator\Engine\Schema\Site;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

use function sprintf;
use function var_export;

/** Schemata built from code the tests write, and what their code returns with a mutant switched on. */
final class Schemata
{
    /**
     * The schema of this code with the sites a closure names among its
     * nodes, read as both runners read a file: names resolved, parents set.
     *
     * @param Closure(Nodes): list<Site> $sites
     */
    public static function of(string $code, Closure $sites): Schema
    {
        $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
        new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor())->traverse($statements);

        return Schema::of($code, self::switch(), ...$sites(new Nodes(array_values(new NodeFinder()->findInstanceOf($statements, Node::class)))));
    }

    /** The static method a test's schemata switch by. */
    public static function switch(): string
    {
        return sprintf('\\%s::is', SchemaSwitch::class);
    }

    /**
     * What the schema's code returns with the mutant this id names in force,
     * and with none where it is 0, as var_export writes it.
     */
    public static function returned(Schema $schema, int $on): string
    {
        $file = sprintf('%s/schema-%d.php', Scratch::directory(), $on);
        file_put_contents($file, $schema->text());
        SchemaSwitch::on($on);

        try {
            return var_export(include $file, return: true);
        } finally {
            SchemaSwitch::off();
        }
    }
}
