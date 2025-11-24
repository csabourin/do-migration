<?php

namespace csabourin\spaghettiMigrator\tests\Unit\services;

use csabourin\spaghettiMigrator\services\ModuleDefinitionProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ModuleDefinitionProviderTest extends TestCase
{
    public function testPlaceholderPrefersValueThenEnvVarThenDefault(): void
    {
        $provider = new ModuleDefinitionProvider(new FakeConfig());
        $placeholder = new ReflectionMethod($provider, 'placeholder');
        $placeholder->setAccessible(true);

        $this->assertSame('explicit', $placeholder->invoke($provider, 'explicit', 'ENV_ONE', 'default'));
        $this->assertSame('${ENV_ONE}', $placeholder->invoke($provider, '', 'ENV_ONE', 'default'));
        $this->assertSame('default', $placeholder->invoke($provider, null, '', 'default'));
    }

    public function testNormalizeEndpointStripsProtocolAndTrailingSlash(): void
    {
        $provider = new ModuleDefinitionProvider(new FakeConfig());
        $normalize = new ReflectionMethod($provider, 'normalizeEndpoint');
        $normalize->setAccessible(true);

        $this->assertSame('example.com', $normalize->invoke($provider, 'https://example.com/'));
        $this->assertSame('example.com/path', $normalize->invoke($provider, 'http://example.com/path/'));
        $this->assertSame('', $normalize->invoke($provider, '   '));
    }

    public function testModuleDefinitionsIncludeDefaultFlags(): void
    {
        $provider = new ModuleDefinitionProvider(new FakeConfig());
        $definitions = $provider->getModuleDefinitions();

        $this->assertNotEmpty($definitions);

        foreach ($definitions as $phase) {
            if (!isset($phase['modules'])) {
                continue;
            }

            foreach ($phase['modules'] as $module) {
                $this->assertArrayHasKey('supportsDryRun', $module);
                $this->assertArrayHasKey('supportsResume', $module);
                $this->assertArrayHasKey('requiresArgs', $module);
            }
        }
    }
}

class FakeConfig
{
    public function getAwsBucket(): string
    {
        return 'aws-bucket';
    }

    public function getAwsRegion(): string
    {
        return 'ca-central-1';
    }

    public function getAwsAccessKey(): string
    {
        return 'AKIA123';
    }

    public function getAwsSecretKey(): string
    {
        return 'SECRET';
    }

    public function getDoAccessKey(): string
    {
        return 'DO-ACCESS';
    }

    public function getDoSecretKey(): string
    {
        return 'DO-SECRET';
    }

    public function getDoRegion(): string
    {
        return 'nyc3';
    }

    public function getDoEndpoint(): string
    {
        return 'https://example.com';
    }

    public function getAwsEnvVarBucket(): string
    {
        return 'AWS_SOURCE_BUCKET';
    }

    public function getAwsEnvVarRegion(): string
    {
        return 'AWS_SOURCE_REGION';
    }

    public function getAwsEnvVarAccessKey(): string
    {
        return 'AWS_SOURCE_ACCESS_KEY';
    }

    public function getAwsEnvVarSecretKey(): string
    {
        return 'AWS_SOURCE_SECRET_KEY';
    }

    public function getAwsEnvVarAccessKeyRef(): string
    {
        return '$AWS_SOURCE_ACCESS_KEY';
    }

    public function getAwsEnvVarSecretKeyRef(): string
    {
        return '$AWS_SOURCE_SECRET_KEY';
    }

    public function getAwsEnvVarRegionRef(): string
    {
        return '$AWS_SOURCE_REGION';
    }

    public function get(string $key, $default = null)
    {
        return $default;
    }
}
