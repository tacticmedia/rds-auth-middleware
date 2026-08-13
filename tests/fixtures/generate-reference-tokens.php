<?php

declare(strict_types=1);

/**
 * Regenerates rds-iam-tokens.json with tokens signed by the AWS CLI, the
 * official reference implementation. Each entry records the X-Amz-Date the
 * CLI emitted; the test freezes the generator clock at that instant and
 * compares tokens. Every run signs at a new instant and rewrites all entries.
 *
 * Usage: php tests/fixtures/generate-reference-tokens.php
 */
$cases = [
    [
        'name' => 'ap-southeast-2 basic',
        'host' => 'db.example.com',
        'port' => 5432,
        'region' => 'ap-southeast-2',
        'username' => 'app',
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        'sessionToken' => null,
    ],
    [
        'name' => 'us-east-1 username needing URL encoding',
        'host' => 'cluster.cluster-abc123.us-east-1.rds.amazonaws.com',
        'port' => 5432,
        'region' => 'us-east-1',
        'username' => 'app+user@example.com',
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        'sessionToken' => null,
    ],
    [
        'name' => 'eu-west-1 session token',
        'host' => 'db.eu.example.com',
        'port' => 5433,
        'region' => 'eu-west-1',
        'username' => 'reader',
        'accessKeyId' => 'ASIAEXAMPLESESSION',
        'secretAccessKey' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        'sessionToken' => 'IQoJb3JpZ2luX2VjEXAMPLESESSIONTOKEN/2029/token+with/slashes=',
    ],
];

/**
 * @param array{host: string, port: int, region: string, username: string, accessKeyId: string, secretAccessKey: string, sessionToken: string|null} $case
 */
function cliToken(array $case): string
{
    $environment = [
        'AWS_CONFIG_FILE' => '/dev/null',
        'AWS_SHARED_CREDENTIALS_FILE' => '/dev/null',
        'AWS_EC2_METADATA_DISABLED' => 'true',
        'AWS_ACCESS_KEY_ID' => $case['accessKeyId'],
        'AWS_SECRET_ACCESS_KEY' => $case['secretAccessKey'],
        'AWS_SESSION_TOKEN' => $case['sessionToken'] ?? '',
    ];

    $command = '';
    foreach ($environment as $variable => $value) {
        $command .= sprintf('%s=%s ', $variable, escapeshellarg($value));
    }

    $command .= sprintf(
        'aws rds generate-db-auth-token --hostname %s --port %d --username %s --region %s',
        escapeshellarg($case['host']),
        $case['port'],
        escapeshellarg($case['username']),
        escapeshellarg($case['region']),
    );

    $token = shell_exec($command);
    if (!is_string($token) || '' === trim($token)) {
        throw new RuntimeException(sprintf('AWS CLI produced no token for: %s', $command));
    }

    return trim($token);
}

function cliVersion(): string
{
    $version = shell_exec('aws --version');
    if (!is_string($version) || 1 !== preg_match('#^aws-cli/(\S+)#', trim($version), $matches)) {
        throw new RuntimeException('Cannot determine the AWS CLI version.');
    }

    return 'aws-cli-'.$matches[1];
}

function xAmzDate(string $token): string
{
    if (1 !== preg_match('/X-Amz-Date=([0-9TZ]+)/', $token, $matches)) {
        throw new RuntimeException(sprintf('Token has no X-Amz-Date: %s', $token));
    }

    return $matches[1];
}

$source = cliVersion();
$entries = [];
foreach ($cases as $case) {
    $token = cliToken($case);
    $entries[] = [...$case, 'source' => $source, 'xAmzDate' => xAmzDate($token), 'token' => $token];
}

$json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents(__DIR__.'/rds-iam-tokens.json', $json.PHP_EOL);
echo sprintf("Wrote %d entries from %s to %s/rds-iam-tokens.json\n", count($entries), $source, __DIR__);
