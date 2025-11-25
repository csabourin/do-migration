<?php

namespace csabourin\spaghettiMigrator\tests\Unit\adapters;

use csabourin\spaghettiMigrator\adapters\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LocalFilesystemAdapterTest extends TestCase
{
    private function buildAdapter(?string $basePath, ?string $baseUrl = null): LocalFilesystemAdapter
    {
        $ref = new ReflectionClass(LocalFilesystemAdapter::class);
        /** @var LocalFilesystemAdapter $adapter */
        $adapter = $ref->newInstanceWithoutConstructor();

        $this->setProperty($ref, $adapter, 'basePath', $basePath ?? '/tmp/base');
        $this->setProperty($ref, $adapter, 'baseUrl', $baseUrl);

        return $adapter;
    }

    private function setProperty(ReflectionClass $ref, object $instance, string $name, mixed $value): void
    {
        $property = $ref->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }

    public function testUrlPatternUsesBaseUrlWhenAvailable(): void
    {
        $adapter = $this->buildAdapter('/var/data', 'https://cdn.example.com/base');

        $this->assertSame('local', $adapter->getProviderName());
        $this->assertSame('https://cdn.example.com/base/{path}', $adapter->getUrlPattern());
        $this->assertSame('https://cdn.example.com/base/file.jpg', $adapter->getPublicUrl('file.jpg'));
        $this->assertTrue($adapter->getCapabilities()->supportsPublicUrls);
    }

    public function testUrlPatternFallsBackToFileScheme(): void
    {
        $adapter = $this->buildAdapter('/var/data');

        $this->assertSame('file:///var/data/{path}', $adapter->getUrlPattern());
        $this->assertSame('file:///var/data/example.txt', $adapter->getPublicUrl('/example.txt'));
        $this->assertFalse($adapter->getCapabilities()->supportsPublicUrls);
        $this->assertSame('/var/data', $adapter->getBucket());
    }
}
