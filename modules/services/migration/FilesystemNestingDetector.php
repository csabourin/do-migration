<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\base\FsInterface;

/**
 * Filesystem Nesting Detector
 *
 * Automatically detects if source and target filesystems create a nested scenario
 * where one filesystem path is a parent/child of the other.
 *
 * This prevents:
 * - Source and destination being the same physical location
 * - Infinite loops during file operations
 * - Data corruption from direct cloud-to-cloud copies
 *
 * USAGE:
 * ```php
 * $detector = new FilesystemNestingDetector();
 * if ($detector->isNestedFilesystem($sourceFs, $targetFs)) {
 *     // Use temporary local file approach
 * } else {
 *     // Safe to use direct copy
 * }
 * ```
 *
 * @author Christian Sabourin
 * @version 2.0.0
 */
class FilesystemNestingDetector
{
    /**
     * Detect if source/target filesystems create a nesting scenario
     *
     * Returns true if:
     * - Both filesystems are on the same bucket/container
     * - One filesystem path is a parent of the other
     *
     * @param FsInterface $sourceFs Source filesystem
     * @param FsInterface $targetFs Target filesystem
     * @return bool True if filesystems are nested
     */
    public function isNestedFilesystem(FsInterface $sourceFs, FsInterface $targetFs): bool
    {
        // Both must be on same bucket/container
        if (!$this->isSameBucket($sourceFs, $targetFs)) {
            return false;
        }

        $sourcePath = $this->getBasePath($sourceFs);
        $targetPath = $this->getBasePath($targetFs);

        // Check if one path is parent of the other
        return $this->isParentPath($sourcePath, $targetPath) ||
               $this->isParentPath($targetPath, $sourcePath);
    }

    /**
     * Determine if two filesystems share the same bucket/container
     *
     * Supports:
     * - AWS S3 / DigitalOcean Spaces (\craft\awss3\Fs)
     * - Google Cloud Storage (\craft\gcs\Fs) - future
     * - Azure Blob Storage (\craft\azureblob\Fs) - future
     *
     * @param FsInterface $fs1 First filesystem
     * @param FsInterface $fs2 Second filesystem
     * @return bool True if same bucket/container
     */
    private function isSameBucket(FsInterface $fs1, FsInterface $fs2): bool
    {
        // Handle AWS S3 / DigitalOcean Spaces
        if ($this->isS3Compatible($fs1) && $this->isS3Compatible($fs2)) {
            return $this->getS3Bucket($fs1) === $this->getS3Bucket($fs2) &&
                   $this->getS3Endpoint($fs1) === $this->getS3Endpoint($fs2);
        }

        // Handle Google Cloud Storage (future support)
        if ($this->isGCS($fs1) && $this->isGCS($fs2)) {
            return $this->getGCSBucket($fs1) === $this->getGCSBucket($fs2);
        }

        // Handle Azure Blob Storage (future support)
        if ($this->isAzure($fs1) && $this->isAzure($fs2)) {
            return $this->getAzureContainer($fs1) === $this->getAzureContainer($fs2);
        }

        // Different filesystem types can't be nested
        return false;
    }

    /**
     * Get the full base path including subfolder
     *
     * @param FsInterface $fs Filesystem
     * @return string Base path (empty string for root)
     */
    private function getBasePath(FsInterface $fs): string
    {
        if (property_exists($fs, 'subfolder')) {
            return trim($fs->subfolder ?? '', '/');
        }
        return '';
    }

    /**
     * Check if path1 is a parent of path2
     *
     * Examples:
     * - isParentPath('', 'images') => true (root is parent of subfolder)
     * - isParentPath('images', 'images/products') => true
     * - isParentPath('images', 'documents') => false
     *
     * @param string $path1 Potential parent path
     * @param string $path2 Potential child path
     * @return bool True if path1 is parent of path2
     */
    private function isParentPath(string $path1, string $path2): bool
    {
        // Root is parent of any non-empty subfolder
        if (empty($path1)) {
            return !empty($path2);
        }

        // Empty path2 can't be child of non-empty path1
        if (empty($path2)) {
            return false;
        }

        // Normalize paths for comparison
        $path1 = trim($path1, '/');
        $path2 = trim($path2, '/');

        // Check if path2 starts with path1 followed by /
        return strpos($path2, $path1 . '/') === 0;
    }

