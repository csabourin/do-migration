<?php

namespace csabourin\spaghettiMigrator\services;

/**
 * Command Builder Service
 *
 * Builds console commands with appropriate flags for web interface execution.
 * This service ensures that commands run non-interactively with the correct
 * --yes and --dryRun flags based on module metadata.
 */
class CommandBuilder
{
    /**
     * @var ModuleDefinitionProvider
     */
    private $moduleProvider;

    /**
     * Constructor
     */
    public function __construct(?ModuleDefinitionProvider $moduleProvider = null)
    {
        $this->moduleProvider = $moduleProvider ?? new ModuleDefinitionProvider();
    }

    /**
     * Build a console command with appropriate flags for web execution
     *
     * @param string $moduleId Module ID from module definitions
     * @param bool $dryRun Whether to run in dry-run mode (preview only)
     * @param array $additionalArgs Additional arguments to append
     * @return string The complete command string
     * @throws \InvalidArgumentException If module not found
     */
    public function buildCommand(string $moduleId, bool $dryRun = true, array $additionalArgs = []): string
    {
        $module = $this->findModule($moduleId);

        if (!$module) {
            throw new \InvalidArgumentException("Module not found: {$moduleId}");
        }

        // Start with base command
        $command = './craft spaghetti-migrator/' . $module['command'];

        // Add --yes flag if module requires it (for non-interactive execution)
        if ($module['requiresYes'] ?? false) {
            $command .= ' --yes=1';
        }

        // Add --dryRun flag if module supports it
        if ($module['supportsDryRun'] ?? false) {
            // Special case: MissingFileFixController defaults to dryRun=true
            // So we need to explicitly set it to 0 for live mode
            if (strpos($module['command'], 'missing-file-fix') !== false) {
                // For missing-file-fix, dryRun=true is default, so:
                // - dryRun mode: don't add flag (uses default true)
                // - Live mode: add --dryRun=0
                if (!$dryRun) {
                    $command .= ' --dryRun=0';
                }
            } else {
                // For all other controllers, dryRun=false is default, so:
                // - Dry run mode: add --dryRun=1
                // - Live mode: add --dryRun=0 (or omit, but explicit is better)
                $command .= $dryRun ? ' --dryRun=1' : ' --dryRun=0';
            }
        }

        // Add any additional arguments
        foreach ($additionalArgs as $key => $value) {
            if (is_bool($value)) {
                $command .= sprintf(' --%s=%d', $key, $value ? 1 : 0);
            } elseif (is_numeric($value)) {
                $command .= sprintf(' --%s=%s', $key, $value);
            } else {
                // Escape shell arguments
                $escapedValue = escapeshellarg($value);
                $command .= sprintf(' --%s=%s', $key, $escapedValue);
            }
        }

        return $command;
    }

