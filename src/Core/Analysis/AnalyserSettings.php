<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_map;
use function array_values;
use function in_array;
use function ksort;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;
use function str_contains;
use function str_replace;

/**
 * The configuration a static analyser runs with, as it resolves it from its
 * config files and the environment (ADR-0020, decision 14), written alike on
 * every machine: each path under the project's root spelt from the root,
 * what differs between machines and runs left out, and each map's keys in
 * order. With it come the files it references, such as a baseline, an
 * included config, or a bootstrap, stub or scanned file or directory, which
 * its digest holds by their contents. A file named by a stream, as an
 * analyser names one inside its own archive, is the analyser's own, which
 * its version decides.
 */
final readonly class AnalyserSettings
{
    private const string UNREAD = 'The analyser\'s resolved configuration is no object: %s';

    /** What a path that names a stream holds after the stream's scheme. */
    private const string STREAM = '://';

    private function __construct(private string $written, private Paths $references)
    {
    }

    /**
     * The settings an analyser resolves, as the JSON it prints, in a project
     * at this root, without the members at the top that differ between
     * machines and runs; or why they cannot be read.
     */
    public static function resolved(string $json, string $directory, string ...$leftOut): self|CannotJudge
    {
        $settings = Node::decode($json);

        return $settings->kind() === Kind::Map
            ? new self(self::map($settings, Root::of($directory), array_values($leftOut)), Paths::none())
            : CannotJudge::because(sprintf(self::UNREAD, Lenient::json($settings)));
    }

    /**
     * The files or directories an analyser names, each spelt from the root
     * where it is under it; a name that is empty, or of a stream, is none.
     */
    public static function filesNamed(string $directory, string ...$named): Paths
    {
        $root = Root::of($directory);
        $files = Paths::none();

        foreach ($named as $name) {
            $local = $name !== '' && ! str_contains($name, self::STREAM);
            $files = $local ? $files->with($root->relative(Path::of($name)->collapsed()->value())) : $files;
        }

        return $files;
    }

    /** These settings, referencing these files or directories too. */
    public function referencing(Paths $files): self
    {
        $references = $this->references;

        foreach ($files as $file) {
            $references = $references->with($file);
        }

        return new self($this->written, $references);
    }

    /** The settings as every machine writes them. */
    public function written(): string
    {
        return $this->written;
    }

    /** The files and directories the settings reference, each spelt from the project's root where it is under it. */
    public function references(): Paths
    {
        return $this->references;
    }

    /**
     * The digest of these settings and of the referenced files' contents:
     * each file referenced, and each file under a directory referenced, by
     * its digest, or as missing.
     *
     * @param ByPath<Digest|Missing> $files
     */
    public function digest(ByPath $files): Digest
    {
        $digests = [];

        foreach ($files as $path => $contents) {
            $digests[$path->value()] = $contents instanceof Digest ? $contents->value() : Missing::DIGESTED;
        }

        ksort($digests);

        return Digest::sha256Of(JsonText::compact(['settings' => $this->written, 'files' => $digests]));
    }

    /**
     * A map, its members in order of their keys, those left out dropped.
     *
     * @param list<string> $leftOut
     */
    private static function map(Node $map, Root $root, array $leftOut): string
    {
        $members = [];

        foreach (Lenient::entries($map) as $key => $member) {
            $name = sprintf('%s', $key);

            if (! in_array($name, $leftOut, strict: true)) {
                $members[$name] = self::value($member, $root);
            }
        }

        ksort($members);

        return JsonText::object($members);
    }

    private static function value(Node $value, Root $root): string
    {
        return match ($value->kind()) {
            Kind::Map => self::map($value, $root, []),
            Kind::List => JsonText::items(array_map(
                static fn(Node $item): string => self::value($item, $root),
                Lenient::items($value),
            )),
            Kind::Text => JsonText::text(self::spelt($value->text(), $root)),
            Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null, Kind::Empty, Kind::Nothing => Lenient::json($value),
        };
    }

    /** A text with each path under the root in it spelt from the root, and the root itself as `.`. */
    private static function spelt(string $text, Root $root): string
    {
        return $text === $root->value()
            ? Path::root()->value()
            : str_replace(sprintf('%s/', $root->value()), '', $text);
    }
}
