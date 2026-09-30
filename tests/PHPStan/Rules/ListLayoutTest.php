<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\ListLayout;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;

/**
 * Every refusal the layout of the lists in a file earns.
 *
 * @return list<IdentifierRuleError&LineRuleError>
 */
function layoutErrors(string $code): array
{
    $layout = ListLayout::of($code);
    $nodes = new NodeFinder()->findInstanceOf(new ParserFactory()->createForHostVersion()->parse($code) ?? [], Node::class);

    return array_merge(...array_map($layout->judge(...), $nodes));
}

/**
 * Each refusal as the line it is reported on and what is wrong: `split`,
 * `indent` or `closer`.
 *
 * @return list<string>
 */
function layoutRefusals(string $code): array
{
    return array_map(
        static fn(IdentifierRuleError&LineRuleError $error): string => sprintf('%d %s', $error->getLine(), match (true) {
            str_contains($error->getMessage(), 'or split it') => 'split',
            str_contains($error->getMessage(), 'indent every item') => 'indent',
            default => 'closer',
        }),
        layoutErrors($code),
    );
}

/**
 * Each refusal's message.
 *
 * @return list<string>
 */
function layoutMessages(string $code): array
{
    return array_map(static fn(IdentifierRuleError $error): string => $error->getMessage(), layoutErrors($code));
}

describe('a list refused', function (): void {
    it('refuses a call whose last argument is a call spanning lines', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $added = $project->write($path, sprintf(
                '%s',
                $text,
            ));
            PHP))->toBe(['2 split']);
    });

    it('refuses a call whose first argument spans lines and whose last shares its closing line', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            return json_encode(array_map(
                static fn(int $shard): array => ['id' => $shard],
                $plan,
            ), JSON_THROW_ON_ERROR);
            PHP))->toBe(['2 split', '5 closer']);
    });

    it('refuses an arrow function whose body spans lines, since only a function closure may', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $rows = array_map(static fn(int $shard): array => [
                'id' => $shard,
            ], $plan);
            PHP))->toBe(['2 split', '4 closer']);
    });

    it('refuses a ternary whose last branch spans lines', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            return $reach->wholly($paths, $why === '' ? $users : Reason::that(
                sprintf('%s', $path),
            ));
            PHP))->toBe(['2 split']);
    });

    it('refuses a heredoc as the last argument, and the parenthesis after its marker', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            return sprintf($format, <<<'TXT'
                body
                TXT);
            PHP))->toBe(['2 split', '4 closer']);
    });

    it('refuses a constructor and a static call that hug their last argument', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $adapter = new Adapter($builtins, Section::fields(
                Field::required('use'),
            ));
            $field = Field::section('newCode', Section::fields(
                Field::setting('floor'),
            ));
            PHP))->toBe(['2 split', '5 split']);
    });

    it('refuses the call around a closure that spans lines, since only the closure may', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $label = implode('; ', array_map(static function (string $tree): string {
                return $tree;
            }, $named));
            PHP))->toBe(['2 split']);
    });

    it('refuses two items on one line of a split list', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $joined = implode(
                ',', $parts,
            );
            PHP))->toBe(['3 split']);
    });

    it('refuses an item a split list indents anywhere but one indent past its anchor', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $joined = implode(
                    ',',
                $parts,
            );
            PHP))->toBe(['3 indent']);
    });

    it('refuses an item on the line an array literal before it closes on, in a split list', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $merged = merge(
                [
                    'plan' => $digest,
                ], $rest,
            );
            PHP))->toBe(['3 split']);
    });

    it('measures a method\'s parameters from the line its name is on', function (): void {
        expect(layoutMessages(<<<'PHP'
            <?php
            final class Joiner
            {
                public function join(
                    string $glue,
                        array $parts,
                ): string {
                    return '';
                }
            }
            PHP))->toBe([
            'H9 — indent every item of this list 8 spaces, one indent past line 4, as SonarCloud\'s S1808 requires (H9).',
        ]);
    });

    it('refuses a closing parenthesis on the last item line', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $joined = implode(
                ',',
                $parts);
            PHP))->toBe(['4 closer']);
    });

    it('refuses a function, a method and a closure whose parameters are split wrongly', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            function joined(string $glue,
                array $parts): string
            {
                return '';
            }
            final class Joiner
            {
                #[Pure]
                public function join(string $glue,
                    array $parts): string
                {
                    return '';
                }
            }
            $join = static function (string $glue,
                array $parts): string {
                return '';
            };
            PHP))->toBe(['2 split', '3 closer', '10 split', '11 closer', '16 split', '17 closer']);
    });

    it('names the line to join on and the indent to split at', function (): void {
        expect(layoutMessages(<<<'PHP'
            <?php
            final class Writer
            {
                public function write(): void
                {
                    $added = $project->write($path, sprintf(
                        '%s',
                        $text,
                    ));
                }
            }
            PHP))->toBe([
            'H9 — put every item of this list on line 6, or split it: the first on the next line, one per line, indented 12 spaces. SonarCloud\'s S1808 refuses any other shape, and Pint lets this one through (H9).',
        ]);
    });

    it('measures a function\'s parameters from the line its own keyword is on', function (): void {
        expect(layoutMessages(<<<'PHP'
            <?php
            function joined(
                  string $glue,
                array $parts,
            ): string {
                return '';
            }
            final class Joiner
            {
                public function join(): void
                {
                }
            }
            PHP))->toBe([
            'H9 — indent every item of this list 4 spaces, one indent past line 2, as SonarCloud\'s S1808 requires (H9).',
        ]);
    });

    it('measures the indent from the first token on the line, past a comment', function (): void {
        expect(layoutMessages(<<<'PHP'
            <?php
            /* joined */ $joined = implode(
                ',',
                $parts,
            );
            PHP))->toBe([
            'H9 — indent every item of this list 17 spaces, one indent past line 2, as SonarCloud\'s S1808 requires (H9).',
        ]);
    });

    it('measures a chained call from the line its name is on', function (): void {
        expect(layoutMessages(<<<'PHP'
            <?php
            $command = $application
                ->register('init')
                ->setCode(
                        $one,
                    $two,
                );
            PHP))->toBe([
            'H9 — indent every item of this list 8 spaces, one indent past line 4, as SonarCloud\'s S1808 requires (H9).',
        ]);
    });
});

