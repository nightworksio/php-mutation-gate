<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

use function array_map;
use function file;
use function is_array;
use function is_file;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Native\Selected;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function realpath;

/**
 * What the gate told its Codeception extension (see Option): which tests to
 * run, and which files to write the run's coverage and listing to, where it
 * names them.
 *
 * @internal
 */
final readonly class Told
{
    private function __construct(private Node $settings)
    {
    }

    /** @param array<array-key, mixed> $settings the extension's settings, as Codeception hands them over */
    public static function from(array $settings): self
    {
        return new self(Node::decode(JsonText::compact($settings)));
    }

    public function selected(): Selected
    {
        $group = $this->text(Option::Group);
        $filter = $this->text(Option::Filter);
        $files = array_map(
            static fn(Node $file): string => self::real(Lenient::text($file)),
            Lenient::items($this->settings->field(Option::Files->value)),
        );

        return $this->listing() instanceof NotGiven ? Selected::narrowedTo(
            $this->tests(),
            $group instanceof NotGiven ? $group : Group::named($group),
            $filter instanceof NotGiven ? $filter : Filter::matching($filter),
            $files === [] ? NotGiven::value() : $files,
        ) : Selected::listing();
    }

    /**
     * The file to write the run's coverage to, or none where the run measures none.
     *
     * @return non-empty-string|NotGiven
     */
    public function coverage(): string|NotGiven
    {
        return $this->text(Option::Coverage);
    }

    /** The file to write the suite's tests to, running none, or none where the run is no listing. */
    public function listing(): string|NotGiven
    {
        return $this->text(Option::Listing);
    }

    /** The project's vendor directory, or none where the gate names none. */
    public function vendor(): string|NotGiven
    {
        return $this->text(Option::Vendor);
    }

    /** The tests the selection file lists, by id; none where it names no file. */
    private function tests(): TestIds|NotGiven
    {
        $file = $this->text(Option::Selected);

        return match (true) {
            $file instanceof NotGiven => $file,
            is_file($file) => $this->listedIn($file),
            default => TestIds::none(),
        };
    }

    /** The tests a file lists, by id, a line each. */
    private function listedIn(string $file): TestIds
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return TestIds::of(...array_map(TestId::of(...), is_array($lines) ? $lines : []));
    }

    /** A test file the gate named, by its real path, as Codeception names the file a test is declared in. */
    private static function real(string $file): string
    {
        $real = realpath($file);

        return $real === false ? $file : $real;
    }

    /**
     * An option the gate set as text, or none where it set none.
     *
     * @return non-empty-string|NotGiven
     */
    private function text(Option $option): string|NotGiven
    {
        $value = Lenient::text($this->settings->field($option->value));

        return $value === '' ? NotGiven::value() : $value;
    }
}
