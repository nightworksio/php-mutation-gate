<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function addslashes;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function intdiv;
use function json_encode;
use function max;
use function mb_strcut;
use function mb_stripos;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

use function preg_match;
use function rawurlencode;
use function sodium_bin2base64;
use function sodium_bin2hex;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strtr;
use function trim;
use function urlencode;

/**
 * Values that must never reach a file or a log, such as the CI's tokens: the
 * values of the variables the gate withholds from every process, those long
 * enough not to be a word the text holds anyway. Text from a process is
 * screened for them whole, never redacted in part: where any of them, in any
 * form a test prints a value in, or anything shaped like a credential,
 * appears in it, none of it is kept (ADR-0014, decision 16). The forms are
 * the value trimmed, and each of its lines; and each of those
 * URL-encoded, form-encoded, hex-encoded, JSON-escaped, backslash-escaped,
 * shell-escaped, and base64-encoded in the standard and the URL alphabet at
 * each of the three alignments a value takes inside a longer string. They are
 * found whatever their case.
 */
final readonly class Secrets
{
    /** The fewest characters a withheld variable's value has to be screened for as a secret, and a form of one. */
    private const int SHORTEST = 8;

    /** How many bytes base64 turns into one group of characters. */
    private const int GROUP_BYTES = 3;

    /** How many characters base64 writes one group of bytes as. */
    private const int GROUP_CHARACTERS = 4;

    /**
     * What a credential looks like whoever issued it, withheld or not, such as
     * one a test reads from a file or prints from a fixture: a private key, a
     * GitHub, AWS, Slack or GitLab token, the token actions/checkout keeps in
     * the repository's config, an authorization header, and a service
     * account's key file.
     */
    private const array SHAPES = [
        '/PRIVATE KEY-----/i',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/x-access-token/i',
        '/authorization:\s*(?:basic|bearer|token)\s+\S/i',
        '/"private_key"\s*:/',
        '/\bxox[abposr]-/',
        '/\bglpat-[A-Za-z0-9_-]{20,}/',
    ];

    /** @param list<string> $forms every form of every secret, as text a terminal shows safely */
    private function __construct(private array $forms)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(string ...$values): self
    {
        $forms = [];

        foreach ($values as $value) {
            $forms = [...$forms, ...self::formsOf($value)];
        }

        return new self(array_values(array_unique(array_filter(
            array_map(Printable::text(...), $forms),
            static fn(string $form): bool => mb_strlen($form) >= self::SHORTEST,
        ))));
    }

    /** The values of the variables these withhold, each of at least the fewest characters a secret has. */
    public static function withheldIn(Variables $variables, Withheld $withheld): self
    {
        return self::of(...array_values(array_filter(
            $variables->matching($withheld->pattern()),
            static fn(string $value): bool => mb_strlen(trim($value)) >= self::SHORTEST,
        )));
    }

    /**
     * What a process printed, as text a terminal shows safely, where no form
     * of a secret and nothing shaped like a credential is in it; and nothing
     * where one is. Where it was cut from what the process printed, its start
     * may hold the end of a secret no form matches whole, so as many bytes as
     * the longest form holds are dropped from its start first, before any
     * control character goes, which could otherwise bring that end within
     * the tail.
     */
    public function screened(string $printed, bool $cut): string|NotGiven
    {
        $text = Printable::text($cut ? $this->afterTheLongest($printed) : $printed);

        foreach ($this->forms as $form) {
            if (mb_stripos($text, $form, 0, 'UTF-8') !== false) {
                return NotGiven::value();
            }
        }

        foreach (self::SHAPES as $shape) {
            if (preg_match($shape, $text) === 1) {
                return NotGiven::value();
            }
        }

        return $text;
    }

    /** @return list<string> */
    private static function formsOf(string $value): array
    {
        $trimmed = trim($value);
        $plain = [$trimmed, ...array_map(trim(...), explode("\n", $trimmed))];
        $forms = [];

        foreach (array_unique($plain) as $one) {
            $forms = [
                ...$forms,
                $one,
                rawurlencode($one),
                urlencode($one),
                sodium_bin2hex($one),
                addslashes($one),
                str_replace("'", "'\\''", $one),
                ...self::jsonOf($one),
                ...self::base64Of($one),
            ];
        }

        return $forms;
    }

    /**
     * A value as JSON writes it in a string, its slashes and its characters
     * past ASCII escaped or left as they are, each way.
     *
     * @return list<string>
     */
    private static function jsonOf(string $value): array
    {
        return array_map(
            static fn(int $flags): string
                => mb_substr((string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | $flags), 1, -1),
            [0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE],
        );
    }

    /**
     * The characters base64 gives a value's bytes alone, at each of the three
     * alignments it can start at inside a longer string, in the standard and
     * the URL alphabet: the groups its bytes share with no other byte.
     *
     * @return list<string>
     */
    private static function base64Of(string $value): array
    {
        $forms = [];

        foreach ([0, 1, 2] as $before) {
            $aligned = sprintf('%s%s', str_repeat("\0", $before), $value);
            $encoded = sodium_bin2base64($aligned, SODIUM_BASE64_VARIANT_ORIGINAL);
            $whole = intdiv($before + Bytes::length($value), self::GROUP_BYTES) * self::GROUP_CHARACTERS;
            $from = $before === 0 ? 0 : self::GROUP_CHARACTERS;
            $alone = mb_substr($encoded, $from, max(0, $whole - $from));
            $forms = [...$forms, $alone, strtr($alone, '+/', '-_')];
        }

        return $forms;
    }

    /**
     * How many bytes the longest form holds, none where there is no secret:
     * at least twice what the value holds, which its hex form does, however
     * many control characters it held.
     */
    private function longest(): int
    {
        return max([0, ...array_map(Bytes::length(...), $this->forms)]);
    }

    /**
     * The text after as many bytes as the longest form holds, from the first
     * character that starts at or past them.
     */
    private function afterTheLongest(string $text): string
    {
        $start = $this->longest();
        $most = max(0, Bytes::length($text) - $start);
        $rest = mb_strcut($text, $start, null, 'UTF-8');

        while (Bytes::length($rest) > $most) {
            $rest = mb_strcut($text, ++$start, null, 'UTF-8');
        }

        return $rest;
    }
}
