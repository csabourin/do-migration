/**
 * AWS to DigitalOcean Migration Dashboard
 * Interactive dashboard for orchestrating the complete migration
 */

(function() {
    'use strict';

    const MigrationDashboard = {
        // Configuration getter - reads dynamically to avoid timing issues
        get config() {
            return window.migrationDashboard || {};
        },

        // State
        state: {
            runningModules: new Set(),
            completedModules: new Set(),
            pollingIntervals: new Map(),
        },

        /**
         * Initialize the dashboard
         */
        init: function() {
            // Verify config is loaded
            if (!window.migrationDashboard) {
                console.error('Migration Dashboard config not found! window.migrationDashboard is undefined.');
                return;
            }
            console.log('Migration Dashboard config:', this.config);

            this.attachEventListeners();
            this.loadState();
            this.checkStatus();
            console.log('Migration Dashboard initialized');
        },

        /**
         * Attach event listeners
         */
        attachEventListeners: function() {
            const self = this;

            // Run module buttons
            document.querySelectorAll('.run-module-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const command = this.getAttribute('data-command');
                    const dryRun = this.getAttribute('data-dry-run') === 'true';
                    const supportsResume = this.getAttribute('data-supports-resume') === 'true';
                    const resumeRequested = this.getAttribute('data-resume') === 'true';

                    self.runCommand(command, {
                        dryRun: dryRun,
                        resume: supportsResume && resumeRequested ? '1' : '0'
                    });
                });
            });

            // Test connection button
            const testConnectionBtn = document.getElementById('test-connection-btn');
            if (testConnectionBtn) {
                testConnectionBtn.addEventListener('click', () => {
                    this.testConnection();
                });
            }

            // View checkpoint button
            const viewCheckpointBtn = document.getElementById('view-checkpoint-btn');
            if (viewCheckpointBtn) {
                viewCheckpointBtn.addEventListener('click', () => {
                    this.showCheckpoints();
                });
            }

            // View logs buttons
            document.querySelectorAll('.view-logs-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const moduleCard = this.closest('.module-card');
                    const outputContent = moduleCard.querySelector('.output-content');
                    if (outputContent && outputContent.textContent.trim()) {
                        self.showOutputModal(outputContent.textContent);
                    } else {
                        Craft.cp.displayNotice('No logs available for this module yet');
                    }
                });
            });

            // Clear output buttons
            document.querySelectorAll('.clear-output-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const moduleCard = this.closest('.module-card');
                    const outputSection = moduleCard.querySelector('.module-output');
                    const outputContent = moduleCard.querySelector('.output-content');
                    if (outputContent) {
                        outputContent.textContent = '';
                        outputSection.style.display = 'none';
                    }
                });
            });

            // Cancel module buttons
            document.querySelectorAll('.cancel-module-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const moduleCard = this.closest('.module-card');
                    const runBtn = moduleCard.querySelector('.run-module-btn');
                    const command = runBtn.getAttribute('data-command');
                    if (command) {
                        self.cancelCommand(moduleCard, command);
                    }
                });
            });

            // Rollback button
            const rollbackBtn = document.getElementById('rollback-btn');
            if (rollbackBtn) {
                rollbackBtn.addEventListener('click', () => {
                    this.showRollbackModal();
                });
            }

            // Confirm rollback button
            const confirmRollbackBtn = document.getElementById('confirm-rollback-btn');
            if (confirmRollbackBtn) {
                confirmRollbackBtn.addEventListener('click', () => {
                    this.confirmRollback();
                });
            }

            // View changelog button
            const viewChangelogBtn = document.getElementById('view-changelog-btn');
            if (viewChangelogBtn) {
                viewChangelogBtn.addEventListener('click', () => {
                    this.showChangelog();
                });
            }

            // Modal close buttons
            document.querySelectorAll('.modal-close').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.closest('.modal').style.display = 'none';
                });
            });

            // Close modal on outside click
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.style.display = 'none';
                    }
                });
            });
        },

        /**
         * Load saved state from localStorage
         */
        loadState: function() {
            try {
                const saved = localStorage.getItem('migrationDashboardState');
                if (saved) {
                    const state = JSON.parse(saved);
                    this.state.completedModules = new Set(state.completedModules || []);
                    this.updateModuleStates();
                }
            } catch (e) {
                console.error('Failed to load state:', e);
            }
        },

        /**
         * Save state to localStorage
         */
        saveState: function() {
            try {
                const state = {
                    completedModules: Array.from(this.state.completedModules),
                    lastUpdate: new Date().toISOString()
                };
                localStorage.setItem('migrationDashboardState', JSON.stringify(state));
            } catch (e) {
                console.error('Failed to save state:', e);
            }
        },

        /**
         * Check migration status from server
         */
        checkStatus: function() {
            const self = this;

            fetch(this.config.statusUrl, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update state based on server response
                    if (data.state && data.state.completedModules) {
                        data.state.completedModules.forEach(module => {
                            this.state.completedModules.add(module);
                        });
                        this.updateModuleStates();
                        this.saveState();
                    }
                }
            })
            .catch(error => {
                console.error('Failed to check status:', error);
            });
        },

        /**
         * Update UI based on module states
         */
        updateModuleStates: function() {
            this.state.completedModules.forEach(moduleId => {
                const moduleCard = document.querySelector(`[data-module-id="${moduleId}"]`);
                if (moduleCard) {
                    moduleCard.classList.add('module-completed');
                    const statusIndicator = moduleCard.querySelector('.status-indicator');
                    if (statusIndicator) {
                        statusIndicator.textContent = '✓';
                        statusIndicator.classList.add('completed');
                    }
                }
            });
        },

        /**
         * Run a migration command
         */
        runCommand: function(command, args = {}) {
            const moduleCard = document.querySelector(`[data-command="${command}"]`);
            if (!moduleCard) {
                console.error('Module card not found for command:', command);
                return;
            }

            // Check if already running
            if (this.state.runningModules.has(command)) {
                Craft.cp.displayNotice('This module is already running');
                return;
            }

            // Mark as running
            this.state.runningModules.add(command);
            this.setModuleRunning(moduleCard, true);

            // Show progress
            const progressSection = moduleCard.querySelector('.module-progress');
            if (progressSection) {
                progressSection.style.display = 'block';
            }

            // Determine if this is a long-running command that should use streaming
            const streamingCommands = [
                'image-migration/migrate',
                'image-migration/monitor',
                'image-migration/rollback',
                'transform-pre-generation/generate',
                'transform-pre-generation/warmup',
                'url-replacement/replace-s3-urls',
                'migration-check/check',
                'migration-diag/analyze'
            ];

            const useStreaming = streamingCommands.includes(command);

            if (useStreaming) {
                this.runCommandStreaming(moduleCard, command, args);
            } else {
                this.runCommandStandard(moduleCard, command, args);
            }
        },

        /**
         * Run command with streaming output (SSE)
         */
        runCommandStreaming: function(moduleCard, command, args = {}) {
            // Prepare request
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);
            formData.append('command', command);
            formData.append('args', JSON.stringify(args));
            formData.append('stream', '1');
            if (args.dryRun) {
                formData.append('dryRun', '1');
            }

            // Clear previous output
            this.showModuleOutput(moduleCard, '');

            // Create EventSource for SSE (we'll use fetch instead for POST)
            fetch(this.config.runCommandUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                const processStream = () => {
                    return reader.read().then(({done, value}) => {
                        if (done) {
                            return;
                        }

                        // Decode the chunk
                        buffer += decoder.decode(value, {stream: true});

                        // Process SSE events
                        const lines = buffer.split('\n\n');
                        buffer = lines.pop(); // Keep incomplete event in buffer

                        lines.forEach(eventBlock => {
                            if (!eventBlock.trim()) return;

                            const lines = eventBlock.split('\n');
                            let eventType = 'message';
                            let eventData = '';

                            lines.forEach(line => {
                                if (line.startsWith('event: ')) {
                                    eventType = line.substring(7);
                                } else if (line.startsWith('data: ')) {
                                    eventData = line.substring(6);
                                }
                            });

                            this.handleStreamEvent(moduleCard, command, eventType, eventData, args.dryRun);
                        });

                        return processStream();
                    });
                };

                return processStream();
            })
            .catch(error => {
                this.handleCommandError(moduleCard, command, error);
            })
            .finally(() => {
                this.state.runningModules.delete(command);
                this.setModuleRunning(moduleCard, false);
            });
        },

        /**
         * Run command without streaming (original method)
         */
        runCommandStandard: function(moduleCard, command, args = {}) {
            // Prepare request
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);
            formData.append('command', command);
            formData.append('args', JSON.stringify(args));
            if (args.dryRun) {
                formData.append('dryRun', '1');
            }

            // Execute command
            fetch(this.config.runCommandUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                this.handleCommandResponse(moduleCard, command, data, args.dryRun);
            })
            .catch(error => {
                this.handleCommandError(moduleCard, command, error);
            })
            .finally(() => {
                this.state.runningModules.delete(command);
                this.setModuleRunning(moduleCard, false);
            });
        },

        /**
         * Handle streaming event
         */
        handleStreamEvent: function(moduleCard, command, eventType, eventData, isDryRun) {
            try {
                const data = JSON.parse(eventData);

                switch (eventType) {
                    case 'start':
                        this.showModuleOutput(moduleCard, 'Command started...\n');
                        this.updateModuleProgress(moduleCard, 0, 'Starting...');
                        break;

                    case 'output':
                        // Append output line
                        const outputContent = moduleCard.querySelector('.output-content');
                        if (outputContent) {
                            const currentOutput = outputContent.textContent;
                            outputContent.textContent = currentOutput + data.line + '\n';
                            outputContent.scrollTop = outputContent.scrollHeight;
                        }

                        // Try to parse progress from output
                        this.parseProgressFromOutput(moduleCard, data.line);
                        break;

                    case 'complete':
                        if (data.cancelled) {
                            this.updateModuleProgress(moduleCard, 0, 'Cancelled');
                            Craft.cp.displayNotice('Command was cancelled');
                        } else if (data.success) {
                            this.updateModuleProgress(moduleCard, 100, 'Completed');
                            if (!isDryRun) {
                                this.markModuleCompleted(moduleCard, command);
                                Craft.cp.displayNotice('Command completed successfully');
                            } else {
                                Craft.cp.displayNotice('Dry run completed successfully');
                            }
                        } else {
                            this.updateModuleProgress(moduleCard, 0, 'Failed');
                            Craft.cp.displayError('Command failed: Exit code ' + data.exitCode);
                        }
                        break;

                    case 'cancelled':
                        this.updateModuleProgress(moduleCard, 0, 'Cancelling...');
                        this.showModuleOutput(moduleCard, '\n[Process termination in progress...]\n');
                        break;

                    case 'error':
                        this.showModuleOutput(moduleCard, 'Error: ' + (data.error || 'Unknown error'));
                        Craft.cp.displayError('Command error: ' + (data.error || 'Unknown error'));
                        break;
                }
            } catch (e) {
                console.error('Failed to parse stream event:', e, eventData);
            }
        },

        /**
         * Parse progress information from command output
         */
        parseProgressFromOutput: function(moduleCard, line) {
            // Look for progress patterns in output
            // Examples: "Progress: 45%", "Processed 150/300", "45% complete"

            // Pattern 1: "X%"
            let match = line.match(/(\d+)%/);
            if (match) {
                const percent = parseInt(match[1]);
                this.updateModuleProgress(moduleCard, percent, line.trim());
                return;
            }

            // Pattern 2: "X/Y" or "X of Y"
            match = line.match(/(\d+)\s*(?:\/|of)\s*(\d+)/);
            if (match) {
                const current = parseInt(match[1]);
                const total = parseInt(match[2]);
                const percent = (current / total) * 100;
                this.updateModuleProgress(moduleCard, percent, line.trim());
                return;
            }

            // Pattern 3: Status messages
            if (line.includes('Starting') || line.includes('Initializing')) {
                this.updateModuleProgress(moduleCard, 5, line.trim());
            } else if (line.includes('Processing') || line.includes('Migrating')) {
                // Keep current progress, just update text
                const progressText = moduleCard.querySelector('.progress-text');
                if (progressText) {
                    progressText.textContent = line.trim();
                }
            } else if (line.includes('Complete') || line.includes('Finished')) {
                this.updateModuleProgress(moduleCard, 100, line.trim());
            }
        },

        /**
         * Handle command response
         */
        handleCommandResponse: function(moduleCard, command, data, isDryRun) {
            if (data.success) {
                // Show output
                this.showModuleOutput(moduleCard, data.output);

                // Update progress to 100%
                this.updateModuleProgress(moduleCard, 100, 'Completed');

                // Mark as completed (if not dry run)
                if (!isDryRun) {
                    this.markModuleCompleted(moduleCard, command);
                    Craft.cp.displayNotice('Command completed successfully');
                } else {
                    Craft.cp.displayNotice('Dry run completed successfully');
                }
            } else {
                this.showModuleOutput(moduleCard, 'Error: ' + (data.error || 'Unknown error'));
                Craft.cp.displayError('Command failed: ' + (data.error || 'Unknown error'));
            }
        },

        /**
         * Handle command error
         */
        handleCommandError: function(moduleCard, command, error) {
            console.error('Command error:', error);
            this.showModuleOutput(moduleCard, 'Error: ' + error.message);
            Craft.cp.displayError('Failed to execute command');
        },

        /**
         * Set module running state
         */
        setModuleRunning: function(moduleCard, isRunning) {
            if (isRunning) {
                moduleCard.classList.add('module-running');
                const runBtn = moduleCard.querySelector('.run-module-btn:not([data-dry-run])');
                if (runBtn) {
                    runBtn.disabled = true;
                    runBtn.classList.add('loading');
                }
                // Show cancel button for streaming commands
                const cancelBtn = moduleCard.querySelector('.cancel-module-btn');
                if (cancelBtn) {
                    cancelBtn.style.display = 'inline-block';
                    cancelBtn.disabled = false;
                    cancelBtn.classList.remove('cancelling');
                    cancelBtn.textContent = 'Cancel';
                }
                // Show progress section
                const progressSection = moduleCard.querySelector('.module-progress');
                if (progressSection) {
                    progressSection.style.display = 'block';
                }
            } else {
                moduleCard.classList.remove('module-running');
                const runBtn = moduleCard.querySelector('.run-module-btn:not([data-dry-run])');
                if (runBtn) {
                    runBtn.disabled = false;
                    runBtn.classList.remove('loading');
                }
                // Hide cancel button
                const cancelBtn = moduleCard.querySelector('.cancel-module-btn');
                if (cancelBtn) {
                    cancelBtn.style.display = 'none';
                }
            }
        },

        /**
         * Update module progress
         */
        updateModuleProgress: function(moduleCard, percent, text) {
            const progressFill = moduleCard.querySelector('.progress-fill');
            const progressPercent = moduleCard.querySelector('.progress-percent');
            const progressText = moduleCard.querySelector('.progress-text');

            if (progressFill) {
                progressFill.style.width = percent + '%';
            }
            if (progressPercent) {
                progressPercent.textContent = Math.round(percent) + '%';
            }
            if (progressText) {
                progressText.textContent = text;
            }
        },

        /**
         * Show module output
         */
        showModuleOutput: function(moduleCard, output) {
            const outputSection = moduleCard.querySelector('.module-output');
            const outputContent = moduleCard.querySelector('.output-content');

            if (outputSection && outputContent) {
                outputContent.textContent = output;
                outputSection.style.display = 'block';

                // Auto-scroll to bottom
                outputContent.scrollTop = outputContent.scrollHeight;
            }
        },

        /**
         * Mark module as completed
         */
        markModuleCompleted: function(moduleCard, command) {
            moduleCard.classList.add('module-completed');
            const statusIndicator = moduleCard.querySelector('.status-indicator');
            if (statusIndicator) {
                statusIndicator.textContent = '✓';
                statusIndicator.classList.add('completed');
            }

            // Save to state
            const moduleId = moduleCard.getAttribute('data-module-id');
            if (moduleId) {
                this.state.completedModules.add(moduleId);
                this.saveState();
            }
        },

        /**
         * Test DO Spaces connection
         */
        testConnection: function() {
            const btn = document.getElementById('test-connection-btn');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('loading');
            }

            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);

            fetch(this.config.testConnectionUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Craft.cp.displayNotice(data.message || 'Connection test successful');
                } else {
                    const errorMsg = data.errors ? data.errors.join(', ') : data.error || 'Connection test failed';
                    Craft.cp.displayError(errorMsg);
                }
            })
            .catch(error => {
                console.error('Connection test error:', error);
                Craft.cp.displayError('Failed to test connection');
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('loading');
                }
            });
        },

        /**
         * Cancel a running command
         */
        cancelCommand: function(moduleCard, command) {
            if (!confirm('Are you sure you want to cancel this command? The process will be terminated.')) {
                return;
            }

            const cancelBtn = moduleCard.querySelector('.cancel-module-btn');
            if (cancelBtn) {
                cancelBtn.disabled = true;
                cancelBtn.classList.add('cancelling');
                cancelBtn.textContent = 'Cancelling...';
            }

            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);
            formData.append('command', command);

            fetch(this.config.cancelCommandUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Craft.cp.displayNotice(data.message || 'Command cancellation requested');
                    this.showModuleOutput(moduleCard, '\n[Cancellation requested - process will terminate shortly]\n');
                } else {
                    Craft.cp.displayError(data.error || 'Failed to cancel command');
                    // Re-enable cancel button if cancellation failed
                    if (cancelBtn) {
                        cancelBtn.disabled = false;
                        cancelBtn.classList.remove('cancelling');
                        cancelBtn.textContent = 'Cancel';
                    }
                }
            })
            .catch(error => {
                console.error('Cancel command error:', error);
                Craft.cp.displayError('Failed to cancel command');
                // Re-enable cancel button on error
                if (cancelBtn) {
                    cancelBtn.disabled = false;
                    cancelBtn.classList.remove('cancelling');
                    cancelBtn.textContent = 'Cancel';
                }
            });
        },

        /**
         * Show checkpoints modal
         */
        showCheckpoints: function() {
            const modal = document.getElementById('checkpoint-modal');
            const checkpointList = document.getElementById('checkpoint-list');

            if (!modal || !checkpointList) return;

            fetch(this.config.checkpointUrl, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.checkpoints) {
                    if (data.checkpoints.length === 0) {
                        checkpointList.innerHTML = '<p class="light">No checkpoints found.</p>';
                    } else {
                        let html = '<div class="checkpoint-list">';
                        data.checkpoints.forEach(cp => {
                            const date = cp.timestamp ? new Date(cp.timestamp * 1000).toLocaleString() : 'Unknown';
                            const progress = cp.progress ? JSON.stringify(cp.progress, null, 2) : '{}';
                            html += `
                                <div class="checkpoint-item">
                                    <div class="checkpoint-header">
                                        <strong>${cp.filename}</strong>
                                        <span class="checkpoint-date">${date}</span>
                                    </div>
                                    <div class="checkpoint-details">
                                        <p>Phase: ${cp.phase || 'Unknown'}</p>
                                        <pre class="checkpoint-progress">${progress}</pre>
                                    </div>
                                </div>
                            `;
                        });
                        html += '</div>';
                        checkpointList.innerHTML = html;
                    }
                    modal.style.display = 'flex';
                } else {
                    Craft.cp.displayError('Failed to load checkpoints');
                }
            })
            .catch(error => {
                console.error('Failed to load checkpoints:', error);
                Craft.cp.displayError('Failed to load checkpoints');
            });
        },

        /**
         * Show output modal
         */
        showOutputModal: function(output) {
            const modal = document.getElementById('output-modal');
            const content = document.getElementById('modal-output-content');

            if (modal && content) {
                content.textContent = output;
                modal.style.display = 'flex';
            }
        },

        /**
         * Show rollback modal
         */
        showRollbackModal: function() {
            const modal = document.getElementById('rollback-modal');
            if (modal) {
                modal.style.display = 'flex';
            }
        },

        /**
         * Confirm rollback
         */
        confirmRollback: function() {
            const phaseSelect = document.getElementById('rollback-phase');
            const phase = phaseSelect ? phaseSelect.value : '';

            if (!confirm('Are you sure you want to rollback the migration? This cannot be undone.')) {
                return;
            }

            const args = {};
            if (phase) {
                args.toPhase = phase;
            }

            // Close modal
            const modal = document.getElementById('rollback-modal');
            if (modal) {
                modal.style.display = 'none';
            }

            // Execute rollback
            this.runCommand('image-migration/rollback', args);
        },

        /**
         * Show changelog
         */
        showChangelog: function() {
            const modal = document.getElementById('output-modal');
            const content = document.getElementById('modal-output-content');
            const modalTitle = modal ? modal.querySelector('.modal-title') : null;

            if (!modal || !content) {
                Craft.cp.displayError('Could not display changelog');
                return;
            }

            // Update modal title
            if (modalTitle) {
                modalTitle.textContent = 'Migration Changelogs';
            }

            // Show loading state
            content.textContent = 'Loading changelogs...';
            modal.style.display = 'flex';

            fetch(this.config.changelogUrl, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.changelogs) {
                    if (data.changelogs.length === 0) {
                        content.textContent = 'No changelog files found.\n\nChangelogs will be created during migration operations and stored in:\n' + (data.directory || 'storage/migration-logs/');
                    } else {
                        // Format changelogs for display
                        let output = `Found ${data.changelogs.length} changelog file(s)\n`;
                        output += `Directory: ${data.directory}\n`;
                        output += '='.repeat(80) + '\n\n';

                        data.changelogs.forEach((log, index) => {
                            const date = log.timestamp ? new Date(log.timestamp * 1000).toLocaleString() : 'Unknown';
                            output += `[${index + 1}] ${log.filename}\n`;
                            output += `    Date: ${date}\n`;
                            output += `    Operation: ${log.operation}\n`;

                            if (log.summary && Object.keys(log.summary).length > 0) {
                                output += `    Summary:\n`;
                                for (const [key, value] of Object.entries(log.summary)) {
                                    output += `      - ${key}: ${value}\n`;
                                }
                            }

                            if (log.changes && log.changes.length > 0) {
                                output += `    Changes: ${log.changes.length} item(s)\n`;
                                // Show first few changes as preview
                                const previewCount = Math.min(3, log.changes.length);
                                for (let i = 0; i < previewCount; i++) {
                                    const change = log.changes[i];
                                    if (typeof change === 'string') {
                                        output += `      - ${change}\n`;
                                    } else if (change.type) {
                                        output += `      - ${change.type}: ${change.description || change.table || ''}\n`;
                                    }
                                }
                                if (log.changes.length > previewCount) {
                                    output += `      ... and ${log.changes.length - previewCount} more\n`;
                                }
                            }

                            output += `    File path: ${log.filepath}\n`;
                            output += '\n' + '-'.repeat(80) + '\n\n';
                        });

                        output += '\nTo view complete changelog details, access the files directly at:\n';
                        output += data.directory + '/\n';

                        content.textContent = output;
                    }
                } else {
                    content.textContent = 'Failed to load changelogs: ' + (data.error || 'Unknown error');
                }
            })
            .catch(error => {
                console.error('Failed to load changelogs:', error);
                content.textContent = 'Error loading changelogs: ' + error.message;
            })
            .finally(() => {
                // Reset modal title when closed
                const closeButtons = modal.querySelectorAll('.modal-close');
                const resetTitle = () => {
                    if (modalTitle) {
                        modalTitle.textContent = 'Command Output';
                    }
                };
                closeButtons.forEach(btn => {
                    btn.addEventListener('click', resetTitle, { once: true });
                });
            });
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            MigrationDashboard.init();
        });
    } else {
        MigrationDashboard.init();
    }

    // Expose to window for debugging
    window.MigrationDashboard = MigrationDashboard;

})();
