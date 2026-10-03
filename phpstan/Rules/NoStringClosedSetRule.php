<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_any;
use function array_key_exists;
use function in_array;

use NightWorksIO\MutationGate\PHPStan\Rules\ClosedSets\StringSets;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_ends_with;

/**
 * D8 — a closed set of strings is an enum.
 *
 * A `match` with three or more string arms, or a class constant of three or
 * more strings that `in_array` or `array_key_exists` asks about, is a set of
 * names every caller has to spell right. An enum names the set once and lets the analyser check each
 * use. A file where an outside vocabulary is translated into the gate's own
 * enum is named in `vocabularyIn`, since the strings there are the outside
 * world's. A file whose set is still to become an enum is named in
 * `awaiting`, which only shrinks. It reads `src`.
 *
 * @implements Rule<Expr>
 */
final readonly class NoStringClosedSetRule implements Rule
{
    /** Two strings are a pair of words; three begin a set. */
    private const int SMALLEST_SET = 3;

    /** The lookups that ask a constant array whether it holds a string: among its values, or among its keys. */
    private const string AMONG_VALUES = 'in_array';

    private const string AMONG_KEYS = 'array_key_exists';

    /**
     * @param list<string> $vocabularyIn files, relative to the repository, that translate an outside vocabulary
     * @param list<string> $awaiting     files, relative to the repository, whose closed set is still to become an enum
     */
    public function __construct(private array $vocabularyIn = [], private array $awaiting = [])
    {
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! OwnSource::holds($scope->getFile()) || $this->translatesAVocabulary($scope)) {
            return [];
        }

        $what = match (true) {
            $node instanceof Match_ => StringSets::inMatch($node) >= self::SMALLEST_SET ? 'this match' : '',
            default => $this->closedSetLookedUp($node, $scope),
        };

        if ($what === '') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'D8 — %s treats a closed set of strings as its values. Make the set an enum and match on its cases, so every use is checked (D4, D8).',
                $what,
            ))
                ->identifier('mutationGate.closedSetAsStrings')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function translatesAVocabulary(Scope $scope): bool
    {
        return array_any(
            [...$this->vocabularyIn, ...$this->awaiting],
            static fn(string $file): bool => str_ends_with($scope->getFile(), sprintf('/%s', $file)),
        );
    }

    /** The class constant a lookup asks about, where it holds a closed set of strings, or nothing. */
    private function closedSetLookedUp(Node $node, Scope $scope): string
    {
        if (! $node instanceof FuncCall || ! $node->name instanceof Name || $node->isFirstClassCallable()) {
            return '';
        }

        $arguments = $node->getArgs();
        $function = $node->name->toLowerString();
        $set = array_key_exists(1, $arguments) ? $arguments[1]->value : $node;

        if (! $set instanceof ClassConstFetch || ! in_array($function, [self::AMONG_VALUES, self::AMONG_KEYS], strict: true)) {
            return '';
        }

        $held = $function === self::AMONG_VALUES
            ? StringSets::amongValues($scope->getType($set))
            : StringSets::amongKeys($scope->getType($set));

        return $held >= self::SMALLEST_SET ? sprintf('%s over %s', $function, $this->nameOf($set)) : '';
    }

    private function nameOf(ClassConstFetch $fetch): string
    {
        $class = $fetch->class instanceof Name ? $fetch->class->toString() : 'the class';
        $name = $fetch->name instanceof Identifier ? $fetch->name->toString() : 'a constant';

        return sprintf('%s::%s', $class, $name);
    }
}
