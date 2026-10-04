<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function class_exists;

use Closure;

use function in_array;

use NightWorksIO\MutationGate\Adapter\Infection\Import\Profiles;
use NightWorksIO\MutationGate\Core\Format\JsonObject;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;

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

    /** The profile Infection turns on where its config turns on no mutator. */
    private const string DEFAULTS = '@default';

    /** @param Closure(string): bool $exists whether a class can be loaded, Infection's profiles among them */
    private function __construct(private Node $block, private Closure $exists)
    {
    }

    /** @param Closure(string): bool $exists whether a class can be loaded, Infection's profiles among them */
    public static function of(Node $block, Closure $exists = class_exists(...)): self
    {
        return new self($block, $exists);
    }

    /**
     * Every value of `ignore` or `ignoreSourceCodeByRegex` under `mutators`:
     * the key it is under, whether it is a pattern over source rather than
     * over names, the mutator it applies to, none where it applies to more
     * than one, and the pattern.
     *
     * @return list<IgnorePattern>
     */
    public function patterns(): array
    {
        $patterns = [];

        foreach (Lenient::entries($this->block) as $key => $settings) {
            $name = sprintf('%s', $key);
            $mutator = str_starts_with($name, '@') ? AnyMutator::of() : $name;
            $patterns = [...$patterns, ...match ($name) {
                self::GLOBAL_IGNORE => $this->listed($name, AnyMutator::of(), $settings, regex: false),
                self::GLOBAL_REGEX => $this->listed($name, AnyMutator::of(), $settings, regex: true),
                default => [
                    ...$this->under($name, $mutator, $settings, self::IGNORE),
                    ...$this->under($name, $mutator, $settings, self::REGEX),
                ],
            }];
        }

        return $patterns;
    }

    /**
     * The JSON of the `mutators` block the gate writes, by its key: for a run
     * of every mutator, the whole block, or Infection's default profile where
     * the project's turns on nothing, and every bridge to a registered mutator
     * the config turns on, less those that stand down beside Infection's own
     * (ADR-0021, decision 18); none where there is neither. For a run that names
     * mutators, only those, a bridged one by its bridge's class, with the
     * project's settings for each, and its global ignores.
     *
     * @return array<string, string>
     */
    public function narrowedTo(Mutators $mutators, Bridges $bridges = new Bridges()): array
    {
        $own = Lenient::entries($this->block);
        $kept = $mutators->isAll() ? $this->all($own, $bridges) : $this->named($own, $mutators, $bridges);

        return $kept === [] ? [] : ['mutators' => JsonObject::of($kept)];
    }

    /**
     * Every entry of the project's block, or the default profile where it has
     * none and there are bridges, then every bridge, less each to a mutator
     * that stands down beside the mutators of Infection's own those turn on.
     *
     * @param array<array-key, Node> $own
     *
     * @return array<string, string>
     */
    private function all(array $own, Bridges $bridges): array
    {
        $kept = $own === [] && ! $bridges->isEmpty() ? [self::DEFAULTS => self::ON] : $this->entries($own);
        $profiles = Profiles::installed($this->exists);
        $running = NamedMutators::of(...$profiles instanceof Profiles ? $profiles->on($this->switches($own)) : []);

        foreach ($bridges->besides($running)->classes() as $bridge) {
            $kept[$bridge] = self::ON;
        }

        return $kept;
    }

    /**
     * Whether the block turns each profile or mutator on, in its order: the
     * default profile, where it names none.
     *
     * @param array<array-key, Node> $own
     *
     * @return list<array{string, bool}>
     */
    private function switches(array $own): array
    {
        $switches = $own === [] ? [[self::DEFAULTS, true]] : [];

        foreach ($own as $key => $settings) {
            $name = sprintf('%s', $key);
            $switches = in_array($name, self::GLOBAL, strict: true)
                ? $switches
                : [...$switches, [$name, Lenient::boolean($settings, otherwise: true)]];
        }

        return $switches;
    }

    /**
     * @param array<array-key, Node> $own
     *
     * @return array<string, string>
     */
    private function entries(array $own): array
    {
        $kept = [];

        foreach ($own as $key => $settings) {
            $name = sprintf('%s', $key);
            $kept[$name] = $this->written($name, $settings);
        }

        return $kept;
    }

    /**
     * The global ignores, and each named mutator, a bridged one by its
     * bridge's class: with the project's settings for it where it has some,
     * and turned on bare where it has none.
     *
     * @param array<array-key, Node> $own
     *
     * @return array<string, string>
     */
    private function named(array $own, Mutators $mutators, Bridges $bridges): array
    {
        $kept = array_intersect_key($this->entries($own), array_flip(self::GLOBAL));

        foreach ($mutators as $mutator) {
            $kept[$bridges->keyOf($mutator)] = self::ON;
        }

        foreach ($own as $key => $settings) {
            $name = sprintf('%s', $key);

            if (array_key_exists($name, $kept) && Lenient::entries($settings) !== []) {
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
    /** @return list<IgnorePattern> */
    private function under(string $name, string|AnyMutator $mutator, Node $settings, string $setting): array
    {
        $key = sprintf('%s.%s', $name, $setting);

        return $this->listed($key, $mutator, $settings->field($setting), regex: $setting === self::REGEX);
    }

    /**
     * The text values of a list under a key, each as a pattern: a pattern
     * over source where the list is a regex setting.
     *
     * @return list<IgnorePattern>
     */
    private function listed(string $key, string|AnyMutator $mutator, Node $values, bool $regex): array
    {
        $patterns = [];

        foreach (Lenient::items($values) as $value) {
            $patterns[] = $regex
                ? IgnorePattern::overSource($key, $mutator, Lenient::text($value))
                : IgnorePattern::overNames($key, $mutator, Lenient::text($value));
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
}
