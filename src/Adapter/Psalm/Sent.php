<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Psalm;

use function array_key_exists;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Analysis\FindingFiles;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * What one check sends Psalm's language server: the mutant's text in place
 * of its original, and each dependent the check lists that Psalm analyses
 * with its own text, so the server analyses those files again against the
 * mutant; and the original's text, sent back after. What the check finds is
 * what the server published of each file sent, and for every other file what
 * the run over the originals found.
 */
final readonly class Sent
{
    private const string NO_TEXT = '%s cannot be read, so Psalm cannot check it.';

    /**
     * @param array<string, string> $texts    each file's text sent, by its absolute path
     * @param string                $original the original's absolute path
     * @param string                $restored the original's own text
     */
    private function __construct(
        private array $texts,
        private string $original,
        private string $restored,
        private FindingFiles $files,
    ) {
    }

    /** What a check of a project at this root sends, under this config; or why a file it sends cannot be read. */
    public static function of(MutantCheck $check, Root $root, PsalmXml $xml): self|CannotJudge
    {
        $original = $root->at($check->original())->value();
        $restored = self::read($root, $check->original());
        $mutant = self::read($root, $check->mutant());

        if (! is_string($restored) || ! is_string($mutant)) {
            return is_string($restored) ? $mutant : $restored;
        }

        $texts = [$original => $mutant];

        foreach ($check->dependents() as $dependent) {
            $at = $root->at($dependent)->value();

            if (! $xml->holds($at)) {
                continue;
            }

            $text = self::read($root, $dependent);

            if (! is_string($text)) {
                return $text;
            }

            $texts[$at] = $text;
        }

        return new self($texts, $original, $restored, FindingFiles::under($root)->substituting($check));
    }

    /**
     * Whether the server published what it found in the mutant, which it
     * does where it analyses the original.
     *
     * @param array<string, Node> $published by URI
     */
    public function analysedIn(array $published): bool
    {
        return array_key_exists(Diagnostics::uriOf($this->original), $published);
    }

    /** @return array<string, string> each file's text sent, by its absolute path */
    public function texts(): array
    {
        return $this->texts;
    }

    /** @return array<string, string> the original's own text, sent back after the check, by its absolute path */
    public function restoring(): array
    {
        return [$this->original => $this->restored];
    }

    /**
     * What the check found: what the server published of each file sent,
     * and what the run over the originals found of every other file.
     *
     * @param array<string, Node> $published by URI
     */
    public function found(Findings $originals, array $published, Root $root): Findings
    {
        $findings = [];

        foreach ($originals as $finding) {
            if (! array_key_exists(Diagnostics::uriOf($root->at($finding->file())->value()), $published)) {
                $findings[] = $finding;
            }
        }

        foreach ($published as $diagnostics) {
            $findings = [...$findings, ...Diagnostics::of($diagnostics, $this->files)];
        }

        return Findings::of(...$findings);
    }

    private static function read(Root $root, Path $file): string|CannotJudge
    {
        $at = $root->at($file)->value();
        $text = is_file($at) ? file_get_contents($at) : false;

        return is_string($text) ? $text : CannotJudge::because(sprintf(self::NO_TEXT, $file->value()));
    }
}