    // ========================================================================
    // S3-Compatible Filesystem Detection (AWS S3, DigitalOcean Spaces)
    // ========================================================================

    /**
     * Check if filesystem is S3-compatible
     *
     * @param FsInterface $fs Filesystem
     * @return bool True if S3-compatible
     */
    private function isS3Compatible(FsInterface $fs): bool
    {
        return $fs instanceof \craft\awss3\Fs;
    }

    /**
     * Get S3 bucket name
     *
     * @param FsInterface $fs Filesystem
     * @return string Bucket name
     */
    private function getS3Bucket(FsInterface $fs): string
    {
        if (!$this->isS3Compatible($fs)) {
            return '';
        }
        return $fs->bucket ?? '';
    }

    /**
     * Get S3 endpoint (for DigitalOcean Spaces detection)
     *
     * @param FsInterface $fs Filesystem
     * @return string Endpoint URL
     */
    private function getS3Endpoint(FsInterface $fs): string
    {
        if (!$this->isS3Compatible($fs)) {
            return '';
        }
        return $fs->endpoint ?? '';
    }

    // ========================================================================
    // Google Cloud Storage Detection (Future Support)
    // ========================================================================

    /**
     * Check if filesystem is Google Cloud Storage
     *
     * @param FsInterface $fs Filesystem
     * @return bool True if GCS
     */
    private function isGCS(FsInterface $fs): bool
    {
        // Check if class exists before using instanceof
        return class_exists('\craft\gcs\Fs') && $fs instanceof \craft\gcs\Fs;
    }

    /**
     * Get GCS bucket name
     *
     * @param FsInterface $fs Filesystem
     * @return string Bucket name
     */
    private function getGCSBucket(FsInterface $fs): string
    {
        if (!$this->isGCS($fs)) {
            return '';
        }
        return $fs->bucket ?? '';
    }

    // ========================================================================
    // Azure Blob Storage Detection (Future Support)
    // ========================================================================

    /**
     * Check if filesystem is Azure Blob Storage
     *
     * @param FsInterface $fs Filesystem
     * @return bool True if Azure
     */
    private function isAzure(FsInterface $fs): bool
    {
        // Check if class exists before using instanceof
        return class_exists('\craft\azureblob\Fs') && $fs instanceof \craft\azureblob\Fs;
    }

    /**
     * Get Azure container name
     *
     * @param FsInterface $fs Filesystem
     * @return string Container name
     */
    private function getAzureContainer(FsInterface $fs): string
    {
        if (!$this->isAzure($fs)) {
            return '';
        }
        return $fs->container ?? '';
    }

    // ========================================================================
    // Diagnostic Methods (For Debugging)
    // ========================================================================

    /**
     * Get detailed nesting information for debugging
     *
     * @param FsInterface $sourceFs Source filesystem
     * @param FsInterface $targetFs Target filesystem
     * @return array Diagnostic information
     */
    public function getDiagnosticInfo(FsInterface $sourceFs, FsInterface $targetFs): array
    {
        return [
            'is_nested' => $this->isNestedFilesystem($sourceFs, $targetFs),
            'same_bucket' => $this->isSameBucket($sourceFs, $targetFs),
            'source' => [
                'type' => get_class($sourceFs),
                'bucket' => $this->getBucketName($sourceFs),
                'path' => $this->getBasePath($sourceFs),
            ],
            'target' => [
                'type' => get_class($targetFs),
                'bucket' => $this->getBucketName($targetFs),
                'path' => $this->getBasePath($targetFs),
            ],
        ];
    }

    /**
     * Get bucket/container name for any filesystem type
     *
     * @param FsInterface $fs Filesystem
     * @return string Bucket/container name
     */
    private function getBucketName(FsInterface $fs): string
    {
        if ($this->isS3Compatible($fs)) {
            return $this->getS3Bucket($fs);
        }
        if ($this->isGCS($fs)) {
            return $this->getGCSBucket($fs);
        }
        if ($this->isAzure($fs)) {
            return $this->getAzureContainer($fs);
        }
        return '';
    }
}