describe('a list let through', function (): void {
    it('lets a list on one line through', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $joined = implode(',', array_map(strval(...), $parts));
            PHP))->toBe([]);
    });

    it('lets a list through that splits fully, nested lists and all', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            final class Writer
            {
                public function write(
                    string $path,
                    string $text,
                ): mixed {
                    return $this->project->write(
                        Path::of($path),
                        Contents::of(
                            sprintf(
                                '%s',
                                $text,
                            ),
                        ),
                        named: true,
                    );
                }
            }
            PHP))->toBe([]);
    });

    it('lets one argument span lines, since a single item is no list', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $field = Field::section(Section::fields(
                Field::required('use'),
            ));
            PHP))->toBe([]);
    });

    it('lets an array literal span lines as the last argument', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $encoded = Json::encode($plan, [
                'plan' => $digest,
            ]);
            $long = merge($plan, array(
                'plan' => $digest,
            ));
            PHP))->toBe([]);
    });

    it('lets a function closure span lines, with the next argument on its closing line', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $labels = array_map(static function (string $tree): string {
                return $tree;
            }, $named);
            PHP))->toBe([]);
    });


    it('lets a first-class callable and an arrow function parameter list through', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $length = strlen(...);
            $sum = static fn(int $one,
                int $two): int => $one + $two;
            PHP))->toBe([]);
    });

    it('lets an anonymous class through, whose arguments SonarCloud does not read', function (): void {
        expect(layoutRefusals(<<<'PHP'
            <?php
            $reporter = new class($one, sprintf(
                '%s',
                $two,
            )) {
            };
            PHP))->toBe([]);
    });
});
