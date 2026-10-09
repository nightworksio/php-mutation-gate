<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Migration\Migrated;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Retired;
use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

use function sprintf;

/**
 * A PHP config's migration (ADR-0026, decision 3): parsed, never run. The
 * links a removal takes out of the chain are cut from the text first (see
 * ChainCuts); each other call a step retires is then rewritten through
 * php-parser's format-preserving printer, which keeps every comment and the
 * layout.
 */
final readonly class PhpMigration
{
    private const string UNPARSABLE = '%s cannot be migrated: it is not PHP the gate can parse (%s).';

    private const string PENDING = '%s line %d: %s';

    /** The config as it would be written, with the calls left for a hand edit; or why it cannot be read. */
    public static function of(string $file, string $code, Migrations $migrations): Migrated|CannotJudge
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $errors = new Collecting();
        $original = $parser->parse($code, $errors) ?? [];

        if ($errors->hasErrors()) {
            return CannotJudge::because(sprintf(self::UNPARSABLE, $file, $errors->getErrors()[0]->getMessage()));
        }

        $resolved = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]))->traverse($original);
        $cut = ChainCuts::made($code, $resolved, $migrations);
        $statements = $parser->parse($cut) ?? [];
        $tree = new NodeTraverser(new CloningVisitor())->traverse($statements);
        $resolving = new NameResolver(options: ['replaceNodes' => false]);
        new NodeTraverser($resolving, new ParentConnectingVisitor())->traverse($tree);
        $respelling = new Respelling($migrations);
        $migrated = new NodeTraverser($respelling)->traverse($tree);
        $printed = new Standard()->printFormatPreserving($migrated, $statements, $parser->getTokens());

        return Migrated::of($file, $code, $printed, $respelling->left());
    }

    /**
     * Why a config that cannot load still calls what a release retired: the
     * first such call, at its line, naming `migrate`; nothing where it calls
     * none, or cannot be parsed (ADR-0026, decision 1).
     */
    public static function pending(string $file, string $code, Migrations $migrations): string|NotGiven
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $statements = $parser->parse($code, new Collecting()) ?? [];
        $resolved = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]))->traverse($statements);

        $finder = new NodeFinder();
        $calls = [
            ...$finder->findInstanceOf($resolved, StaticCall::class),
            ...$finder->findInstanceOf($resolved, MethodCall::class),
        ];
        $first = NotGiven::value();
        $at = 0;

        foreach ($calls as $call) {
            $retired = RetiredCall::of($call, $migrations);
            $named = $call->name->getStartFilePos();

            if ($retired instanceof Retired && ($first instanceof NotGiven || $named < $at)) {
                [$first, $at] = [sprintf(self::PENDING, $file, RetiredCall::line($call), $retired->pending()), $named];
            }
        }

        return $first;
    }
}
