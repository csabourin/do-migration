# ImageMigrationController Refactoring Plan

## Overview
Refactor the 7,079-line ImageMigrationController into smaller, maintainable modules while preserving all enterprise-grade features, safeguards, and functionality.

## Design Principles
- **Single Responsibility**: Each module handles one specific concern
- **Dependency Injection**: All dependencies passed via constructor
- **Centralized Configuration**: Use existing MigrationConfig
- **Preserve Safeguards**: All checkpoints, error handling, and recovery mechanisms remain
- **Enterprise-Grade**: Maintain batch processing, transactions, and rollback capabilities
- **Well-Documented**: Clear docblocks and inline comments

## Module Architecture

### 1. **MigrationOrchestrator** (Main Module)
**Location**: `modules/services/MigrationOrchestrator.php`
**Purpose**: Main orchestration layer that coordinates all other services
**Responsibilities**:
- Execute migration phases in correct order
- Manage checkpoint/resume flow
- Coordinate between services
- Handle user prompts and confirmations
- Track overall progress and statistics
- Manage migration lifecycle (init, phases, cleanup)

**Dependencies**:
- InventoryBuilder
- InlineLinkingService
- DuplicateResolutionService
- LinkRepairService
- ConsolidationService
- QuarantineService
- VerificationService
- BackupService
- OptimizedImagesService
- CheckpointManager (existing)
- ChangeLogManager (existing)
- ErrorRecoveryManager (existing)
- RollbackEngine (existing)
- MigrationConfig (existing)

### 2. **InventoryBuilder**
**Location**: `modules/services/migration/InventoryBuilder.php`
**Purpose**: Build asset and file inventories
**Responsibilities**:
- Build asset inventory with batching (`buildAssetInventoryBatched`)
- Build file inventory from filesystems (`buildFileInventory`)
- Analyze asset-file relationships (`analyzeAssetFileLinks`)
- Build RTE field maps (`buildRteFieldMap`)
- Build asset lookup indexes (`buildAssetLookup`, `buildFileSearchIndexes`)
- Track processed assets/files

**Methods from Controller**:
- `buildAssetInventoryBatched()` (lines 2403-2514)
- `buildFileInventory()` (lines 4284-4356)
- `scanFilesystem()` (lines 4358-4419)
- `analyzeAssetFileLinks()` (lines 4421-4590)
- `buildRteFieldMap()` (lines 4592-4615)
- `buildAssetLookup()` (lines 4691-4698)
- `buildAssetLookupArray()` (lines 4699-4715)
- `buildFileSearchIndexes()` (lines 4760-4806)

### 3. **InlineLinkingService**
**Location**: `modules/services/migration/InlineLinkingService.php`
**Purpose**: Link inline images in RTE fields to assets
**Responsibilities**:
- Scan database for inline image references
- Link inline images to asset records
- Process batches of inline images
- Map fields to columns
- Estimate inline linking work

**Methods from Controller**:
- `linkInlineImagesBatched()` (lines 2516-2659)
- `processInlineImageBatch()` (lines 2661-2831)
- `mapFieldsToColumns()` (lines 4617-4689)
- `findAssetByUrl()` (lines 4717-4758)
- `estimateInlineLinking()` (lines 5418-5481)

### 4. **DuplicateResolutionService**
**Location**: `modules/services/migration/DuplicateResolutionService.php`
**Purpose**: Handle file and asset duplicates
**Responsibilities**:
- Analyze file duplicates shared by multiple assets
- Stage duplicate files to temp location
- Determine primary assets
- Delete unused duplicate asset records
- Verify file safety before deletion
- Cleanup temp files
- Resolve duplicate asset records

**Methods from Controller**:
- `analyzeFileDuplicates()` (lines 6355-6494)
- `stageFilesToTemp()` (lines 6496-6633)
- `verifyFileSafety()` (lines 6635-6715)
- `determineActiveAssets()` (lines 6717-6779)
- `selectPrimaryAsset()` (lines 6781-6834)
- `canSafelyDeleteSource()` (lines 6836-6915)
- `deleteUnusedDuplicateAssets()` (lines 6943-7037)
- `cleanupTempFiles()` (lines 7039-7079)
- `resolveDuplicateAssets()` (lines 2833-3014)
- `getDuplicateRecord()` (lines 6917-6941)

### 5. **LinkRepairService**
**Location**: `modules/services/migration/LinkRepairService.php`
**Purpose**: Fix broken asset-file links
**Responsibilities**:
- Fix broken links in batches
- Find files for orphaned assets
- Update asset paths
- Fuzzy filename matching
- Prioritize file candidates

