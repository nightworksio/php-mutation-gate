<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_flip;
use function array_intersect_key;
use function in_array;

use NightWorksIO\MutationGate\Core\Format\JsonObject;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;

use function sprintf;
use function str_starts_with;

/**
 * The `mutators` block of the project's Infection config, as it was read:
 * which mutators and profiles it turns on, each mutator's settings, and the
 * patterns that hide mutants.
 */
final readonly class MutatorSettings
{
    private const string IGNORE = 'ignore';

    private const string REGEX = 'ignoreSourceCodeByRegex';

    private const string GLOBAL_IGNORE = 'global-ignore';

    private const string GLOBAL_REGEX = 'global-ignoreSourceCodeByRegex';

    private const array GLOBAL = [self::GLOBAL_IGNORE, self::GLOBAL_REGEX];

    /** A mutator turned on with no settings, where the block names it bare or does not name it. */
    private const string ON = 'true';

    /** What an empty object reads back as, which the block writes as the object it was. */
    private const string EMPTY_LIST = '[]';

    private function __construct(private Node $block)
    {
    }

    public static function of(Node $block): self
    {
        return new self($block);
    }

    /**
     * Every value of `ignore` or `ignoreSourceCodeByRegex` under `mutators`:
     * the key it is under, whether it is a pattern over source rather than
     * over names, the mutator it applies to, none where it applies to more
     * than one, and the pattern.
     *
     * @return list<array{key: string, regex: bool, mutator: string, pattern: string}>
     */
    public function patterns(): array
    {
        $patterns = [];

        foreach ($this->entriesOf($this->block) as $name => $settings) {
            $mutator = str_starts_with($name, '@') ? '' : $name;
            $patterns = [...$patterns, ...match ($name) {
                self::GLOBAL_IGNORE => $this->listed($name, '', $settings, regex: false),
                self::GLOBAL_REGEX => $this->listed($name, '', $settings, regex: true),
                default => [
                    ...$this->under($name, $mutator, $settings, self::IGNORE),
                    ...$this->under($name, $mutator, $settings, self::REGEX),
                ],
            }];
        }

        return $patterns;
    }

    /**
     * The JSON of the `mutators` block the gate writes, by its key: the whole
     * block for a run of every mutator, none where the project has none; for
     * a run that names mutators, only those, with the project's settings for
     * each, and its global ignores.
     *
     * @return array<string, string>
     */
    public function narrowedTo(Mutators $mutators): array
    {
        $own = $this->entriesOf($this->block);
        $kept = $mutators->isAll() ? $this->all($own) : $this->named($own, $mutators);

        return $kept === [] ? [] : ['mutators' => JsonObject::of($kept)];
    }

    /**
     * @param array<string, Node> $own
     *
     * @return array<string, string>
     */
    private function all(array $own): array
    {
        $kept = [];

        foreach ($own as $name => $settings) {
            $kept[$name] = $this->written($name, $settings);
        }

        return $kept;
    }

    /**
     * The global ignores, and each named mutator: with the project's settings
     * for it where it has some, and turned on bare where it has none.
     *
     * @param array<string, Node> $own
     *
     * @return array<string, string>
     */
    private function named(array $own, Mutators $mutators): array
    {
        $kept = array_intersect_key($this->all($own), array_flip(self::GLOBAL));

        foreach ($mutators as $mutator) {
            $kept[$mutator] = self::ON;
        }

        foreach (array_intersect_key($own, $kept) as $name => $settings) {
            if ($this->entriesOf($settings) !== []) {
                $kept[$name] = $this->written($name, $settings);
            }
        }

        return $kept;
    }

    /**
     * The patterns of one setting of a mutator or profile.
     *
     * @return list<array{key: string, regex: bool, mutator: string, pattern: string}>
     */
    private function under(string $name, string $mutator, Node $settings, string $setting): array
    {
        $key = sprintf('%s.%s', $name, $setting);

        return $this->listed($key, $mutator, $settings->field($setting), regex: $setting === self::REGEX);
    }

    /**
     * The text values of a list under a key, each as a pattern: a pattern
     * over source where the list is a regex setting.
     *
     * @return list<array{key: string, regex: bool, mutator: string, pattern: string}>
     */
    private function listed(string $key, string $mutator, Node $values, bool $regex): array
    {
        $patterns = [];

        foreach ($this->itemsOf($values) as $value) {
            $patterns[] = ['key' => $key, 'regex' => $regex, 'mutator' => $mutator, 'pattern' => $this->textOf($value)];
        }

        return $patterns;
    }

    /** One entry of the block, as it was read; an empty object as the object it was. */
    private function written(string $name, Node $settings): string
    {
        $json = $settings->json();

        return $json === self::EMPTY_LIST && ! in_array($name, self::GLOBAL, strict: true)
            ? JsonObject::of([])
            : $json;
    }

    /** @return array<string, Node> */
    private function entriesOf(Node $node): array
    {
        try {
            return $node->entries();
        } catch (NotInShape) {
            return [];
        }
    }

    /** @return list<Node> */
    private function itemsOf(Node $node): array
    {
        try {
            return $node->items();
        } catch (NotInShape) {
            return [];
        }
    }

    private function textOf(Node $node): string
    {
        try {
            return $node->text();
        } catch (NotInShape) {
            return '';
        }
    }
}
