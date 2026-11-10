<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What Outbreak's static graph answered (ADR-0132): for each file asked
 * about, the test files that reach it, the files those tests reach, and the
 * files it names, each none where the graph gives no answer; each test file
 * the coverage map shows the graph wrong of; and each file the graph cannot
 * place, with why. Where there is no graph, it answers nothing.
 *
 * @internal
 */
final readonly class GraphAnswer
{
    /**
     * @param array<string, Paths|NotGiven> $reaching the test files that reach each file asked about, by its path
     * @param array<string, Paths|NotGiven> $joined   the files the tests that reach each file reach, by its path
     * @param array<string, Paths|NotGiven> $named    the files each file named, by its path
     * @param array<string, string>         $unplaced why the graph cannot place each such file, by its path
     */
    private function __construct(
        private array $reaching,
        private array $joined,
        private array $named,
        private Paths $wrong,
        private array $unplaced,
    ) {
    }

    /**
     * @param array<string, Paths|NotGiven> $reaching
     * @param array<string, Paths|NotGiven> $joined
     * @param array<string, Paths|NotGiven> $named
     * @param array<string, string>         $unplaced
     */
    public static function of(array $reaching, array $joined, array $named, Paths $wrong, array $unplaced): self
    {
        return new self($reaching, $joined, $named, $wrong, $unplaced);
    }

    /** No answer to anything. */
    public static function none(): self
    {
        return new self([], [], [], Paths::none(), []);
    }

    /** The test files that reach a file; none where the graph gives no answer. */
    public function reaching(Path $file): Paths|NotGiven
    {
        return self::in($this->reaching, $file);
    }

    /** Every file the test files that reach a file reach; none where the graph gives no answer. */
    public function joined(Path $file): Paths|NotGiven
    {
        return self::in($this->joined, $file);
    }

    /** The files a file names; none where the graph gives no answer. */
    public function named(Path $file): Paths|NotGiven
    {
        return self::in($this->named, $file);
    }

    /** The test files the coverage map shows the graph wrong of. */
    public function wrong(): Paths
    {
        return $this->wrong;
    }

    /**
     * Why the graph cannot place each file it cannot, by its path.
     *
     * @return array<string, string>
     */
    public function unplaced(): array
    {
        return $this->unplaced;
    }

    /** @param array<string, Paths|NotGiven> $answers */
    private static function in(array $answers, Path $file): Paths|NotGiven
    {
        return array_key_exists($file->value(), $answers) ? $answers[$file->value()] : NotGiven::value();
    }
}
