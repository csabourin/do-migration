<?php

namespace csabourin\craftS3SpacesMigration;

use Craft;
use craft\db\Migration;

/**
 * Installation class for the S3 Spaces Migration module
 * Handles database schema creation for migration state tracking
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createMigrationStateTable();
        $this->createFileDuplicatesTable();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%migration_file_duplicates}}');
        $this->dropTableIfExists('{{%migration_state}}');
        return true;
    }

    /**
     * Create the migration_state table for persisting migration progress
     */
    private function createMigrationStateTable(): void
    {
        if ($this->db->tableExists('{{%migration_state}}')) {
            return;
        }

        $this->createTable('{{%migration_state}}', [
            'id' => $this->primaryKey(),
            'migrationId' => $this->string(255)->notNull()->unique(),
            'sessionId' => $this->string(255),
            'phase' => $this->string(100)->notNull(),
            'status' => $this->string(50)->notNull()->defaultValue('running'), // running, completed, failed, paused
            'pid' => $this->integer(),
            'command' => $this->string(255),
            'processedCount' => $this->integer()->defaultValue(0),
            'totalCount' => $this->integer()->defaultValue(0),
            'currentBatch' => $this->integer()->defaultValue(0),
            'processedIds' => $this->mediumText(), // JSON array of processed IDs
            'stats' => $this->text(), // JSON stats object
            'errorMessage' => $this->text(),
            'checkpointFile' => $this->string(255),
            'startedAt' => $this->dateTime()->notNull(),
            'lastUpdatedAt' => $this->dateTime()->notNull(),
            'completedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%migration_state}}', ['migrationId'], false);
        $this->createIndex(null, '{{%migration_state}}', ['status'], false);
        $this->createIndex(null, '{{%migration_state}}', ['sessionId'], false);
        $this->createIndex(null, '{{%migration_state}}', ['pid'], false);
        $this->createIndex(null, '{{%migration_state}}', ['lastUpdatedAt'], false);

        Craft::info('Created migration_state table', __METHOD__);
    }

    /**
     * Create the migration_file_duplicates table for tracking duplicate file handling
     */
    private function createFileDuplicatesTable(): void
    {
        if ($this->db->tableExists('{{%migration_file_duplicates}}')) {
            return;
        }

        $this->createTable('{{%migration_file_duplicates}}', [
            'id' => $this->primaryKey(),
            'migrationId' => $this->string(255)->notNull(),
            'fileKey' => $this->string(500)->notNull(), // hash of volumeName::path
            'originalPath' => $this->string(500), // path in originals folder (source of truth)
            'tempPath' => $this->string(500), // safe staging location in quarantine
            'physicalFileHash' => $this->string(64), // MD5 hash of file content
            'assetIds' => $this->text(), // JSON array of asset IDs referencing this file
            'primaryAssetId' => $this->integer(), // the asset that gets the final file
            'status' => $this->string(50)->notNull()->defaultValue('pending'), // pending, staged, cleaned, analyzed, migrated, completed
            'volumeName' => $this->string(255),
            'relativePathInVolume' => $this->string(500),
            'fileSize' => $this->bigInteger(),
            'processedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes for common queries
        $this->createIndex(null, '{{%migration_file_duplicates}}', ['migrationId', 'fileKey'], true); // Unique constraint per migration
        $this->createIndex(null, '{{%migration_file_duplicates}}', ['migrationId'], false);
        $this->createIndex(null, '{{%migration_file_duplicates}}', ['status'], false);
        $this->createIndex(null, '{{%migration_file_duplicates}}', ['primaryAssetId'], false);
        $this->createIndex(null, '{{%migration_file_duplicates}}', ['processedAt'], false);

        Craft::info('Created migration_file_duplicates table', __METHOD__);
    }
}