**Methods from Controller**:
- `fixBrokenLinksBatched()` (lines 3016-3119)
- `fixSingleBrokenLink()` (lines 3121-3279)
- `updateAssetPath()` (lines 3281-3336)
- `findFileForAsset()` (lines 4808-4942)
- `normalizeFilename()` (lines 4944-4952)
- `getExtensionFamily()` (lines 4954-4968)
- `calculateSimilarity()` (lines 4970-4981)
- `findFuzzyMatches()` (lines 4983-5035)
- `prioritizeFile()` (lines 5037-5076)

### 6. **ConsolidationService**
**Location**: `modules/services/migration/ConsolidationService.php`
**Purpose**: Consolidate files to correct locations
**Responsibilities**:
- Consolidate used files in batches
- Move assets to target volume/folder
- Handle path transformations
- Track consolidation progress

**Methods from Controller**:
- `consolidateUsedFilesBatched()` (lines 3338-3441)
- `consolidateSingleAsset()` (lines 3443-3501)
- `isAssetProcessed()` (lines 3503-3509)
- `markAssetProcessed()` (lines 3511-3519)
- `markAssetsProcessedBatch()` (lines 3521-3531)

### 7. **QuarantineService**
**Location**: `modules/services/migration/QuarantineService.php`
**Purpose**: Quarantine unused files and assets
**Responsibilities**:
- Quarantine unused files in batches
- Quarantine orphaned files
- Quarantine unused assets
- Move files to quarantine volume
- Restore original filenames

**Methods from Controller**:
- `quarantineUnusedFilesBatched()` (lines 3533-3629)
- `quarantineSingleFile()` (lines 3631-3686)
- `quarantineSingleAsset()` (lines 3688-3737)
- `restoreOriginalFilename()` (lines 3739-3774)

### 8. **FileOperationsService**
**Location**: `modules/services/migration/FileOperationsService.php`
**Purpose**: Low-level file operations (move, copy, delete)
**Responsibilities**:
- Move assets within same volume
- Move assets across volumes
- Copy files to assets
- Extract filesystem listing data
- Check if path is in originals folder
- Manual asset moves (for special cases)

**Methods from Controller**:
- `moveAssetSameVolume()` (lines 5093-5114)
- `moveAssetManual()` (lines 5116-5172)
- `moveAssetCrossVolume()` (lines 5174-5216)
- `copyFileToAsset()` (lines 5218-5337)
- `extractFsListingData()` (lines 5339-5416)
- `isInOriginalsFolder()` (lines 5078-5091)

### 9. **VerificationService**
**Location**: `modules/services/migration/VerificationService.php`
**Purpose**: Verify migration completeness and correctness
**Responsibilities**:
- Perform full verification
- Perform sample verification
- Export missing files to CSV
- Verify migration results
- Update optimisedImages subfolder

**Methods from Controller**:
- `performCleanupAndVerification()` (lines 5483-5553)
- `verifyMigrationFull()` (lines 5555-5602)
- `verifyMigrationSample()` (lines 5604-5634)
- `verifyMigration()` (lines 5714-5741)
- `exportMissingFilesToCsv()` (lines 6245-6284)
- `updateOptimisedImagesSubfolder()` (lines 5636-5712)

### 10. **BackupService**
**Location**: `modules/services/migration/BackupService.php`
**Purpose**: Handle backup operations
**Responsibilities**:
- Create full backups
- Create database backups
- Create Craft native backups
- Verify backup integrity

**Methods from Controller**:
- `createBackup()` (lines 5846-5932)
- `createDatabaseBackup()` (lines 6053-6124)
- `createCraftBackup()` (lines 6126-6172)
- `ensurePhase1ResultsTable()` (lines 5934-5979)
- `savePhase1Results()` (lines 5981-6024)
- `loadPhase1Results()` (lines 6026-6051)

### 11. **OptimizedImagesService**
**Location**: `modules/services/migration/OptimizedImagesService.php`
**Purpose**: Handle optimized images at bucket root
**Responsibilities**:
- Handle optimized images migration
- Build file index for optimized migration
- Migrate optimized assets
- Cleanup optimized files
- Detect transform files

**Methods from Controller**:
- `handleOptimisedImagesAtRoot()` (lines 753-917)
- `buildFileIndexForOptimisedMigration()` (lines 919-989)
- `migrateOptimisedAsset()` (lines 991-1157)
- `cleanupOptimisedFile()` (lines 1159-1184)
- `isTransformFile()` (lines 708-732)

