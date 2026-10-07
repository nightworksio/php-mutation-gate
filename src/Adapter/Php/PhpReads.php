<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use function array_values;
use function file_get_contents;
use function in_array;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Include_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

use function sprintf;

/**
 * What a `mutation-gate.php` reads beside itself, read from its code and
 * never run (ADR-0005, decision 4). A `require` or `include` of a literal
 * path, or of `__DIR__` and a literal, names that file wherever PHP looks for
 * it, and what that file reads is read in turn. An include of a path built
 * as it runs, or of one outside the project, names none, and nor does any
 * expression `Unnamed` says reaches what cannot be named.
 */
final readonly class PhpReads
{
    private const string UNNAMED
        = '%s reads what the gate cannot name without running it (%s on line %d), so every change reaches everything.';

    private const string UNPARSED = '%s is no PHP the gate can parse (%s), so every change reaches everything.';

    private const string BUILT = '%s of a path it builds';

    private const string OUTSIDE = '%s of %s, outside the project';

    private function __construct(private IncludePaths $paths, private Unnamed $unnamed)
    {
    }

    /** What a config file reads beside itself, from its code. */
    public static function of(ConfigFile $config): ConfigReads
    {
        $file = IncludePaths::real($config->file()->value());

        return new self(IncludePaths::in($config->project()->value()), new Unnamed())->read($file, [$file]);
    }

    /**
     * What a PHP file reads, the files it includes read in turn, none twice along one chain of includes.
     *
     * @param list<string> $seen the files on the chain that led here, this one among them
     */
    private function read(string $file, array $seen): ConfigReads
    {
        $code = is_file($file) ? file_get_contents($file) : false;
        $parsed = is_string($code) ? $this->parsed($code, $file) : [];

        return $parsed instanceof ConfigReads ? $parsed : $this->readIn($parsed, $file, $seen);
    }

    /**
     * A file's code parsed, every name resolved; or why it cannot be.
     *
     * @return list<Node>|ConfigReads
     */
    private function parsed(string $code, string $file): array|ConfigReads
    {
        try {
            $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error $error) {
            return ConfigReads::unnamed(sprintf(self::UNPARSED, $this->paths->shown($file), $error->getMessage()));
        }

        return array_values(new NodeTraverser(new NameResolver())->traverse($statements));
    }

    /**
     * What a file's statements read, together.
     *
     * @param list<Node>   $statements
     * @param list<string> $seen
     */
    private function readIn(array $statements, string $file, array $seen): ConfigReads
    {
        $reads = ConfigReads::none();

        foreach (new NodeFinder()->findInstanceOf($statements, Expr::class) as $expression) {
            $reads = $reads->and($this->readBy($expression, $file, $seen));
        }

        return $reads;
    }

    /**
     * What one expression of a file reads.
     *
     * @param list<string> $seen
     */
    private function readBy(Expr $expression, string $file, array $seen): ConfigReads
    {
        if ($expression instanceof Include_) {
            return $this->included($expression, $file, $seen);
        }

        $what = $this->unnamed->in($expression);

        return $what instanceof NotGiven ? ConfigReads::none() : $this->unnamedBy($file, $what, $expression);
    }

    /**
     * The files an include names, and what each reads; or why it names none.
     *
     * @param list<string> $seen
     */
    private function included(Include_ $include, string $file, array $seen): ConfigReads
    {
        $kind = IncludeKind::of($include);
        $written = $this->paths->literal($include->expr, $file);

        if ($written instanceof NotGiven) {
            return $this->unnamedBy($file, sprintf(self::BUILT, $kind->said()), $include);
        }

        $reads = ConfigReads::none();

        foreach ($this->paths->candidates($written[0], $file) as $candidate) {
            $inProject = $this->paths->inProject($candidate);
            $outside = sprintf(self::OUTSIDE, $kind->said(), $written[1]);
            $reads = $inProject instanceof NotGiven
                ? $reads->and($this->unnamedBy($file, $outside, $include))
                : $reads->and($this->namedIn($inProject, $candidate, $seen));
        }

        return $reads;
    }

    /**
     * A file an include names, and what it reads where the chain that led here has not read it yet.
     *
     * @param list<string> $seen
     */
    private function namedIn(string $inProject, string $candidate, array $seen): ConfigReads
    {
        $named = ConfigReads::named(Path::of($inProject));

        return in_array($candidate, $seen, strict: true)
            ? $named
            : $named->and($this->read($candidate, [...$seen, $candidate]));
    }

    private function unnamedBy(string $file, string $what, Node $node): ConfigReads
    {
        $said = sprintf(self::UNNAMED, $this->paths->shown($file), $what, $node->getStartLine());

        return ConfigReads::unnamed($said);
    }
}
