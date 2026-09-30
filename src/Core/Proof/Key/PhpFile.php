<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_last;
use function array_values;
use function explode;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\Names;
use PhpToken;

use function preg_match_all;
use function str_replace;
use function trim;

/**
 * What one PHP file declares and what it names, read for a content key.
 *
 * A key follows the support a test uses by name, which is sound only for a
 * file that does nothing but declare: a file that runs something when it is
 * loaded acts on tests that never name it. So a file is read for whether it
 * only declares, the names it declares, and every name it mentions.
 *
 * A mention is read generously on purpose. Every identifier counts, and so
 * does every word in a string, with a hyphenated word also read joined up.
 * Reading too much puts a file in a key it did not need, which costs a run;
 * reading too little would leave one out, which would cost a verdict.
 */
final readonly class PhpFile
{
    /** The tokens that carry text rather than code. */
    private const array TEXT_TOKENS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];

    /** What a reading of the top level passes over. */
    private const array SILENT = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG];

    /** A word, or a run of words joined by hyphens. */
    private const string WORDS = '/[a-z0-9_]+(?:-[a-z0-9_]+)*/u';

    /**
     * @param list<string>        $declares every class, interface, trait, enum, function and constant it
     *                                      declares, lower-cased
     * @param array<string, string> $names    every name and word it mentions, lower-cased
     */
    private function __construct(private bool $onlyDeclares, private array $declares, private array $names)
    {
    }

    public static function read(Contents $source): self
    {
        $tokens = PhpToken::tokenize($source->text());
        $reading = Reading::start();

        foreach ($tokens as $token) {
            if ($reading->refused()) {
                break;
            }

            $reading = self::isSilent($token) ? $reading : $reading->then($token);
        }

        return new self(
            ! $reading->refused(),
            $reading->refused() ? [] : $reading->declares(),
            self::mentionedIn($tokens),
        );
    }

    /** Whether loading it runs nothing: every statement at its top level declares. */
    public function onlyDeclares(): bool
    {
        return $this->onlyDeclares;
    }

    /** @return list<string> */
    public function declares(): array
    {
        return $this->declares;
    }

    /** @return list<string> every name and word it mentions, lower-cased */
    public function names(): array
    {
        return array_values($this->names);
    }

    private static function isSilent(PhpToken $token): bool
    {
        return $token->is(self::SILENT) || ($token->is(T_INLINE_HTML) && trim($token->text) === '');
    }

    /**
     * @param  array<PhpToken>     $tokens
     * @return array<string, string>
     */
    private static function mentionedIn(array $tokens): array
    {
        $names = [];

        foreach ($tokens as $token) {
            if ($token->is(Names::TOKENS)) {
                $name = self::lastSegmentOf(mb_strtolower($token->text));
                $names[$name] = $name;

                continue;
            }

            $names += $token->is(self::TEXT_TOKENS) ? self::wordsIn(mb_strtolower($token->text)) : [];
        }

        return $names;
    }

    private static function lastSegmentOf(string $name): string
    {
        $segments = explode('\\', $name);

        return array_last($segments);
    }

    /**
     * Every word in some text, and every hyphenated run of words joined up.
     *
     * @return array<string, string>
     */
    private static function wordsIn(string $text): array
    {
        preg_match_all(self::WORDS, $text, $found);
        $words = [];

        foreach ($found[0] as $run) {
            $words += self::named(str_replace('-', '', $run));

            foreach (explode('-', $run) as $word) {
                $words += self::named($word);
            }
        }

        return $words;
    }

    /**
     * A name, keyed by itself, so that a name read twice is held once.
     *
     * @return array<string, string>
     */
    private static function named(string $name): array
    {
        return [$name => $name];
    }
}
