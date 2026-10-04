<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Psalm;

use function array_any;
use function array_key_exists;
use function array_map;
use function dirname;
use function is_array;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\ShellPattern;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Xml;

use function realpath;
use function rtrim;
use function simplexml_load_string;

use SimpleXMLElement;

use function sprintf;
use function str_starts_with;
use function strpbrk;
use function trim;

/**
 * A project's Psalm config, `psalm.xml`, as Psalm reads it (ADR-0020,
 * decisions 7 and 14): the files it analyses, its `<projectFiles>` less their
 * `<ignoreFiles>`; and the configuration it runs with, which Psalm prints no
 * command for, read from the file itself, with the files it names: its
 * baseline, its autoloader, its stubs, its file plugins and the configs it
 * includes. Every path in it is spelt from the config's own directory.
 */
final readonly class PsalmXml
{
    private const string NOT_XML = 'Psalm\'s config %s is not XML.';

    /** The namespace of XInclude, whose `include` element pulls another file into a Psalm config. */
    private const string XINCLUDE = 'http://www.w3.org/2001/XInclude';

    /**
     * @param list<string> $analysed every directory or file `<projectFiles>` names, absolute
     * @param list<string> $ignored  every directory or file its `<ignoreFiles>` names, absolute
     * @param list<string> $named    every file the config names that Psalm reads besides the code, absolute
     * @param array<string, string> $links    each directory `<projectFiles>` names through a link, as named, by
     *                                        where it really is, which is how Psalm names the files in it
     */
    private function __construct(
        private Path $file,
        private string $written,
        private array $analysed,
        private array $ignored,
        private array $named,
        private array $links,
    ) {
    }

    /** The config these contents hold, read from this file of a project at this root; or why it is no config. */
    public static function read(Contents $contents, Path $config, Root $root): self|CannotJudge
    {
        $xml = simplexml_load_string($contents->text(), options: Xml::QUIET);

        if (! $xml instanceof SimpleXMLElement) {
            return CannotJudge::because(sprintf(self::NOT_XML, $config->value()));
        }

        $file = $root->at($config);
        $directory = dirname($file->value());
        $project = self::child($xml, 'projectFiles');
        $named = [
            $file->value(),
            ...self::attributed($xml, 'errorBaseline', $directory),
            ...self::attributed($xml, 'autoloader', $directory),
            ...self::namesIn(self::child($xml, 'stubs'), 'file', $directory),
            ...self::filenamesOf(self::child($xml, 'plugins'), $directory),
            ...self::includedBy($xml, $directory),
        ];

        $directories = self::namesIn($project, 'directory', $directory);

        return new self(
            $config,
            self::element($xml),
            [
                ...self::within($directories),
                ...self::namesIn($project, 'file', $directory),
            ],
            [
                ...self::within(self::namesIn(self::child($project, 'ignoreFiles'), 'directory', $directory)),
                ...self::namesIn(self::child($project, 'ignoreFiles'), 'file', $directory),
            ],
            $named,
            self::linked($directories),
        );
    }

    /** The config's file, as the project spells it. */
    public function file(): Path
    {
        return $this->file;
    }

    /**
     * A file Psalm names, as the project names it: one in a directory the
     * config names through a link by the directory as named, which Psalm
     * names by where it really is.
     */
    public function spelt(string $file): string
    {
        foreach ($this->links as $real => $named) {
            if ($file === $real || str_starts_with($file, sprintf('%s/', $real))) {
                return sprintf('%s%s', $named, mb_substr($file, mb_strlen($real)));
            }
        }

        return $file;
    }

    /** Whether Psalm analyses this file, by its absolute path. */
    public function holds(string $file): bool
    {
        return array_any($this->analysed, static fn(string $path): bool => ShellPattern::covers($path, $file))
            && ! array_any($this->ignored, static fn(string $path): bool => ShellPattern::covers($path, $file));
    }

    /**
     * The configuration Psalm runs with, as every machine writes it, with
     * the files it names, in a project at this root.
     */
    public function settings(string $root): AnalyserSettings|CannotJudge
    {
        $settings = AnalyserSettings::resolved($this->written, $root);

        return $settings instanceof AnalyserSettings
            ? $settings->referencing(AnalyserSettings::filesNamed($root, ...$this->named))
            : $settings;
    }

    /**
     * An element as JSON: its name, its attributes by name, its text where it
     * has any, and its children in order.
     */
    private static function element(SimpleXMLElement $element): string
    {
        $attributes = [];

        foreach (self::attributesOf($element) as $name => $value) {
            $attributes[$name] = JsonText::text($value);
        }

        $text = trim((string) $element);

        return JsonText::object([
            'element' => JsonText::text($element->getName()),
            'attributes' => JsonText::object($attributes),
            ...$text === '' ? [] : ['text' => JsonText::text($text)],
            'children' => JsonText::items(array_map(self::element(...), self::childrenOf($element))),
        ]);
    }

    /** @return array<string, string> an element's attributes, in no namespace, by name */
    private static function attributesOf(SimpleXMLElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes() ?? [] as $name => $value) {
            $attributes[$name] = (string) $value;
        }

        return $attributes;
    }

    /** @return list<SimpleXMLElement> an element's children in the config's own namespace, a default one or none */
    private static function childrenOf(SimpleXMLElement $element): array
    {
        $children = [];

        foreach ($element->children() ?? [] as $child) {
            $children[] = $child;
        }

        return $children;
    }

    /** The first child of an element with this name, or the element itself where it has none, which names nothing. */
    private static function child(SimpleXMLElement $element, string $name): SimpleXMLElement
    {
        foreach (self::childrenOf($element) as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }

        return new SimpleXMLElement('<none/>');
    }

    /** @return list<string> the `name` of each child of this kind, absolute */
    private static function namesIn(SimpleXMLElement $parent, string $kind, string $directory): array
    {
        $names = [];

        foreach (self::childrenOf($parent) as $child) {
            if ($child->getName() === $kind) {
                $names = [...$names, ...self::attributed($child, 'name', $directory)];
            }
        }

        return $names;
    }

    /**
     * Directories as patterns of the files inside them: a directory as
     * named, and one named by a pattern with every file under each directory
     * it matches too, as Psalm finds the directories first.
     *
     * @param  list<string> $directories
     * @return list<string>
     */
    private static function within(array $directories): array
    {
        $patterns = [];

        foreach ($directories as $directory) {
            $pattern = strpbrk($directory, ShellPattern::WILDCARDS) !== false;
            $patterns = [...$patterns, $directory, ...$pattern ? [sprintf('%s/*', rtrim($directory, '/'))] : []];
        }

        return $patterns;
    }

    /**
     * @param  list<string>          $directories
     * @return array<string, string> each of these directories that is not where it is named, as named, by where it is
     */
    private static function linked(array $directories): array
    {
        $links = [];

        foreach ($directories as $directory) {
            $real = realpath($directory);
            $links += $real === false || $real === $directory ? [] : [$real => $directory];
        }

        return $links;
    }

    /** @return list<string> the `filename` of each `<plugin>`, absolute */
    private static function filenamesOf(SimpleXMLElement $plugins, string $directory): array
    {
        $files = [];

        foreach (self::childrenOf($plugins) as $plugin) {
            $files = [...$files, ...self::attributed($plugin, 'filename', $directory)];
        }

        return $files;
    }

    /** @return list<string> the file every `xi:include` anywhere in the config pulls in, absolute */
    private static function includedBy(SimpleXMLElement $xml, string $directory): array
    {
        $included = [];
        $xml->registerXPathNamespace('xi', self::XINCLUDE);
        $includes = $xml->xpath('//xi:include');

        foreach (is_array($includes) ? $includes : [] as $include) {
            $href = $include->attributes()?->href;
            $included = $href === null ? $included : [...$included, self::from($directory, (string) $href)];
        }

        return $included;
    }

    /** @return list<string> an attribute of an element, as an absolute path, where it has it */
    private static function attributed(SimpleXMLElement $element, string $attribute, string $directory): array
    {
        $attributes = self::attributesOf($element);

        return array_key_exists($attribute, $attributes) && $attributes[$attribute] !== ''
            ? [self::from($directory, $attributes[$attribute])]
            : [];
    }

    /** A path as Psalm reads it from its config: absolute, or spelt from the config's directory. */
    private static function from(string $directory, string $path): string
    {
        return str_starts_with($path, '/') ? $path : sprintf('%s/%s', $directory, $path);
    }
}
