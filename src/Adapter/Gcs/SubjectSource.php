<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Gcs;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Http\Answer;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\Origin;
use NightWorksIO\MutationGate\Core\Http\Request;

use function sprintf;
use function trim;

/**
 * Where an external-account file says the CI's own token is: a file, or a
 * URL asked with the headers the file names, as `google-github-actions/auth`
 * writes it; and, for a JSON one, the field that holds it
 * (ADR-0028 decision 2).
 */
final readonly class SubjectSource
{
    private const string UNREAD = 'the CI\'s token could not be read from %s';

    private const string EMPTY = 'the CI\'s token from %s is empty';

    /**
     * @param string|Request $at    the file, or the request that asks for the token
     * @param string         $field the JSON field that holds the token; empty where the whole text is the token
     */
    private function __construct(private string|Request $at, private string $field)
    {
    }

    public static function file(string $path, string $field): self
    {
        return new self($path, $field);
    }

    public static function url(Request $request, string $field): self
    {
        return new self($request, $field);
    }

    /** The CI's token, or why it could not be had. */
    public function token(Exchange $exchange): string|CannotJudge
    {
        $from = $this->at instanceof Request ? Origin::of($this->at->url()) : $this->at;
        $text = $this->at instanceof Request
            ? Answer::body($exchange->answer($this->at), $from)
            : $this->read($this->at);
        $token = is_string($text) ? $this->within($text) : $text;

        return $token === '' ? CannotJudge::because(sprintf(self::EMPTY, $from)) : $token;
    }

    private function read(string $file): string|CannotJudge
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        return is_string($text) ? $text : CannotJudge::because(sprintf(self::UNREAD, $file));
    }

    private function within(string $text): string
    {
        return $this->field === '' ? trim($text) : Lenient::text(Node::decode($text)->field($this->field));
    }
}
