<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

/**
 * How much of a verdict the HTML report's page lists up front: the files
 * with survivors, `with.files`, and the survivors, `with.survivors`; the
 * page's pane and its files below show every one.
 *
 * @internal
 */
final readonly class PageShows
{
    /** The option of the `html` reporter that says how many files the page lists. */
    public const string FILES = 'files';

    /** The option of the `html` reporter that says how many survivors the page lists. */
    public const string SURVIVORS = 'survivors';

    private const int STANDARD_FILES = 8;

    private function __construct(private int $files, private int $survivors)
    {
    }

    /** Eight files and as many survivors as a digest names. */
    public static function standard(): self
    {
        return new self(self::STANDARD_FILES, Survivors::SHOWN);
    }

    public static function of(int $files, int $survivors): self
    {
        return new self($files, $survivors);
    }

    public function files(): int
    {
        return $this->files;
    }

    public function survivors(): int
    {
        return $this->survivors;
    }
}
