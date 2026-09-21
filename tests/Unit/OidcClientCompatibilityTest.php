<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Unit;

use Admin9\OidcServer\Models\OidcClient;
use Admin9\OidcServer\Models\Passport12OidcClient;
use Admin9\OidcServer\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

class OidcClientCompatibilityTest extends TestCase
{
    public function test_default_client_model_matches_passport_signature(): void
    {
        $passportParameters = (new \ReflectionMethod(Client::class, 'skipsAuthorization'))
            ->getNumberOfRequiredParameters();

        $this->assertSame(
            $passportParameters === 0 ? Passport12OidcClient::class : OidcClient::class,
            Passport::clientModel()
        );
        $this->assertSame($passportParameters, (new \ReflectionMethod(Passport::clientModel(), 'skipsAuthorization'))
            ->getNumberOfRequiredParameters());
    }

    public function test_existing_client_override_remains_compatible_with_passport_13(): void
    {
        if ((new \ReflectionMethod(Client::class, 'skipsAuthorization'))->getNumberOfRequiredParameters() === 0) {
            $this->markTestSkipped('The existing two-argument override is a Passport 13 contract.');
        }

        $client = new class extends OidcClient
        {
            public function skipsAuthorization(Authenticatable $user, array $scopes): bool
            {
                return true;
            }
        };

        $this->assertTrue($client->skipsAuthorization($this->createStub(Authenticatable::class), []));
    }
}
