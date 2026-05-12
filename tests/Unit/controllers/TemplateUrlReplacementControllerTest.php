<?php

namespace csabourin\spaghettiMigrator\tests\Unit\controllers;

use Craft;
use CraftAppStub;
use csabourin\spaghettiMigrator\console\controllers\TemplateUrlReplacementController;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use yii\console\ExitCode;

class TemplateUrlReplacementControllerTest extends TestCase
{
    private string $tempDir;
    private string $templatesDir;
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        Craft::$app = new CraftAppStub();
        $this->tempDir = sys_get_temp_dir() . '/template_url_test_' . uniqid();
        $this->templatesDir = $this->tempDir . '/templates';
        $this->configDir = $this->tempDir . '/config';

        mkdir($this->templatesDir, 0777, true);
        mkdir($this->configDir, 0777, true);

        file_put_contents($this->configDir . '/migration-config.php', '<?php return ' . var_export([
            'paths' => [
                'templates' => $this->templatesDir,
            ],
            'aws' => [
                'bucket' => 'source-bucket',
            ],
            'templates' => [
                'extensions' => ['twig'],
                'envVarName' => 'DO_S3_BASE_URL',
                'backupSuffix' => '.bak',
            ],
        ], true) . ';');

        Craft::setAlias('@config', $this->configDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        $this->resetMigrationConfig();

        parent::tearDown();
    }

    public function testReplaceEmitsSingleSuccessMarkerAfterSuccessfulWork(): void
    {
        $templatePath = $this->templatesDir . '/index.twig';
        file_put_contents(
            $templatePath,
            '<img src="https://s3.amazonaws.com/source-bucket/images/example.jpg">'
        );

        $controller = new CapturingTemplateUrlReplacementController('template-url-replacement', null);
        $controller->yes = true;
        $controller->backup = false;

        $this->assertSame(ExitCode::OK, $controller->actionReplace());
        $this->assertSame(1, substr_count($controller->stdoutBuffer, '__CLI_EXIT_CODE_0__'));
        $this->assertStringContainsString(
            "{{ getenv('DO_S3_BASE_URL') }}/images/example.jpg",
            file_get_contents($templatePath)
        );
    }

    private function resetMigrationConfig(): void
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}

class CapturingTemplateUrlReplacementController extends TemplateUrlReplacementController
{
    public string $stdoutBuffer = '';
    public string $stderrBuffer = '';

    protected function stdout($string, $color = null)
    {
        $this->stdoutBuffer .= $string;

        return strlen($string);
    }

    protected function stderr($string, $color = null)
    {
        $this->stderrBuffer .= $string;

        return strlen($string);
    }
}
