<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function in_array;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Opcache;

use function sprintf;

/**
 * What a mutant's run says of itself in the guard file (ADR-0004): the
 * wrapper, in any process of the run, that it served the mutated file,
 * and the framework's recorder that opcache could have served a cached
 * original in its place. A run that served no mutated file, or could have
 * served a cached original, says nothing of the mutant.
 */
final readonly class Guard
{
    /** What the wrapper writes once it serves the mutated file. */
    public const string SERVED = 'served';

    /** What the recorder writes where opcache could serve a cached original. */
    private const string CACHED = 'cached';

    private function __construct(private bool $served, private bool $cached)
    {
    }

    /**
     * What a guard file's lines say; no line says nothing was served.
     *
     * @param list<string> $lines
     */
    public static function of(array $lines): self
    {
        return new self(in_array(self::SERVED, $lines, strict: true), in_array(self::CACHED, $lines, strict: true));
    }

    /** The line the recorder writes where opcache could serve a cached original; none where it could not. */
    public static function noting(Opcache $opcache): string|NotGiven
    {
        return $opcache->couldServeTheOriginal() ? sprintf("%s\n", self::CACHED) : NotGiven::value();
    }

    /** Whether the wrapper served the mutated file. */
    public function served(): bool
    {
        return $this->served;
    }

    /** Whether opcache could have served a cached original in its place. */
    public function cached(): bool
    {
        return $this->cached;
    }
}
