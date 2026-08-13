<?php

declare(strict_types=1);

namespace TacticMedia\RdsAuth\Tests;

use AsyncAws\Core\Credentials\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use TacticMedia\RdsAuth\RdsIamTokenGenerator;
use TacticMedia\RdsAuth\Tests\Support\FrozenClock;

/**
 * Guards against drift between RdsIamTokenGenerator and the official AWS
 * implementation. The fixtures hold tokens recorded from the AWS CLI;
 * tests/fixtures/generate-reference-tokens.php reproduces them.
 *
 * @internal
 */
#[CoversClass(RdsIamTokenGenerator::class)]
final class RdsIamTokenGeneratorReferenceTest extends TestCase
{
    /**
     * @param array{
     *     name: string,
     *     source: string,
     *     host: string,
     *     port: int,
     *     region: string,
     *     username: string,
     *     accessKeyId: string,
     *     secretAccessKey: string,
     *     sessionToken: string|null,
     *     xAmzDate: string,
     *     token: string,
     * } $entry
     */
    #[DataProvider('referenceTokens')]
    #[TestDox('Reproduces the token an official AWS implementation signed for the same inputs and instant')]
    public function testReproducesReferenceToken(array $entry): void
    {
        $signedAt = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $entry['xAmzDate'], new \DateTimeZone('UTC'));
        if (false === $signedAt) {
            throw new \RuntimeException(sprintf('Fixture "%s" has a malformed X-Amz-Date.', $entry['name']));
        }

        $generator = new RdsIamTokenGenerator(
            new Credentials($entry['accessKeyId'], $entry['secretAccessKey'], $entry['sessionToken']),
            new FrozenClock($signedAt),
        );

        $token = $generator->createToken(sprintf('%s:%d', $entry['host'], $entry['port']), $entry['region'], $entry['username']);

        // Compare raw key=value pairs, order-insensitively but without URL decoding:
        // the signature covers the canonical encoding, so a decoded comparison could
        // hide an encoding difference the server would reject.
        self::assertSame($this->hostAndPath($entry['token']), $this->hostAndPath($token));
        self::assertSame($this->sortedQueryPairs($entry['token']), $this->sortedQueryPairs($token));
    }

    /**
     * @return iterable<string, array{array{
     *     name: string,
     *     source: string,
     *     host: string,
     *     port: int,
     *     region: string,
     *     username: string,
     *     accessKeyId: string,
     *     secretAccessKey: string,
     *     sessionToken: string|null,
     *     xAmzDate: string,
     *     token: string,
     * }}>
     */
    public static function referenceTokens(): iterable
    {
        $json = file_get_contents(__DIR__.'/fixtures/rds-iam-tokens.json');
        if (false === $json) {
            throw new \RuntimeException('Cannot read reference token fixtures.');
        }

        /** @var list<array{name: string, source: string, host: string, port: int, region: string, username: string, accessKeyId: string, secretAccessKey: string, sessionToken: string|null, xAmzDate: string, token: string}> $entries */
        $entries = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        foreach ($entries as $entry) {
            yield sprintf('%s [%s]', $entry['name'], $entry['source']) => [$entry];
        }
    }

    private function hostAndPath(string $token): string
    {
        return explode('?', $token, 2)[0];
    }

    /** @return list<string> */
    private function sortedQueryPairs(string $token): array
    {
        $query = explode('?', $token, 2)[1] ?? '';
        $pairs = explode('&', $query);
        sort($pairs);

        return $pairs;
    }
}
