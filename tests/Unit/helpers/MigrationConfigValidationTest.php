<?php

namespace csabourin\spaghettiMigrator\tests\Unit\helpers;

use Craft;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MigrationConfigValidationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetConfig();
        parent::tearDown();
    }

    public function testValidateFlagsMissingConfiguration(): void
    {
        $tempDir = $this->createTempConfig([]);

        Craft::setAlias('@config', $tempDir);
        $config = MigrationConfig::getInstance();

        $errors = $config->validate();

        $this->assertContains('AWS bucket name is not configured (set aws.bucket in migration-config.php)', $errors);
        $this->assertContains('AWS URLs are not configured (auto-generated from aws.bucket and aws.region)', $errors);
        $this->assertContains('DigitalOcean bucket name is not configured (set DO_S3_BUCKET in .env)', $errors);
        $this->assertContains('DigitalOcean base URL is not configured (set DO_S3_BASE_URL in .env)', $errors);
        $this->assertContains('DigitalOcean access key is not configured (set DO_S3_ACCESS_KEY in .env)', $errors);
        $this->assertContains('DigitalOcean secret key is not configured (set DO_S3_SECRET_KEY in .env)', $errors);
        $this->assertContains('Filesystem mappings are not configured (set filesystemMappings in migration-config.php)', $errors);
        $this->assertCount(7, $errors);
    }

    public function testValidatePassesWithCompleteConfiguration(): void
    {
        $configData = [
            'aws' => [
                'bucket' => 'source-bucket',
                'urls' => ['https://source-bucket.s3.amazonaws.com'],
            ],
            'digitalocean' => [
                'bucket' => 'target-bucket',
                'baseUrl' => 'https://target.nyc3.digitaloceanspaces.com',
                'accessKey' => 'ACCESS',
                'secretKey' => 'SECRET',
            ],
            'filesystemMappings' => [
                'images' => 'imagesFs',
            ],
        ];

        $tempDir = $this->createTempConfig($configData);

        Craft::setAlias('@config', $tempDir);
        $config = MigrationConfig::getInstance();

        $errors = $config->validate();

        $this->assertSame([], $errors);
    }

    private function createTempConfig(array $config): string
    {
        $dir = sys_get_temp_dir() . '/config_' . uniqid();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $filePath = $dir . '/migration-config.php';
        file_put_contents($filePath, '<?php return ' . var_export($config, true) . ';');

        return $dir;
    }

    private function resetConfig(): void
    {
        $ref = new ReflectionClass(MigrationConfig::class);

        foreach (['config', 'settings', 'instance'] as $property) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        $usePluginSettings = $ref->getProperty('usePluginSettings');
        $usePluginSettings->setAccessible(true);
        $usePluginSettings->setValue(null, false);
    }
}
