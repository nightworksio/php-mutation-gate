<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function preg_replace;
use function sprintf;

/**
 * A `paths` entry (ADR-0074): a glob, and the mutators, timeouts and static check it sets for the files it
 * matches, as a layer that sets only those.
 */
final readonly class PathRule
{
    /** A glob's first wildcard and everything after it. */
    private const string WILDCARD_ON = '/[*?].*$/us';

    private function __construct(private Glob $glob, private Layer $rules)
    {
    }

    public static function of(Glob $glob, Layer $rules): self
    {
        return new self($glob, $rules);
    }

    public function glob(): Glob
    {
        return $this->glob;
    }

    /** What the entry sets, as a layer that sets only that. */
    public function rules(): Layer
    {
        return $this->rules;
    }

    public function matches(Path $path): bool
    {
        return $this->glob->matches($path);
    }

    /** The glob's literal prefix: the characters before its first wildcard, which rank how specific it is. */
    public function prefix(): string
    {
        return preg_replace(self::WILDCARD_ON, '', $this->glob->value()) ?? '';
    }

    /** The entry as a config at this origin writes it. */
    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::of('glob', $origin->written(Path::of($this->glob->value()))))
            ->merged($this->rules->written($origin));
    }

    /** The entry as the builder's `PathRule::matching()` writes it. */
    public function php(PathOrigin $origin): string
    {
        return sprintf(
            'PathRule::matching(%s, Gate::configure()%s)',
            PhpCalls::literal($origin->written(Path::of($this->glob->value()))),
            $this->rules->php($origin)->nested(),
        );
    }
}
