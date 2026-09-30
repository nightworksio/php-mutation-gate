<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

use function sprintf;
use function str_ends_with;

/**
 * The `#[Holds]` a Pest suite's test files write, each with its file, read
 * as Pest's plugin reads them: a `#[Holds]` from which no group can follow is
 * refused, and every path the tokens find must be a group Pest lists.
 */
final readonly class PestHolds
{
    /** The file Pest loads before it starts any plugin, at the end of a test file's path. */
    private const string BOOTSTRAP = '/tests/Pest.php';

    /** Where a `#[Holds]` stands that Pest never passes to its filter, by what it is called. */
    private const array UNFILTERED = [
        'HookClosure' => 'a beforeEach, afterEach, beforeAll or afterAll closure',
        'DatasetClosure' => 'a dataset closure',
        'NamedFunction' => 'a named function',
    ];

    /** Where a `#[Holds]` stands that Pest loads as PHPUnit's, by what it is called. */
    private const array PHPUNIT = ['TestClass' => 'class', 'TestMethod' => 'method'];

    /** How a refusal begins: where the `#[Holds]` is written. */
    private const string AT = '%s:%d: %s';

    /** Why a `#[Holds]` in `tests/Pest.php` cannot be judged. */
    private const string IN_BOOTSTRAP = <<<'SAID'
        %s stands in %s, which Pest loads before it starts any plugin, so its tests
        register before the filter that turns #[Holds] into a group exists.
        Hold the test with ->group(%s) instead.
        SAID;

    /** Why a `#[Holds]` Pest never passes to its filter cannot be judged. */
    private const string NOT_FILTERED = <<<'SAID'
        %1$s stands on %2$s, which Pest never passes to its filter, so no group can follow from it.
        Hold the tests with ->group(%3$s) on a test or a describe, or with pest()->group(%3$s) for the whole file.
        SAID;

    /** Why a `#[Holds]` on a closure kept in a variable cannot be judged. */
    private const string KEPT = <<<'SAID'
        %s stands on a closure kept in a variable, and tokens cannot tell which test that closure
        becomes, so the gate cannot check it against the groups Pest lists.
        Hold the test with ->group(%s) where it is declared.
        SAID;

    /** Why a `#[Holds]` on a PHPUnit class or method whose path is not a literal cannot be judged. */
    private const string NOT_LITERAL = <<<'SAID'
        %s stands on the PHPUnit %s %s, which Pest loads without its filter, and its path is not
        one string literal, so tokens cannot show that a #[Group] beside it holds the same path.
        Write the path as one string literal, with #[Group('holds:<path>')] beside it.
        SAID;

    /** Why a `#[Holds]` on a PHPUnit class or method without its `#[Group]` cannot be judged. */
    private const string UNGROUPED = <<<'SAID'
        %s stands on the PHPUnit %s %s, which Pest loads without its filter,
        so only PHPUnit's own #[Group] puts it in a group. Add this line beside it,
        with PHPUnit\Framework\Attributes\Group imported:
        #[Group(%s)]
        SAID;

    /** Why a held path Pest lists no group for cannot be judged. */
    private const string UNLISTED = <<<'SAID'
        %s is written here, but Pest lists no group holds:%s.
        The package's Pest plugin turns each #[Holds] into its group, so it is not loaded.
        Pest loads the plugins vendor/pest-plugins.json lists, which the Composer plugin
        pestphp/pest-plugin writes from each package's extra.pest.plugins as Composer dumps
        its autoloader: allow that Composer plugin and run composer dump-autoload.
        SAID;

    /** @param list<array{Path, HoldsAttribute}> $read every `#[Holds]` read, with its file */
    private function __construct(private array $read)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * These, with every `#[Holds]` one test file writes, its path relative to
     * the repository, or the first from which no group can follow.
     */
    public function read(Path $file, HoldsAttributes $attributes): self|CannotJudge
    {
        $read = $this->read;

        foreach ($attributes as $attribute) {
            $why = $this->refusalOf($file, $attribute);

            if ($why !== '') {
                return CannotJudge::because(sprintf(self::AT, $file->value(), $attribute->line(), $why));
            }

            $read[] = [$file, $attribute];
        }

        return new self($read);
    }

    /**
     * The holdings the groups Pest lists declare, once every path read as one
     * string literal is among them. A path written as anything else is
     * evaluated by the plugin, so the listing alone answers for it. The
     * listing is the whole answer, so a `#[Holds]` with its `#[Group]` beside
     * it is one holding, by the group.
     */
    public function listedIn(Groups $listing): Holdings|CannotJudge
    {
        foreach ($this->read as [$file, $attribute]) {
            $path = $attribute->path();

            if ($path->isLiteral() && ! $listing->has(Group::named(sprintf('holds:%s', $path->text())))) {
                return CannotJudge::because(sprintf(
                    self::AT,
                    $file->value(),
                    $attribute->line(),
                    sprintf(self::UNLISTED, $attribute->written(), $path->text()),
                ));
            }
        }

        return Holdings::inGroups($listing);
    }

    /** Why no group can follow from a `#[Holds]`; empty where one can. */
    private function refusalOf(Path $file, HoldsAttribute $attribute): string
    {
        $written = $attribute->written();
        $path = $attribute->path();
        $on = $attribute->standing()->name;

        return match (true) {
            str_ends_with(sprintf('/%s', $file->value()), self::BOOTSTRAP)
                => sprintf(self::IN_BOOTSTRAP, $written, $file->value(), $path->group()),
            array_key_exists($on, self::UNFILTERED)
                => sprintf(self::NOT_FILTERED, $written, self::UNFILTERED[$on], $path->group()),
            $attribute->standing() === Standing::KeptClosure => sprintf(self::KEPT, $written, $path->group()),
            array_key_exists($on, self::PHPUNIT) && ! $path->isLiteral()
                => sprintf(self::NOT_LITERAL, $written, self::PHPUNIT[$on], $attribute->holder()),
            array_key_exists($on, self::PHPUNIT) && ! $attribute->isGrouped()
                => sprintf(self::UNGROUPED, $written, self::PHPUNIT[$on], $attribute->holder(), $path->group()),
            default => '',
        };
    }
}
