/**
 * AWS to DigitalOcean Migration Dashboard
 * Interactive dashboard for orchestrating the complete migration
 */

(function() {
    'use strict';

    const MigrationDashboard = {
        // Configuration
        config: window.migrationDashboard || {},

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
            } else {
                moduleCard.classList.remove('module-running');
                const runBtn = moduleCard.querySelector('.run-module-btn:not([data-dry-run])');
                if (runBtn) {
                    runBtn.disabled = false;
                    runBtn.classList.remove('loading');
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
            Craft.cp.displayNotice('View changelog files in storage/migration-logs/');
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
