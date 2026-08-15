<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CacheProvider;
use AsyncAws\Core\Credentials\ChainProvider;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Request;
use AsyncAws\Core\RequestContext;
use AsyncAws\Core\Signer\SignerV4;
use AsyncAws\Core\Stream\StreamFactory;
use Psr\Clock\ClockInterface;

/**
 * AsyncAws has no RDS client, so this mirrors Aws\Rds\AuthTokenGenerator:
 * SigV4-presign GET https://{host}:{port}/?Action=connect&DBUser={user} with
 * service scope rds-db, then drop the scheme.
 *
 * The official AWS PHP SDK is massive, and this library aims not to require it just for a single feature.
 */
final readonly class RdsIamTokenGenerator
{
    /** RDS accepts at most 900 seconds. */
    private const int TOKEN_LIFETIME_SECONDS = 900;

    private CredentialProvider $credentialProvider;

    public function __construct(
        ?CredentialProvider $credentialProvider = null,
        private ?ClockInterface $clock = null,
    ) {
        // CacheProvider keeps resolved credentials in memory until expiry; without it every token mint hits IMDS.
        $this->credentialProvider = $credentialProvider ?? new CacheProvider(ChainProvider::createDefaultChain());
    }

    public function createToken(string $hostWithPort, string $region, string $username): string
    {
        $credentials = $this->credentialProvider->getCredentials(Configuration::create(['region' => $region]))
            ?? throw new \RuntimeException('Cannot resolve AWS credentials for RDS IAM authentication.');

        $query = ['Action' => 'connect', 'DBUser' => $username];
        $request = new Request('GET', '/', $query, [], StreamFactory::create(''));
        // setEndpoint() replaces the query with the URL's query string, so the URL must carry it.
        $request->setEndpoint(sprintf('https://%s/?%s', $hostWithPort, http_build_query($query)));

        $signedAt = $this->clock?->now() ?? new \DateTimeImmutable();

        (new SignerV4('rds-db', $region))->presign($request, $credentials, new RequestContext([
            'currentDate' => $signedAt,
            'expirationDate' => $signedAt->modify(sprintf('+%d seconds', self::TOKEN_LIFETIME_SECONDS)),
        ]));

        return substr($request->getEndpoint(), \strlen('https://'));
    }
}