### 12. **MigrationReporter** (New Helper)
**Location**: `modules/services/migration/MigrationReporter.php`
**Purpose**: Handle all reporting and output formatting
**Responsibilities**:
- Print phase headers
- Print analysis reports
- Print planned operations
- Print inline linking results
- Print final reports
- Format durations, bytes, ages
- Print progress legends

**Methods from Controller**:
- `printHeader()` (lines 4120-4129)
- `printSuccessFooter()` (lines 4131-4141)
- `printPhaseHeader()` (lines 4143-4151)
- `printProgressLegend()` (lines 4153-4177)
- `printAnalysisReport()` (lines 6174-6213)
- `printPlannedOperations()` (lines 6215-6227)
- `printInlineLinkingResults()` (lines 6229-6243)
- `printFinalReport()` (lines 6286-6321)
- `formatBytes()` (lines 3940-3953)
- `formatAge()` (lines 4076-4092)
- `formatDuration()` (lines 4094-4118)

### 13. **ValidationService** (New Helper)
**Location**: `modules/services/migration/ValidationService.php`
**Purpose**: Handle validation and health checks
**Responsibilities**:
- Validate configuration
- Perform health checks
- Validate disk space
- Check filesystem accessibility

**Methods from Controller**:
- `validateConfiguration()` (lines 4179-4240)
- `performHealthCheck()` (lines 3820-3877)
- `validateDiskSpace()` (lines 3879-3938)
- `assertFsAccessible()` (lines 3955-3966)
- `getFsOperatorFromService()` (lines 4242-4259)
- `getFsPrefix()` (lines 4261-4282)

## Shared Utilities

All services will share:
- **MigrationConfig**: Centralized configuration
- **CheckpointManager**: Save/restore state
- **ChangeLogManager**: Track all changes
- **ErrorRecoveryManager**: Retry logic
- **RollbackEngine**: Rollback operations
- **ProgressTracker**: Track progress
- **MigrationLock**: Prevent concurrent migrations

## Data Flow

```
ImageMigrationController (CLI Interface)
    ↓
MigrationOrchestrator (Orchestration)
    ↓
[Phase 0] ValidationService → BackupService
    ↓
[Phase 0.5] OptimizedImagesService
    ↓
[Phase 1] InventoryBuilder
    ↓
[Phase 1.5] InlineLinkingService
    ↓
[Phase 1.7] DuplicateResolutionService (staging)
    ↓
[Phase 1.8] DuplicateResolutionService (resolution)
    ↓
[Phase 2] LinkRepairService
    ↓
[Phase 3] ConsolidationService
    ↓
[Phase 4] QuarantineService
    ↓
[Phase 4.5] DuplicateResolutionService (cleanup)
    ↓
[Phase 5] VerificationService
    ↓
MigrationReporter (Final Report)
```

## Error Handling Strategy

Each service will:
1. Accept ErrorRecoveryManager via constructor
2. Track errors using type-specific counters
3. Use retry logic for transient failures
4. Throw exceptions for critical errors
5. Return structured results with success/failure counts

## Checkpoint/Resume Strategy

Each service will:
1. Accept CheckpointManager via constructor
2. Save checkpoints at appropriate intervals
3. Support resuming from mid-operation
4. Track processed IDs to avoid duplicates

## Change Logging Strategy

Each service will:
1. Accept ChangeLogManager via constructor
2. Log all destructive operations (moves, deletes, updates)
3. Support rollback via RollbackEngine
4. Flush logs at phase boundaries

## Testing Strategy

1. Unit tests for each service (isolated)
2. Integration tests for orchestrator
3. End-to-end migration test
4. Resume/checkpoint tests
5. Rollback tests
6. Error recovery tests

## Migration Path

The ImageMigrationController will remain unchanged. The new modular architecture will coexist, allowing:
1. Gradual migration of functionality
2. A/B testing between old and new
3. Rollback to original if needed
4. Eventually, ImageMigrationController can be deprecated

## Implementation Order

1. ✅ Analysis complete
2. 📝 Create plan (this document)
3. Create shared utilities (MigrationReporter, ValidationService)
4. Create InventoryBuilder
5. Create FileOperationsService
6. Create BackupService
7. Create OptimizedImagesService
8. Create InlineLinkingService
9. Create DuplicateResolutionService
10. Create LinkRepairService
11. Create ConsolidationService
12. Create QuarantineService
13. Create VerificationService
14. Create MigrationOrchestrator
15. Integration testing
16. Documentation

## Success Criteria

- ✓ All services < 500 lines each
- ✓ Clear single responsibility
- ✓ 100% feature parity with original
- ✓ All safeguards preserved
- ✓ All tests passing
- ✓ Well-documented code
- ✓ Enterprise-grade quality maintained
