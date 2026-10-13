<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Php\Tokens;
use NightWorksIO\MutationGate\Core\Report\MutantText;

use function rtrim;
use function sprintf;

/**
 * A test file a stub follows and is added to (ADR-0015, decisions 2 and 3):
 * a PHPUnit class, whose new test goes before the class's closing brace,
 * found by tokens; or Pest's closures, whose new test goes at the end.
 */
final readonly class TestFile
{
    private const string OPENING = "<?php\n\ndeclare(strict_types=1);\n\n";

    private const string CLASS_FILE = <<<'CLASS'
        %suse PHPUnit\Framework\TestCase;

        final class %s extends TestCase
        {
        %s
        }

        CLASS;

    private function __construct(private Path $path, private string $text, private int $closing)
    {
    }

    public static function read(Path $path, Contents $contents): self
    {
        $tokens = Tokens::in($contents);
        $closing = Tokens::NONE;

        foreach ($tokens->indicesOf(T_CLASS) as $class) {
            $named = $closing === Tokens::NONE && $tokens->is($class + 1, T_STRING);
            $closing = $named ? self::closingOf($tokens, $class) : $closing;
        }

        return new self($path, $contents->text(), $closing);
    }

    /** A new file of one test, in a style: a PHPUnit class named for the file, or Pest's closures. */
    public static function created(Path $path, string $test, AssertionStyle $style): string
    {
        return $style === AssertionStyle::Pest
            ? sprintf("%s%s\n", self::OPENING, $test)
            : sprintf(self::CLASS_FILE, self::OPENING, $path->stem(), self::indented($test));
    }

    public function path(): Path
    {
        return $this->path;
    }

    /** The style its tests are in: a PHPUnit class where it declares one, else Pest's closures. */
    public function style(): AssertionStyle
    {
        return $this->closing === Tokens::NONE ? AssertionStyle::Pest : AssertionStyle::PhpUnit;
    }

    /** Its text with a test added: a method before its class's closing brace, or a closure at its end. */
    public function with(string $test): string
    {
        if ($this->closing === Tokens::NONE) {
            return sprintf("%s\n\n%s\n", rtrim($this->text), $test);
        }

        return sprintf(
            "%s\n\n%s\n%s",
            rtrim(Bytes::slice($this->text, 0, $this->closing)),
            self::indented($test),
            Bytes::from($this->text, $this->closing),
        );
    }

    /** Where the body of the class a keyword declares closes, as a byte offset. */
    private static function closingOf(Tokens $tokens, int $class): int
    {
        $closing = Tokens::NONE;

        foreach ($tokens->inside($tokens->enclosing($class), '{') as $brace) {
            $closes = $closing === Tokens::NONE && $brace > $class && $tokens->is($tokens->closing($brace), '}');
            $closing = $closes ? $tokens->offset($tokens->closing($brace)) : $closing;
        }

        return $closing;
    }

    /** A test's lines indented as a class's members are, a blank line left blank. */
    private static function indented(string $test): string
    {
        $lines = [];

        foreach (explode("\n", $test) as $line) {
            $lines[] = $line === '' ? '' : sprintf('%s%s', MutantText::INDENT, $line);
        }

        return implode("\n", $lines);
    }
}