    /**
     * Build a command string for display purposes (without escaping)
     *
     * @param string $moduleId Module ID
     * @param bool $dryRun Whether in dry-run mode
     * @param array $additionalArgs Additional arguments
     * @return string Human-readable command string
     */
    public function buildDisplayCommand(string $moduleId, bool $dryRun = true, array $additionalArgs = []): string
    {
        $module = $this->findModule($moduleId);

        if (!$module) {
            return "Module not found: {$moduleId}";
        }

        $parts = ['./craft', 'spaghetti-migrator/' . $module['command']];

        if ($module['requiresYes'] ?? false) {
            $parts[] = '--yes=1';
        }

        if ($module['supportsDryRun'] ?? false) {
            if (strpos($module['command'], 'missing-file-fix') !== false) {
                if (!$dryRun) {
                    $parts[] = '--dryRun=0';
                }
            } else {
                $parts[] = $dryRun ? '--dryRun=1' : '--dryRun=0';
            }
        }

        foreach ($additionalArgs as $key => $value) {
            if (is_bool($value)) {
                $parts[] = sprintf('--%s=%d', $key, $value ? 1 : 0);
            } else {
                $parts[] = sprintf('--%s=%s', $key, $value);
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Get module metadata
     *
     * @param string $moduleId Module ID
     * @return array|null Module definition or null if not found
     */
    public function getModuleMetadata(string $moduleId): ?array
    {
        return $this->findModule($moduleId);
    }

    /**
     * Check if a module requires confirmation (has --yes flag)
     *
     * @param string $moduleId Module ID
     * @return bool
     */
    public function requiresConfirmation(string $moduleId): bool
    {
        $module = $this->findModule($moduleId);
        return $module ? ($module['requiresYes'] ?? false) : false;
    }

    /**
     * Check if a module supports dry-run mode
     *
     * @param string $moduleId Module ID
     * @return bool
     */
    public function supportsDryRun(string $moduleId): bool
    {
        $module = $this->findModule($moduleId);
        return $module ? ($module['supportsDryRun'] ?? false) : false;
    }

    /**
     * Check if a module is critical
     *
     * @param string $moduleId Module ID
     * @return bool
     */
    public function isCritical(string $moduleId): bool
    {
        $module = $this->findModule($moduleId);
        return $module ? ($module['critical'] ?? false) : false;
    }

    /**
     * Check if a module requires arguments
     *
     * @param string $moduleId Module ID
     * @return bool
     */
    public function requiresArgs(string $moduleId): bool
    {
        $module = $this->findModule($moduleId);
        return $module ? ($module['requiresArgs'] ?? false) : false;
    }

    /**
     * Find a module by ID across all phases
     *
     * @param string $moduleId Module ID to find
     * @return array|null Module definition or null if not found
     */
    private function findModule(string $moduleId): ?array
    {
        $definitions = $this->moduleProvider->getModuleDefinitions();

        foreach ($definitions as $phase) {
            if (!isset($phase['modules'])) {
                continue;
            }

            foreach ($phase['modules'] as $module) {
                if ($module['id'] === $moduleId) {
                    return $module;
                }
            }
        }

        return null;
    }

    /**
     * Get all modules that require --yes flag
     *
     * @return array Array of module IDs
     */
    public function getModulesRequiringYes(): array
    {
        $moduleIds = [];
        $definitions = $this->moduleProvider->getModuleDefinitions();

        foreach ($definitions as $phase) {
            if (!isset($phase['modules'])) {
                continue;
            }

            foreach ($phase['modules'] as $module) {
                if ($module['requiresYes'] ?? false) {
                    $moduleIds[] = $module['id'];
                }
            }
        }

        return $moduleIds;
    }

    /**
     * Get all modules that support --dryRun flag
     *
     * @return array Array of module IDs
     */
    public function getModulesSupportingDryRun(): array
    {
        $moduleIds = [];
        $definitions = $this->moduleProvider->getModuleDefinitions();

        foreach ($definitions as $phase) {
            if (!isset($phase['modules'])) {
                continue;
            }

            foreach ($phase['modules'] as $module) {
                if ($module['supportsDryRun'] ?? false) {
                    $moduleIds[] = $module['id'];
                }
            }
        }

        return $moduleIds;
    }

    /**
     * Get recommended execution mode for a module
     *
     * @param string $moduleId Module ID
     * @return string 'preview' (dry-run), 'live', 'manual' (requires CLI), or 'unknown'
     */
    public function getRecommendedExecutionMode(string $moduleId): string
    {
        $module = $this->findModule($moduleId);

        if (!$module) {
            return 'unknown';
        }

        // Modules requiring arguments must be run manually via CLI
        if ($module['requiresArgs'] ?? false) {
            return 'manual';
        }

        // Modules with dry-run support should default to preview
        if ($module['supportsDryRun'] ?? false) {
            return 'preview';
        }

        // Other modules can run live directly
        return 'live';
    }

    /**
     * Validate execution mode for a module
     *
     * @param string $moduleId Module ID
     * @param string $mode Execution mode ('preview' or 'live')
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateExecutionMode(string $moduleId, string $mode): array
    {
        $module = $this->findModule($moduleId);

        if (!$module) {
            return ['valid' => false, 'error' => "Module not found: {$moduleId}"];
        }

        // Check if module requires manual execution
        if ($module['requiresArgs'] ?? false) {
            return [
                'valid' => false,
                'error' => 'This module requires arguments and must be run manually via CLI'
            ];
        }

        // Check if trying to use preview mode on non-dry-run module
        if ($mode === 'preview' && !($module['supportsDryRun'] ?? false)) {
            return [
                'valid' => false,
                'error' => 'This module does not support dry-run mode'
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Check if a command requires the --yes flag
     *
     * This method searches through all module definitions to find the module
     * with the matching command and returns whether it requires confirmation.
     *
     * @param string $command The command string (e.g., 'volume-config/configure-all')
     * @return bool True if the command requires --yes flag
     */
    public function commandRequiresYes(string $command): bool
    {
        // Normalize the command
        $command = ltrim($command, '/');
        if (str_starts_with($command, 'spaghetti-migrator/')) {
            $command = substr($command, strlen('spaghetti-migrator/'));
        }

        // Search through all modules to find the one with this command
        $definitions = $this->moduleProvider->getModuleDefinitions();

        foreach ($definitions as $phase) {
            if (!isset($phase['modules'])) {
                continue;
            }

            foreach ($phase['modules'] as $module) {
                if (isset($module['command']) && $module['command'] === $command) {
                    return $module['requiresYes'] ?? false;
                }
            }
        }

        return false;
    }
}
