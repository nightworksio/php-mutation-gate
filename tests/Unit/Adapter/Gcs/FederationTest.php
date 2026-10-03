<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Gcs\Federation;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\ExternalAccounts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('hands over a ready token another CI\'s federation gave, asking nothing', function (): void {
    $cloud = new Cloud();
    $federation = Federation::from(Variables::of(['MUTATION_GATE_GCS_TOKEN' => 'ya29.ready']), $cloud->exchange());

    expect($federation instanceof Federation ? $federation->token() : $federation)->toEqual(Token::bearer('ya29.ready'))
        ->and($cloud->requests)->toBe([]);
});

it('exchanges the CI\'s token through the external-account file once, however often it is asked', function (): void {
    $cloud = new Cloud()
        ->answering(ExternalAccounts::REQUEST_URL, 200, '{"value": "github-jwt"}')
        ->answering(ExternalAccounts::STS, 200, '{"access_token": "federated"}');
    $federation = Federation::from(Variables::of(['GOOGLE_APPLICATION_CREDENTIALS' => ExternalAccounts::written()]), $cloud->exchange());
    $first = $federation instanceof Federation ? $federation->token() : $federation;
    $second = $federation instanceof Federation ? $federation->token() : $federation;

    expect([$first, $second])->toEqual([Token::bearer('federated'), Token::bearer('federated')])
        ->and($cloud->requests)->toHaveCount(2);
});

it('keeps why there is no token once, rather than asking again', function (): void {
    $cloud = new Cloud()->answering(ExternalAccounts::REQUEST_URL, 401, 'Unauthorized');
    $federation = Federation::from(Variables::of(['GOOGLE_APPLICATION_CREDENTIALS' => ExternalAccounts::written()]), $cloud->exchange());
    $why = CannotJudge::because('https://pipelines.actions.githubusercontent.com answered 401: Unauthorized');

    expect($federation instanceof Federation ? [$federation->token(), $federation->token()] : [])->toEqual([$why, $why])
        ->and($cloud->requests)->toHaveCount(1);
});

it('refuses a key the credentials file holds, though a ready token is set too', function (): void {
    $key = ExternalAccounts::holding('{"type": "service_account"}');

    expect(Federation::from(Variables::of(['GOOGLE_APPLICATION_CREDENTIALS' => $key, 'MUTATION_GATE_GCS_TOKEN' => 'ya29']), new Cloud()->exchange()))
        ->toEqual(Invalid::because(Problem::at(
            '',
            'GOOGLE_APPLICATION_CREDENTIALS names a service-account key, which the gcs store refuses: use Workload Identity Federation instead.',
        )));
});

it('says there is no token where neither the file nor a ready token is set', function (): void {
    expect(Federation::from(Variables::of([]), new Cloud()->exchange()))->toEqual(Invalid::because(Problem::at(
        '',
        'neither GOOGLE_APPLICATION_CREDENTIALS nor MUTATION_GATE_GCS_TOKEN is set, so the gcs store has no token to write with.',
    )));
});
