<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Http\Answer;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\TokenField;

it('reads the body of an accepted answer, and why there is none otherwise', function (): void {
    expect(Answer::body(Reply::of(200, '', 'jwt'), 'https://token.example'))->toBe('jwt')
        ->and(Answer::body(Reply::of(299, '', ''), 'https://token.example'))->toBe('')
        ->and(Answer::body(Reply::of(403, '', "denied\nnow"), 'https://token.example'))
        ->toEqual(CannotJudge::because('https://token.example answered 403: denied now'))
        ->and(Answer::body(CannotJudge::because('unreached'), 'https://token.example'))->toEqual(CannotJudge::because('unreached'));
});

it('reads one field of an accepted JSON answer, and why there is none otherwise', function (): void {
    expect(Answer::field(Reply::of(200, '', '{"access_token": "ya29", "expires_in": 3599}'), TokenField::OAuth, 'https://sts.example'))->toBe('ya29')
        ->and(Answer::field(Reply::of(200, '', '{"access_token": 3}'), TokenField::OAuth, 'https://sts.example'))
        ->toEqual(CannotJudge::because('https://sts.example answered without access_token'))
        ->and(Answer::field(Reply::of(200, '', 'not json'), TokenField::OAuth, 'https://sts.example'))
        ->toEqual(CannotJudge::because('https://sts.example answered without access_token'))
        ->and(Answer::field(Reply::of(400, '', '{"error": "invalid_grant"}'), TokenField::OAuth, 'https://sts.example'))
        ->toEqual(CannotJudge::because('https://sts.example answered 400: {"error": "invalid_grant"}'))
        ->and(Answer::field(CannotJudge::because('unreached'), TokenField::OAuth, 'https://sts.example'))->toEqual(CannotJudge::because('unreached'));
});
