<?php

declare(strict_types=1);

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\NotWritten;
use Symfony\Component\Yaml\Yaml;

it('names the format of a config file by its extension', function (string $extension, Format|Absent $format): void {
    expect(Format::fromExtension($extension))->toEqual($format);
})->with([
    ['php', Format::Php],
    ['json', Format::Json],
    ['yaml', Format::Yaml],
    ['yml', Format::Yaml],
    ['neon', Format::Neon],
    ['toml', fn(): Absent => Absent::setting()],
    ['', fn(): Absent => Absent::setting()],
]);

it('looks for a config file by every name it has, in the order the formats are listed', function (): void {
    expect(Format::fileNames())->toBe([
        'mutation-gate.php',
        'mutation-gate.json',
        'mutation-gate.yaml',
        'mutation-gate.yml',
        'mutation-gate.neon',
    ]);
});

it('lists the words --format takes, in the order the formats are listed', function (): void {
    expect(Format::words())->toBe('php, json, yaml or neon');
});

it('says what each format is called, what init writes it to, and what reads it', function (
    Format $format,
    string $title,
    string $file,
    string $package,
    bool $suggested,
): void {
    expect([$format->title(), $format->fileName(), $format->package(), $format->isSuggested()])
        ->toBe([$title, $file, $package, $suggested]);
})->with([
    [Format::Php, 'PHP', 'mutation-gate.php', 'nightworksio/mutation-gate', false],
    [Format::Json, 'JSON', 'mutation-gate.json', 'nightworksio/mutation-gate', false],
    [Format::Yaml, 'YAML', 'mutation-gate.yaml', 'symfony/yaml', true],
    [Format::Neon, 'NEON', 'mutation-gate.neon', 'nette/neon', true],
]);

it('writes lines as a comment in each format that holds comments, and in JSON none', function (): void {
    $lines = ['Two lines:', '  of a comment'];

    expect(Format::Php->commented($lines))->toBe("\n// Two lines:\n//   of a comment\n")
        ->and(Format::Yaml->commented($lines))->toBe("\n# Two lines:\n#   of a comment\n")
        ->and(Format::Neon->commented($lines))->toBe("\n# Two lines:\n#   of a comment\n")
        ->and(Format::Json->commented($lines))->toEqual(NotWritten::because('JSON holds no comments.'));
});

/** Whether a config of `a: 1` that ends with this comment reads back as that alone, in YAML. */
function formatCommentLeavesYaml(string $comment): bool
{
    return Yaml::parse(sprintf("a: 1\n%s", $comment)) === ['a' => 1];
}

/** Whether a config of `a: 1` that ends with this comment reads back as that alone, in NEON. */
function formatCommentLeavesNeon(string $comment): bool
{
    return Neon::decode(sprintf("a: 1\n%s", $comment)) === ['a' => 1];
}

/** Whether PHP reads this comment as nothing but comments. */
function formatCommentLeavesPhp(string $comment): bool
{
    return array_all(
        token_get_all(sprintf("<?php\n%s", $comment)),
        static fn(mixed $token): bool => is_array($token)
            && in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_WHITESPACE], strict: true),
    );
}

it('keeps every line of a comment inside it, whatever a path in it holds', function (Format $format, callable $leaves): void {
    $hostile = "app'\"*/ \$x\n?><?php echo 'out'; // \r\u{2028}x: 1\u{85}y\u{202E}z";
    $commented = $format->commented(['Trees:', $hostile]);
    $text = is_string($commented) ? $commented : '';

    expect(substr_count($text, "\n"))->toBe(3)
        ->and($text)->not->toContain('?>')
        ->and($text)->toContain("app'\"*/ \$x? ><?php echo 'out'; // x: 1yz")
        ->and($leaves($text))->toBeTrue();
})->with([
    'php' => [Format::Php, 'formatCommentLeavesPhp'],
    'yaml' => [Format::Yaml, 'formatCommentLeavesYaml'],
    'neon' => [Format::Neon, 'formatCommentLeavesNeon'],
]);
