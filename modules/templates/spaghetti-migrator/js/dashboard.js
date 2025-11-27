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
            lastFocusedElement: null, // For focus management
            focusableElements: 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
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
            this.initLiveMonitor();
            this.loadStateFromServer();
            this.setupAccessibility();
            console.log('Migration Dashboard initialized');
        },

        /**
         * Setup accessibility features
         */
        setupAccessibility: function() {
            // Add keyboard support for Escape key to close modals
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    // Close any open modals
                    const openModal = document.querySelector('.modal[style*="display: flex"]');
                    if (openModal) {
                        this.closeModal(openModal);
                    }

                    // Close any open confirmation dialogs
                    const openDialog = document.querySelector('.confirmation-dialog');
                    if (openDialog) {
                        openDialog.remove();
                        if (this.state.lastFocusedElement) {
                            this.state.lastFocusedElement.focus();
                            this.state.lastFocusedElement = null;
                        }
                    }
                }
            });
        },

        /**
         * Announce message to screen readers
         */
        announceToScreenReader: function(message) {
            const announcer = document.getElementById('sr-announcements');
            if (announcer) {
                announcer.textContent = message;
                // Clear after a delay so repeated messages are announced
                setTimeout(() => {
                    announcer.textContent = '';
                }, 1000);
            }
        },

        /**
         * Setup collapsible phase sections
         */
        setupCollapsiblePhases: function() {
            const phaseSections = document.querySelectorAll('.phase-section');

            phaseSections.forEach(section => {
                const phaseId = section.getAttribute('data-phase-id');
                const phaseHeader = section.querySelector('.phase-header');

                if (!phaseHeader) return;

                // Make phase collapsible
                section.classList.add('collapsible');

                // Add collapse icon
                const phaseNumber = phaseHeader.querySelector('.phase-number');
                const phaseInfo = phaseHeader.querySelector('.phase-info');

                if (phaseNumber && phaseInfo) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'phase-header-wrapper';

                    const collapseIcon = document.createElement('span');
                    collapseIcon.className = 'phase-collapse-icon';
                    collapseIcon.setAttribute('aria-hidden', 'true');
                    collapseIcon.textContent = '▼';

                    // Wrap existing content
                    const headerContent = document.createElement('div');
                    headerContent.style.display = 'flex';
                    headerContent.style.alignItems = 'center';
                    headerContent.style.gap = '15px';
                    headerContent.style.flex = '1';

                    phaseHeader.appendChild(wrapper);
                    wrapper.appendChild(headerContent);
                    headerContent.appendChild(phaseNumber);
                    headerContent.appendChild(phaseInfo);
                    wrapper.appendChild(collapseIcon);
                }

                // Add click handler
                phaseHeader.addEventListener('click', function() {
                    section.classList.toggle('collapsed');

                    // Save collapsed state
                    const collapsedPhases = JSON.parse(localStorage.getItem('collapsedPhases') || '[]');
                    if (section.classList.contains('collapsed')) {
                        if (!collapsedPhases.includes(phaseId)) {
                            collapsedPhases.push(phaseId);
                        }
                    } else {
                        const index = collapsedPhases.indexOf(phaseId);
                        if (index > -1) {
                            collapsedPhases.splice(index, 1);
                        }
                    }
                    localStorage.setItem('collapsedPhases', JSON.stringify(collapsedPhases));
                });

                // Restore collapsed state
                const collapsedPhases = JSON.parse(localStorage.getItem('collapsedPhases') || '[]');
                if (collapsedPhases.includes(phaseId)) {
                    section.classList.add('collapsed');
                }
            });
        },

        /**
         * Handle manual step completion confirmation
         */
        handleManualStepCompletion: function(moduleCard, moduleId, moduleTitle) {
            const self = this;

            // Check if already completed
            if (this.state.completedModules.has(moduleId)) {
                Craft.cp.displayNotice('This step is already marked as completed');
                return;
            }

            // Get module description for instructions
            const description = moduleCard ? moduleCard.querySelector('.module-description')?.innerHTML : '';

            // Show confirmation dialog
            const dialog = document.createElement('div');
            dialog.className = 'confirmation-dialog manual-completion-modal';
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-labelledby', 'manual-confirm-title');
            dialog.setAttribute('aria-describedby', 'manual-confirm-message');

            dialog.innerHTML = `
                <div class="confirmation-dialog-content">
                    <div class="confirmation-dialog-icon" aria-hidden="true">📋</div>
                    <h3 id="manual-confirm-title" class="confirmation-dialog-title">Confirm Manual Step Completion</h3>
                    <div id="manual-confirm-message" class="confirmation-dialog-message">
                        <p><strong>${moduleTitle}</strong></p>
                        <div class="manual-completion-checklist">
                            <h4>Before confirming, ensure you have:</h4>
                            <ul>
                                <li>✓ Followed all instructions for this step</li>
                                <li>✓ Run all required CLI commands successfully</li>
                                <li>✓ Verified the output shows no errors</li>
                                <li>✓ Documented any issues or deviations</li>
                            </ul>
                        </div>
                        <p style="color: #6b7280; font-size: 13px; margin-top: 10px;">
                            This will mark the step as completed. You can view the instructions by expanding the module card.
                        </p>
                    </div>
                    <div class="confirmation-dialog-actions">
                        <button type="button" class="btn secondary cancel-btn">Not Yet</button>
                        <button type="button" class="btn submit confirm-btn">Yes, I've Completed This Step</button>
                    </div>
                </div>
            `;

            // Add to page
            document.body.appendChild(dialog);

            // Store focused element
            this.state.lastFocusedElement = document.activeElement;

            // Focus confirm button
            setTimeout(() => {
                const confirmBtn = dialog.querySelector('.confirm-btn');
                if (confirmBtn) {
                    confirmBtn.focus();
                }
            }, 10);

            // Trap focus
            this.trapFocus(dialog);

            // Handle cancel
            dialog.querySelector('.cancel-btn').addEventListener('click', () => {
                dialog.remove();
                if (self.state.lastFocusedElement) {
                    self.state.lastFocusedElement.focus();
                    self.state.lastFocusedElement = null;
                }
            });

            // Handle confirm
            dialog.querySelector('.confirm-btn').addEventListener('click', () => {
                dialog.remove();

                // Mark as completed
                if (moduleCard && moduleId) {
                    moduleCard.classList.add('module-completed', 'manual-completed');
                    const statusIndicator = moduleCard.querySelector('.status-indicator');
                    if (statusIndicator) {
                        statusIndicator.textContent = '✓';
                        statusIndicator.classList.add('completed');
                    }

                    // Change button text
                    const runBtn = moduleCard.querySelector('.run-module-btn');
                    if (runBtn) {
                        runBtn.textContent = 'Completed ✓';
                        runBtn.disabled = true;
                    }

                    // Save to state
                    self.state.completedModules.add(moduleId);
                    self.updateModuleStatus(moduleId, 'completed').catch(err => {
                        console.error('Failed to save completed status:', err);
                        self.persistState();
                    });

                    // Update workflow stepper
                    self.updateWorkflowStepper();

                    // Show success message
                    Craft.cp.displayNotice(`✓ ${moduleTitle} marked as completed`);
                    self.announceToScreenReader(`${moduleTitle} marked as completed`);
                }

                if (self.state.lastFocusedElement) {
                    self.state.lastFocusedElement.focus();
                    self.state.lastFocusedElement = null;
                }
            });

            // Close on background click
            dialog.addEventListener('click', (e) => {
                if (e.target === dialog) {
                    dialog.remove();
                    if (self.state.lastFocusedElement) {
                        self.state.lastFocusedElement.focus();
                        self.state.lastFocusedElement = null;
                    }
                }
            });
        },

        /**
         * Open modal with focus management
         */
        openModal: function(modal) {
            // Store currently focused element
            this.state.lastFocusedElement = document.activeElement;

            // Show modal
            modal.style.display = 'flex';

            // Focus first focusable element in modal
            const focusableElements = modal.querySelectorAll(this.state.focusableElements);
            if (focusableElements.length > 0) {
                focusableElements[0].focus();
            }

            // Trap focus within modal
            this.trapFocus(modal);
        },

        /**
         * Close modal with focus management
         */
        closeModal: function(modal) {
            modal.style.display = 'none';

            // Return focus to previously focused element
            if (this.state.lastFocusedElement) {
                this.state.lastFocusedElement.focus();
                this.state.lastFocusedElement = null;
            }
        },

        /**
         * Trap focus within a modal
         */
        trapFocus: function(modal) {
            const focusableElements = Array.from(modal.querySelectorAll(this.state.focusableElements));
            if (focusableElements.length === 0) return;

            const firstFocusable = focusableElements[0];
            const lastFocusable = focusableElements[focusableElements.length - 1];

            // Remove any existing trap
            const existingHandler = modal._focusTrapHandler;
            if (existingHandler) {
                modal.removeEventListener('keydown', existingHandler);
            }

            // Create new trap handler
            const trapHandler = (e) => {
                if (e.key !== 'Tab' && e.keyCode !== 9) return;

                if (e.shiftKey) {
                    // Shift + Tab
                    if (document.activeElement === firstFocusable) {
                        lastFocusable.focus();
                        e.preventDefault();
                    }
                } else {
                    // Tab
                    if (document.activeElement === lastFocusable) {
                        firstFocusable.focus();
                        e.preventDefault();
                    }
                }
            };

            modal.addEventListener('keydown', trapHandler);
            modal._focusTrapHandler = trapHandler;
        },

        /**
         * Attach event listeners
         */
        attachEventListeners: function() {
            const self = this;

            // Setup collapsible phases
            this.setupCollapsiblePhases();

            // Run module buttons
            const runButtons = document.querySelectorAll('.run-module-btn');
            console.log(`Found ${runButtons.length} run-module-btn buttons`);

            runButtons.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const command = this.getAttribute('data-command');
                    const dryRun = this.getAttribute('data-dry-run') === 'true';
                    const supportsResume = this.getAttribute('data-supports-resume') === 'true';
                    const resumeRequested = this.getAttribute('data-resume') === 'true';
                    const isManualStep = this.hasAttribute('data-manual-step');

                    console.log('Button clicked:', {
                        command,
                        dryRun,
                        supportsResume,
                        resumeRequested,
                        isManualStep
                    });

                    // Handle manual steps differently
                    if (isManualStep) {
                        const moduleCard = this.closest('.module-card');
                        const moduleId = moduleCard ? moduleCard.getAttribute('data-module-id') : null;
                        const moduleTitle = moduleCard ? moduleCard.querySelector('.module-title')?.textContent : 'this step';
                        self.handleManualStepCompletion(moduleCard, moduleId, moduleTitle);
                        return;
                    }

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

            // Copy command buttons
            document.querySelectorAll('.copy-command-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const command = this.getAttribute('data-command');
                    if (command) {
                        navigator.clipboard.writeText(command).then(() => {
                            Craft.cp.displayNotice('CLI command copied to clipboard');
                        }).catch(err => {
                            console.error('Failed to copy command:', err);
                            Craft.cp.displayError('Failed to copy command to clipboard');
                        });
                    } else {
                        Craft.cp.displayNotice('No CLI command available for this module');
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

            // Analyze missing files button
            const analyzeMissingFilesBtn = document.getElementById('analyze-missing-files-btn');
            if (analyzeMissingFilesBtn) {
                analyzeMissingFilesBtn.addEventListener('click', () => {
                    this.analyzeMissingFiles();
                });
            }

            // Fix missing files (dry run) button
            const fixMissingFilesDryRunBtn = document.getElementById('fix-missing-files-btn');
            if (fixMissingFilesDryRunBtn) {
                fixMissingFilesDryRunBtn.addEventListener('click', () => {
                    this.fixMissingFiles(true);
                });
            }

            // Fix missing files (actual) button
            const fixMissingFilesActualBtn = document.getElementById('fix-missing-files-actual-btn');
            if (fixMissingFilesActualBtn) {
                fixMissingFilesActualBtn.addEventListener('click', () => {
                    this.fixMissingFiles(false);
                });
            }

            // Modal close buttons
            document.querySelectorAll('.modal-close').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = btn.closest('.modal');
                    if (modal) {
                        this.closeModal(modal);
                    }
                });
            });

            // Close modal on outside click
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        this.closeModal(modal);
                    }
                });
            });
        },

        /**
         * Load persisted state from the server
         */
        loadStateFromServer: function() {
            this.state.completedModules = new Set();
            this.checkStatus();
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
                    if (data.state) {
                        // Load completed modules
                        if (data.state.completedModules && Array.isArray(data.state.completedModules)) {
                            data.state.completedModules.forEach(module => {
                                this.state.completedModules.add(module);
                            });
                        }

                        // Log running and failed modules for debugging
                        if (data.state.runningModules && Array.isArray(data.state.runningModules)) {
                            console.log('Running modules from server:', data.state.runningModules);
                        }
                        if (data.state.failedModules && Array.isArray(data.state.failedModules)) {
                            console.log('Failed modules from server:', data.state.failedModules);
                        }

                        this.updateModuleStates();
                    }
                }
            })
            .catch(error => {
                console.error('Failed to check status:', error);
            });
        },

        /**
         * Persist the current completion state to the server
         */
        persistState: function() {
            const modules = Array.from(this.state.completedModules);

            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);
            formData.append('modules', JSON.stringify(modules));

            return fetch(this.config.updateStatusUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error('Failed to persist migration status:', data.error);
                }
                return data;
            })
            .catch(error => {
                console.error('Error persisting migration status:', error);
                throw error;
            });
        },

        /**
         * Update module status (running, completed, failed) on the server
         */
        updateModuleStatus: function(moduleId, status, error = null) {
            console.log('Updating module status:', { moduleId, status, error });

            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);
            formData.append('moduleId', moduleId);
            formData.append('status', status);
            if (error) {
                formData.append('error', error);
            }

            return fetch(this.config.updateModuleStatusUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error('Failed to update module status:', data.error);
                }
                return data;
            })
            .catch(error => {
                console.error('Error updating module status:', error);
                throw error;
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

            // Update workflow stepper
            this.updateWorkflowStepper();
        },

        /**
         * Update workflow stepper based on progress
         */
        updateWorkflowStepper: function() {
            // Define critical checkpoints for each phase
            const phaseCheckpoints = {
                0: ['filesystem', 'volume-config'],  // Setup
                1: ['migration-check'],              // Checks
                2: ['url-replacement'],              // URLs
                3: ['template-replace'],             // Templates
                4: ['switch-to-do'],                 // CRITICAL: Filesystem Switch
                5: ['image-migration'],              // CRITICAL: File Migration
                6: ['migration-diag'],               // Validation
                7: ['transform-discovery-all']       // Transforms
            };

            let currentPhase = 0;

            // Determine current phase based on completed modules
            for (let phase = 7; phase >= 0; phase--) {
                const phaseModules = phaseCheckpoints[phase];
                if (phaseModules && phaseModules.some(moduleId => this.state.completedModules.has(moduleId))) {
                    currentPhase = phase + 1; // Next phase after this one
                    break;
                }
            }

            // Update stepper UI
            document.querySelectorAll('.stepper-step').forEach((step, index) => {
                const stepPhase = parseInt(step.getAttribute('data-phase'));
                step.classList.remove('active', 'completed');

                if (stepPhase < currentPhase) {
                    step.classList.add('completed');
                } else if (stepPhase === currentPhase) {
                    step.classList.add('active');
                }
            });
        },

        /**
         * Show confirmation dialog for critical operations
         */
        showConfirmationDialog: function(title, message, onConfirm) {
            console.log('showConfirmationDialog called:', { title });

            // Store currently focused element
            this.state.lastFocusedElement = document.activeElement;

            // Create dialog element
            const dialog = document.createElement('div');
            dialog.className = 'confirmation-dialog';
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-labelledby', 'confirm-dialog-title');
            dialog.setAttribute('aria-describedby', 'confirm-dialog-message');
            dialog.innerHTML = `
                <div class="confirmation-dialog-content">
                    <div class="confirmation-dialog-icon" aria-hidden="true">⚠️</div>
                    <h3 id="confirm-dialog-title" class="confirmation-dialog-title">${title}</h3>
                    <div id="confirm-dialog-message" class="confirmation-dialog-message">${message}</div>
                    <div class="confirmation-dialog-actions">
                        <button type="button" class="btn secondary cancel-btn">Cancel</button>
                        <button type="button" class="btn submit confirm-btn">Confirm & Proceed</button>
                    </div>
                </div>
            `;

            // Add to page
            document.body.appendChild(dialog);
            console.log('Confirmation dialog added to DOM');

            // Focus the cancel button initially for safety
            setTimeout(() => {
                const cancelBtn = dialog.querySelector('.cancel-btn');
                if (cancelBtn) {
                    cancelBtn.focus();
                }
            }, 10);

            // Trap focus in dialog
            this.trapFocus(dialog);

            // Handle cancel
            dialog.querySelector('.cancel-btn').addEventListener('click', () => {
                dialog.remove();
                if (this.state.lastFocusedElement) {
                    this.state.lastFocusedElement.focus();
                    this.state.lastFocusedElement = null;
                }
            });

            // Handle confirm
            dialog.querySelector('.confirm-btn').addEventListener('click', () => {
                dialog.remove();
                if (onConfirm) onConfirm();
            });

            // Close on background click
            dialog.addEventListener('click', (e) => {
                if (e.target === dialog) {
                    dialog.remove();
                    if (this.state.lastFocusedElement) {
                        this.state.lastFocusedElement.focus();
                        this.state.lastFocusedElement = null;
                    }
                }
            });
        },

        /**
         * Validate workflow order before running critical operations
         */
        validateWorkflowOrder: function(moduleId) {
            // Critical dependencies
            const dependencies = {
                'image-migration': {
                    requires: ['switch-to-do'],
                    message: 'You must complete the Filesystem Switch (Phase 4) before running File Migration (Phase 5). Switching filesystems first ensures volumes point to DigitalOcean during migration.'
                },
                'switch-to-do': {
                    requires: ['migration-check'],
                    message: 'You must run Pre-Flight Checks (Phase 1) before switching filesystems.'
                }
            };

            const dep = dependencies[moduleId];
            if (!dep) return true; // No dependencies for this module

            // Check if all required modules are completed
            const missing = dep.requires.filter(reqId => !this.state.completedModules.has(reqId));

            if (missing.length > 0) {
                this.showWarningBanner('Workflow Order Issue', dep.message);
                return false;
            }

            return true;
        },

        /**
         * Show warning banner
         */
        showWarningBanner: function(title, message) {
            console.log('showWarningBanner called:', { title, message });

            const banner = document.createElement('div');
            banner.className = 'order-warning-banner';
            banner.style.animation = 'slideDown 0.3s ease';
            banner.innerHTML = `
                <div class="warning-icon">⚠️</div>
                <div class="warning-content">
                    <h4>${title}</h4>
                    <p>${message}</p>
                </div>
                <button type="button" class="btn small" style="margin-left: auto;" onclick="this.parentElement.remove()">Dismiss</button>
            `;

            const container = document.querySelector('.migration-dashboard');
            if (container) {
                container.insertBefore(banner, container.firstChild);
                console.log('Warning banner added to DOM');

                // Auto-dismiss after 10 seconds
                setTimeout(() => {
                    if (banner.parentElement) {
                        banner.remove();
                    }
                }, 10000);
            } else {
                console.error('Migration dashboard container not found!');
            }
        },

        /**
         * Run a migration command
         *
         * Architecture: All commands are queued via Craft Queue system (non-blocking)
         * instead of using SSE streaming which blocked PHP workers and the Control Panel.
         *
         * Benefits:
         * - Non-blocking: Site/CP remains fully responsive during migrations
         * - Survives page refresh: Progress persists in database
         * - Scalable: Multiple users can run commands simultaneously
         * - Progress tracking: Polls every 2 seconds for updates
         */
        runCommand: function(command, args = {}) {
            console.log('runCommand called:', { command, args });

            let moduleCard = document.querySelector(`.module-card[data-command="${command}"]`);

            if (!moduleCard) {
                const triggerButton = document.querySelector(`.run-module-btn[data-command="${command}"]`);
                if (triggerButton) {
                    moduleCard = triggerButton.closest('.module-card');
                }
            }

            if (!moduleCard) {
                console.error('Module card not found for command:', command);
                Craft.cp.displayError('Unable to locate module card for command: ' + command);
                return;
            }

            const moduleId = moduleCard.getAttribute('data-module-id');
            console.log('Module ID:', moduleId);

            // Check if already running
            if (this.state.runningModules.has(command)) {
                console.log('Module already running, aborting');
                Craft.cp.displayNotice('This module is already running');
                return;
            }

            // Validate workflow order (skip for dry runs as they don't make changes)
            if (args.dryRun) {
                console.log('Dry run mode - skipping workflow validation');
            } else {
                console.log('Validating workflow order...');
                if (!this.validateWorkflowOrder(moduleId)) {
                    console.log('Workflow validation failed');
                    return; // Validation failed, warning already shown
                }
                console.log('Workflow validation passed');
            }

            // Show confirmation dialog for critical operations (except dry runs)
            const criticalModules = ['switch-to-do', 'image-migration', 'filesystem-switch/to-do'];
            if (criticalModules.includes(moduleId) && !args.yes && !args.dryRun) {
                console.log('Showing confirmation dialog for critical module:', moduleId);
                const confirmations = {
                    'switch-to-do': {
                        title: 'Confirm Filesystem Switch',
                        message: '<strong>CRITICAL OPERATION:</strong> This will switch all volumes to use DigitalOcean Spaces. Ensure you have:<br/><br/>• Completed all previous phases<br/>• Synced files from AWS to DO using rclone<br/>• Created a database backup<br/><br/>This operation is reversible, but should be done carefully.'
                    },
                    'image-migration': {
                        title: 'Confirm File Migration',
                        message: '<strong>IMPORTANT:</strong> This will migrate all asset files from AWS to DigitalOcean. Ensure you have:<br/><br/>• Completed Filesystem Switch (Phase 4)<br/>• Sufficient disk space<br/>• Created a database backup<br/><br/>This process may take several hours and creates automatic backups.'
                    }
                };

                const config = confirmations[moduleId] || confirmations['switch-to-do'];

                this.showConfirmationDialog(config.title, config.message, () => {
                    console.log('User confirmed, rerunning command with --yes flag');
                    // User confirmed, proceed with command
                    args.yes = true;
                    this.runCommand(command, args);
                });
                return;
            }

            console.log('Proceeding to execute command...');

            // Mark as running
            this.state.runningModules.add(command);
            this.setModuleRunning(moduleCard, true);

            // Save running status to database
            if (moduleId && !args.dryRun) {
                this.updateModuleStatus(moduleId, 'running').catch(err => {
                    console.error('Failed to save running status:', err);
                });
            }

            // Show progress
            const progressSection = moduleCard.querySelector('.module-progress');
            if (progressSection) {
                progressSection.style.display = 'block';
            }

            // Use queue system for ALL commands to prevent blocking the site/CP
            // This allows migrations to survive page refreshes and run without blocking PHP workers
            console.log('Using non-blocking queue system for command:', command);
            this.runCommandQueue(moduleCard, command, args);
        },

        /**
         * Run command with streaming output (SSE)
         *
         * @deprecated This method is no longer used. All commands now use the queue system
         * via runCommandQueue() to prevent blocking the PHP workers and Control Panel.
         * Kept for backward compatibility but should not be called.
         */
        runCommandStreaming: function(moduleCard, command, args = {}) {
            // Prepare request
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);
            formData.append('command', command);
            formData.append('args', JSON.stringify(args));
            formData.append('stream', '1');
            // Explicitly set dryRun parameter (some commands default to dryRun=true)
            formData.append('dryRun', args.dryRun ? '1' : '0');

            // Clear previous output
            this.showModuleOutput(moduleCard, '');

            // Create EventSource for SSE (we'll use fetch instead for POST)
            fetch(this.config.runCommandUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/event-stream'
                },
                body: formData
            })
            .then(async response => {
                if (!response.ok) {
                    // Try to read the response body for error details
                    let errorMessage = `Server error (${response.status})`;
                    try {
                        const text = await response.text();
                        console.error('Server response:', text);

                        // Try to parse as JSON
                        try {
                            const json = JSON.parse(text);
                            if (json.error) {
                                errorMessage = json.error;
                            } else if (json.message) {
                                errorMessage = json.message;
                            }
                        } catch (e) {
                            // Not JSON, show first 200 chars of response
                            if (text.length > 0) {
                                errorMessage += ': ' + text.substring(0, 200);
                            }
                        }
                    } catch (e) {
                        console.error('Could not read error response:', e);
                    }
                    throw new Error(errorMessage);
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
                            const trimmedBlock = eventBlock.trim();
                            if (!trimmedBlock || trimmedBlock.startsWith(':')) {
                                return;
                            }

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

                            if (!eventData) {
                                return;
                            }

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
         *
         * @deprecated This method is no longer used. All commands now use the queue system
         * via runCommandQueue() to prevent blocking the PHP workers and Control Panel.
         * Kept for backward compatibility but should not be called.
         */
        runCommandStandard: function(moduleCard, command, args = {}) {
            // Prepare request
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);
            formData.append('command', command);
            formData.append('args', JSON.stringify(args));
            // Explicitly set dryRun parameter (some commands default to dryRun=true)
            formData.append('dryRun', args.dryRun ? '1' : '0');

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
         * Run command via queue system (survives page refresh)
         */
        runCommandQueue: function(moduleCard, command, args = {}) {
            console.log('Running command via queue:', { command, args });

            // Prepare request
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);
            formData.append('command', command);
            formData.append('args', JSON.stringify(args));
            // Explicitly set dryRun parameter (some commands default to dryRun=true)
            formData.append('dryRun', args.dryRun ? '1' : '0');

            // Clear previous output
            const commandName = command.split('/').pop().replace(/-/g, ' ');
            this.showModuleOutput(moduleCard, `Starting ${commandName} via queue system...\n`);
            this.updateModuleProgress(moduleCard, 0, 'Queuing job...');

            // Queue the job
            fetch(this.config.runCommandQueueUrl, {
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
                    const jobId = data.jobId;
                    const migrationId = data.migrationId;

                    console.log('Job queued successfully:', { jobId, migrationId });

                    this.showModuleOutput(moduleCard,
                        `Job queued successfully!\n` +
                        `Job ID: ${jobId}\n` +
                        `Migration ID: ${migrationId}\n\n` +
                        `The command is now running in the background via Craft Queue.\n` +
                        `You can safely refresh this page or open other admin windows.\n` +
                        `The site/Control Panel will remain fully responsive.\n\n` +
                        `Polling for progress updates every 2 seconds...\n\n`
                    );

                    Craft.cp.displayNotice(data.message || 'Command queued successfully');

                    // Start polling for progress
                    this.pollQueueJobProgress(moduleCard, command, jobId, migrationId, args.dryRun);
                } else {
                    throw new Error(data.error || 'Failed to queue command');
                }
            })
            .catch(error => {
                console.error('Failed to queue command:', error);
                this.showModuleOutput(moduleCard, `Error: ${error.message}\n`);
                Craft.cp.displayError('Failed to queue command: ' + error.message);
                this.state.runningModules.delete(command);
                this.setModuleRunning(moduleCard, false);
            });
        },

        /**
         * Poll queue job progress
         */
        pollQueueJobProgress: function(moduleCard, command, jobId, migrationId, isDryRun) {
            console.log('Starting polling for job:', { jobId, migrationId });

            let pollCount = 0;
            const maxPolls = 86400; // 48 hours at 2 second intervals (48*60*60/2)
            const pollInterval = 2000; // Poll every 2 seconds for responsive updates

            const pollJob = () => {
                pollCount++;

                // Check queue status
                const queueParams = new URLSearchParams({ jobId });
                if (migrationId) {
                    queueParams.set('migrationId', migrationId);
                }

                fetch(`${this.config.getQueueStatusUrl}?${queueParams.toString()}`, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.error || 'Failed to get queue status');
                    }

                    console.log('Queue status:', data);

                    // Prefer migration status over queue job status for accuracy
                    const status = data.migrationStatus || data.status;
                    const job = data.job;

                    console.log('Detected status:', status, 'Job:', job);

                    if (status === 'completed') {
                        // Job completed, get final state and output
                        console.log('Queue job completed');
                        this.updateModuleProgress(moduleCard, 100, 'Completed');

                        // Get final output from migration state before showing success message
                        this.updateMigrationProgress(moduleCard, migrationId);

                        // Append success message to existing output (don't replace)
                        setTimeout(() => {
                            const outputContent = moduleCard.querySelector('.output-content');
                            if (outputContent) {
                                outputContent.textContent += '\n\n' + '='.repeat(80) + '\n';
                                outputContent.textContent += '✓ Command completed successfully!\n';
                                outputContent.textContent += '='.repeat(80) + '\n';
                                requestAnimationFrame(() => {
                                    outputContent.scrollTop = outputContent.scrollHeight;
                                });
                            }
                        }, 500); // Wait for final migration progress update

                        if (!isDryRun) {
                            this.markModuleCompleted(moduleCard, command);
                            Craft.cp.displayNotice('Command completed successfully!');
                        } else {
                            Craft.cp.displayNotice('Dry run completed successfully!');
                        }

                        this.state.runningModules.delete(command);
                        this.setModuleRunning(moduleCard, false);
                        return; // Stop polling
                    } else if (status === 'failed') {
                        console.error('Queue job failed:', job?.error);
                        this.updateModuleProgress(moduleCard, 0, 'Failed');

                        // Get final output from migration state before showing error
                        this.updateMigrationProgress(moduleCard, migrationId);

                        // Append error message to existing output (don't replace)
                        setTimeout(() => {
                            const outputContent = moduleCard.querySelector('.output-content');
                            if (outputContent) {
                                outputContent.textContent += '\n\n' + '='.repeat(80) + '\n';
                                outputContent.textContent += `✗ Command failed: ${job?.error || 'Unknown error'}\n`;
                                outputContent.textContent += '='.repeat(80) + '\n';
                                requestAnimationFrame(() => {
                                    outputContent.scrollTop = outputContent.scrollHeight;
                                });
                            }
                        }, 500); // Wait for final migration progress update

                        // Save failed status to database
                        const moduleId = moduleCard.getAttribute('data-module-id');
                        if (moduleId && !isDryRun) {
                            this.updateModuleStatus(moduleId, 'failed', job?.error).catch(err => {
                                console.error('Failed to save failed status:', err);
                            });
                        }

                        Craft.cp.displayError('Command failed: ' + (job?.error || 'Unknown error'));
                        this.state.runningModules.delete(command);
                        this.setModuleRunning(moduleCard, false);
                        return; // Stop polling
                    } else {
                        // Still running, update progress
                        if (job && job.progress) {
                            const progress = Math.round(job.progress * 100);
                            const progressLabel = job.description || `Running (${job.progressLabel || progress + '%'})`;
                            this.updateModuleProgress(moduleCard, progress, progressLabel);
                        } else {
                            this.updateModuleProgress(moduleCard, 1, 'Queued (high priority)');
                        }

                        // Also check migration state for more detailed progress
                        this.updateMigrationProgress(moduleCard, migrationId);

                        // Continue polling if not at max
                        if (pollCount < maxPolls) {
                            setTimeout(pollJob, pollInterval);
                        } else {
                            console.warn('Max polling attempts reached');
                            this.showModuleOutput(moduleCard, '\n⚠ Max polling attempts reached. Job may still be running.\n');
                            this.state.runningModules.delete(command);
                            this.setModuleRunning(moduleCard, false);
                        }
                    }
                })
                .catch(error => {
                    console.error('Polling error:', error);

                    // Continue polling on error (might be temporary network issue)
                    if (pollCount < maxPolls) {
                        setTimeout(pollJob, pollInterval * 2); // Back off on errors
                    } else {
                        this.showModuleOutput(moduleCard, `\nPolling error: ${error.message}\n`);
                        this.state.runningModules.delete(command);
                        this.setModuleRunning(moduleCard, false);
                    }
                });
            };

            // Start polling immediately to capture very fast jobs, then continue
            // at the configured interval.
            pollJob();
        },

        /**
         * Update migration progress from MigrationStateService
         */
        updateMigrationProgress: function(moduleCard, migrationId) {
            fetch(`${this.config.getMigrationProgressUrl}?migrationId=${migrationId}`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.migration) {
                    const migration = data.migration;
                    const phase = migration.phase || 'unknown';
                    const processedCount = migration.processedCount || 0;
                    const totalCount = migration.totalCount || 0;

                    // Show real-time output if available
                    if (migration.output && migration.output.trim()) {
                        const outputContent = moduleCard.querySelector('.output-content');
                        if (outputContent) {
                            // Check if user was scrolled to bottom BEFORE updating content
                            // Calculate distance from bottom of OLD content
                            const wasAtBottom = (outputContent.scrollHeight - outputContent.scrollTop - outputContent.clientHeight) < 100;

                            // Replace entire output with latest from backend
                            outputContent.textContent = migration.output;

                            // Auto-scroll to bottom if user was at bottom before update
                            // This prevents jumping when user has scrolled up to read earlier output
                            if (wasAtBottom) {
                                // Small delay to ensure DOM has rendered new content
                                setTimeout(() => {
                                    outputContent.scrollTop = outputContent.scrollHeight;
                                }, 0);
                            }
                        }
                    }

                    // Update progress bar if we have count data
                    if (totalCount > 0) {
                        const progressPercent = Math.round((processedCount / totalCount) * 100);
                        this.updateModuleProgress(moduleCard, progressPercent, `${processedCount}/${totalCount} - ${phase}`);
                    }
                }
            })
            .catch(error => {
                console.error('Failed to get migration progress:', error);
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
                            // Use requestAnimationFrame for reliable auto-scroll
                            requestAnimationFrame(() => {
                                outputContent.scrollTop = outputContent.scrollHeight;
                            });
                        }

                        // Try to parse progress from output
                        this.parseProgressFromOutput(moduleCard, data.line);
                        break;

                    case 'complete':
                        console.log('Complete event received:', {
                            success: data.success,
                            exitCode: data.exitCode,
                            cancelled: data.cancelled,
                            isDryRun: isDryRun,
                            outputLength: data.output ? data.output.length : 0
                        });

                        if (!data.cancelled && typeof data.output === 'string' && data.output.length) {
                            const normalizedOutput = data.output.endsWith('\n') ? data.output : data.output + '\n';
                            this.showModuleOutput(moduleCard, normalizedOutput);
                        }

                        if (data.cancelled) {
                            console.log('Command was cancelled');
                            this.updateModuleProgress(moduleCard, 0, 'Cancelled');
                            this.announceToScreenReader('Command was cancelled');
                            Craft.cp.displayNotice('Command was cancelled');
                        } else if (data.success) {
                            console.log('Command completed successfully');
                            this.updateModuleProgress(moduleCard, 100, 'Completed');
                            if (!isDryRun) {
                                this.markModuleCompleted(moduleCard, command);
                                this.announceToScreenReader('Command completed successfully');
                                Craft.cp.displayNotice('Command completed successfully');
                            } else {
                                this.announceToScreenReader('Dry run completed successfully');
                                Craft.cp.displayNotice('Dry run completed successfully');
                            }
                        } else {
                            console.error('Command failed with exit code:', data.exitCode);
                            this.updateModuleProgress(moduleCard, 0, 'Failed');

                            // Save failed status to database
                            const moduleId = moduleCard.getAttribute('data-module-id');
                            if (moduleId && !isDryRun) {
                                const errorMsg = `Command failed with exit code ${data.exitCode}`;
                                this.updateModuleStatus(moduleId, 'failed', errorMsg).catch(err => {
                                    console.error('Failed to save failed status:', err);
                                });
                            }

                            this.announceToScreenReader('Command failed');
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
            const progressBar = moduleCard.querySelector('.progress-bar');
            const progressFill = moduleCard.querySelector('.progress-fill');
            const progressPercent = moduleCard.querySelector('.progress-percent');
            const progressText = moduleCard.querySelector('.progress-text');

            const roundedPercent = Math.round(percent);

            if (progressFill) {
                progressFill.style.width = roundedPercent + '%';
            }
            if (progressPercent) {
                progressPercent.textContent = roundedPercent + '%';
            }
            if (progressText) {
                progressText.textContent = text;
            }

            // Update ARIA attributes for screen readers
            if (progressBar) {
                progressBar.setAttribute('aria-valuenow', roundedPercent);
            }

            // Announce significant progress milestones to screen readers
            if (roundedPercent === 25 || roundedPercent === 50 || roundedPercent === 75 || roundedPercent === 100) {
                this.announceToScreenReader(`Progress: ${roundedPercent}% complete`);
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

                // Auto-scroll to bottom - use requestAnimationFrame for reliability
                requestAnimationFrame(() => {
                    outputContent.scrollTop = outputContent.scrollHeight;
                });
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

                // Save completed status to database
                this.updateModuleStatus(moduleId, 'completed').catch(err => {
                    console.error('Failed to save completed status:', err);
                    // Fall back to old persistState method
                    this.persistState();
                });

                // Special case: If verification succeeds, mark the switch step as completed too
                // since verification proves the switch was successful
                if (moduleId === 'switch-verify') {
                    console.log('Verification succeeded - also marking switch-to-do as completed');
                    this.state.completedModules.add('switch-to-do');

                    // Save switch-to-do as completed too
                    this.updateModuleStatus('switch-to-do', 'completed').catch(err => {
                        console.error('Failed to save switch-to-do completed status:', err);
                    });

                    // Also update UI for the switch-to-do module
                    const switchModule = document.querySelector('[data-module-id="switch-to-do"]');
                    if (switchModule) {
                        switchModule.classList.add('module-completed');
                        const switchStatusIndicator = switchModule.querySelector('.status-indicator');
                        if (switchStatusIndicator) {
                            switchStatusIndicator.textContent = '✓';
                            switchStatusIndicator.classList.add('completed');
                        }
                    }
                }

                // Update workflow stepper
                this.updateWorkflowStepper();
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
         * Analyze missing files
         */
        analyzeMissingFiles: function() {
            const btn = document.getElementById('analyze-missing-files-btn');
            const resultsDiv = document.getElementById('missing-files-results');
            const statsDiv = document.getElementById('missing-files-stats');
            const listDiv = document.getElementById('missing-files-list');
            const fixResultsDiv = document.getElementById('fix-results');

            // Hide fix results from previous runs
            if (fixResultsDiv) {
                fixResultsDiv.style.display = 'none';
            }

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span aria-hidden="true">⏳</span> Analyzing...';
            }

            const formData = new FormData();
            formData.append(Craft.csrfTokenName, this.config.csrfToken);

            fetch(this.config.analyzeMissingFilesUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    const result = data.data;

                    // Build stats HTML
                    const statsHtml = `
                        <div style="padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb;">
                            <div style="font-weight: 600; color: #374151;">Total Assets:</div>
                            <div style="font-size: 24px; color: #1f2937;">${result.totalAssets.toLocaleString()}</div>
                        </div>
                        <div style="padding: 12px; background: ${result.totalMissing > 0 ? '#fef2f2' : '#f0fdf4'}; border-radius: 6px; border: 1px solid ${result.totalMissing > 0 ? '#fecaca' : '#bbf7d0'};">
                            <div style="font-weight: 600; color: #374151;">Missing Files:</div>
                            <div style="font-size: 24px; color: ${result.totalMissing > 0 ? '#dc2626' : '#16a34a'};">${result.totalMissing.toLocaleString()}</div>
                        </div>
                        <div style="padding: 12px; background: #eff6ff; border-radius: 6px; border: 1px solid #bfdbfe;">
                            <div style="font-weight: 600; color: #374151;">Found in Quarantine:</div>
                            <div style="font-size: 24px; color: #2563eb;">${result.foundInQuarantine.toLocaleString()}</div>
                        </div>
                        <div style="padding: 12px; background: #fafafa; border-radius: 6px; border: 1px solid #e5e7eb;">
                            <div style="font-weight: 600; color: #374151;">Quarantine Assets:</div>
                            <div style="font-size: 24px; color: #6b7280;">${result.quarantineAssetCount.toLocaleString()}</div>
                        </div>
                    `;

                    if (statsDiv) {
                        statsDiv.innerHTML = statsHtml;
                    }

                    // Build file list HTML
                    let listHtml = '';
                    if (result.totalMissing === 0) {
                        listHtml = '<div style="padding: 12px; text-align: center; color: #16a34a; font-weight: 600;">✓ No missing files found!</div>';
                    } else {
                        listHtml = '<div style="font-size: 13px; line-height: 1.6;">';
                        result.missingFiles.forEach((file, idx) => {
                            const isInQuarantine = result.foundInQuarantine > 0; // Simplified check
                            listHtml += `
                                <div style="padding: 8px; border-bottom: 1px solid #e5e7eb; ${idx % 2 === 0 ? 'background: #f9fafb;' : ''}">
                                    <div style="font-weight: 600; color: #1f2937;">${file.filename}</div>
                                    <div style="color: #6b7280; font-size: 12px;">
                                        Volume: ${file.volumeName} | Extension: ${file.extension}
                                    </div>
                                </div>
                            `;
                        });
                        if (result.hasMore) {
                            listHtml += '<div style="padding: 12px; text-align: center; color: #6b7280; font-style: italic;">... and more</div>';
                        }
                        listHtml += '</div>';
                    }

                    if (listDiv) {
                        listDiv.innerHTML = listHtml;
                    }

                    // Show results
                    if (resultsDiv) {
                        resultsDiv.style.display = 'block';
                    }

                    // Show fix buttons if there are fixable files
                    if (result.foundInQuarantine > 0) {
                        document.getElementById('fix-missing-files-btn').style.display = 'inline-block';
                        document.getElementById('fix-missing-files-actual-btn').style.display = 'inline-block';
                        Craft.cp.displayNotice(`Analysis complete: ${result.foundInQuarantine} files can be fixed from quarantine`);
                    } else if (result.totalMissing > 0) {
                        Craft.cp.displayNotice(`Analysis complete: ${result.totalMissing} missing files found, but none are in quarantine`);
                    } else {
                        Craft.cp.displayNotice('Analysis complete: No missing files found');
                    }
                } else {
                    const errorMsg = data.error || 'Analysis failed';
                    Craft.cp.displayError(errorMsg);
                }
            })
            .catch(error => {
                console.error('Analysis error:', error);
                Craft.cp.displayError('Failed to analyze missing files: ' + error.message);
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<span aria-hidden="true">🔍</span> Analyze Missing Files';
                }
            });
        },

        /**
         * Fix missing files
         */
        fixMissingFiles: function(dryRun) {
            const dryRunBtn = document.getElementById('fix-missing-files-btn');
            const actualBtn = document.getElementById('fix-missing-files-actual-btn');
            const btn = dryRun ? dryRunBtn : actualBtn;
            const fixResultsDiv = document.getElementById('fix-results');
            const fixResultsContent = document.getElementById('fix-results-content');

            if (btn) {
                btn.disabled = true;
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<span aria-hidden="true">⏳</span> Processing...';

                const formData = new FormData();
                formData.append(Craft.csrfTokenName, this.config.csrfToken);
                formData.append('dryRun', dryRun ? '1' : '0');

                fetch(this.config.fixMissingFilesUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        const result = data.data;

                        // Build results HTML
                        let resultsHtml = `
                            <div style="margin-bottom: 16px;">
                                <div style="padding: 12px; background: ${result.dryRun ? '#eff6ff' : '#f0fdf4'}; border-radius: 6px; border: 1px solid ${result.dryRun ? '#bfdbfe' : '#bbf7d0'}; margin-bottom: 8px;">
                                    <strong>${result.message}</strong>
                                </div>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                                    <div style="padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb;">
                                        <div style="font-size: 12px; color: #6b7280;">Total Missing</div>
                                        <div style="font-size: 20px; font-weight: 600; color: #1f2937;">${result.totalMissing}</div>
                                    </div>
                                    <div style="padding: 12px; background: #eff6ff; border-radius: 6px; border: 1px solid #bfdbfe;">
                                        <div style="font-size: 12px; color: #6b7280;">Found in Quarantine</div>
                                        <div style="font-size: 20px; font-weight: 600; color: #2563eb;">${result.foundInQuarantine}</div>
                                    </div>
                                    ${!result.dryRun ? `
                                    <div style="padding: 12px; background: #f0fdf4; border-radius: 6px; border: 1px solid #bbf7d0;">
                                        <div style="font-size: 12px; color: #6b7280;">Fixed</div>
                                        <div style="font-size: 20px; font-weight: 600; color: #16a34a;">${result.fixed}</div>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        `;

                        if (result.errors && result.errors.length > 0) {
                            resultsHtml += `
                                <div style="padding: 12px; background: #fef2f2; border-radius: 6px; border: 1px solid #fecaca;">
                                    <strong style="color: #dc2626;">Errors (${result.errors.length}):</strong>
                                    <div style="margin-top: 8px; max-height: 200px; overflow-y: auto;">
                            `;
                            result.errors.forEach(err => {
                                resultsHtml += `
                                    <div style="padding: 6px; border-bottom: 1px solid #fecaca; font-size: 13px;">
                                        <div style="font-weight: 600;">${err.filename}</div>
                                        <div style="color: #dc2626;">${err.error}</div>
                                    </div>
                                `;
                            });
                            resultsHtml += '</div></div>';
                        }

                        if (fixResultsContent) {
                            fixResultsContent.innerHTML = resultsHtml;
                        }

                        if (fixResultsDiv) {
                            fixResultsDiv.style.display = 'block';
                        }

                        // Show notification
                        if (result.dryRun) {
                            Craft.cp.displayNotice(result.message);
                        } else {
                            Craft.cp.displayNotice(result.message);
                            // Re-run analysis to update the display
                            setTimeout(() => this.analyzeMissingFiles(), 1000);
                        }
                    } else {
                        const errorMsg = data.error || 'Fix operation failed';
                        Craft.cp.displayError(errorMsg);
                    }
                })
                .catch(error => {
                    console.error('Fix error:', error);
                    Craft.cp.displayError('Failed to fix missing files: ' + error.message);
                })
                .finally(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }
                });
            }
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
                            const checkpointId = cp.filename ? cp.filename.replace(/^checkpoint-/, '').replace(/\.json$/, '') : null;
                            html += `
                                <div class="checkpoint-item">
                                    <div class="checkpoint-header">
                                        <strong>${cp.filename}</strong>
                                        <span class="checkpoint-date">${date}</span>
                                    </div>
                                    <div class="checkpoint-details">
                                        <p>Phase: <strong>${cp.phase || 'Unknown'}</strong></p>
                                        <pre class="checkpoint-progress">${progress}</pre>
                                    </div>
                                    ${checkpointId ? `
                                    <div class="checkpoint-actions">
                                        <button type="button"
                                                class="btn submit resume-checkpoint-btn"
                                                data-checkpoint-id="${checkpointId}">
                                            Resume from this checkpoint
                                        </button>
                                    </div>
                                    ` : ''}
                                </div>
                            `;
                        });
                        html += '</div>';
                        checkpointList.innerHTML = html;

                        // Add event listeners to resume buttons
                        const self = this;
                        modal.querySelectorAll('.resume-checkpoint-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                const checkpointId = this.getAttribute('data-checkpoint-id');
                                self.closeModal(modal);
                                self.runCommand('image-migration/migrate', {
                                    resume: '1',
                                    checkpointId: checkpointId
                                });
                            });
                        });
                    }
                    this.openModal(modal);
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
                this.openModal(modal);
            }
        },

        /**
         * Show rollback modal
         */
        showRollbackModal: function() {
            const modal = document.getElementById('rollback-modal');
            if (modal) {
                this.openModal(modal);
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
                this.closeModal(modal);
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
            this.openModal(modal);

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
        },


    // ===========================================
    // Live Monitor Feature
    // ===========================================

    /**
     * Initialize Live Monitor
     */
    initLiveMonitor: function() {
        const liveMonitorBtn = document.getElementById('live-monitor-btn');
        const monitorModal = document.getElementById('live-monitor-modal');
        const pauseBtn = document.getElementById('monitor-pause-btn');

        if (!liveMonitorBtn || !monitorModal) {
            return;
        }

        // Open live monitor
        liveMonitorBtn.addEventListener('click', () => {
            this.openLiveMonitor();
        });

        // Pause/Resume refresh
        if (pauseBtn) {
            pauseBtn.addEventListener('click', () => {
                this.toggleMonitorRefresh();
            });
        }

        // Handle modal close
        const closeButtons = monitorModal.querySelectorAll('.modal-close');
        closeButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                this.closeLiveMonitor();
            });
        });

        // Close on outside click
        monitorModal.addEventListener('click', (e) => {
            if (e.target === monitorModal) {
                this.closeLiveMonitor();
            }
        });
    },

    /**
     * Live monitor state
     */
    liveMonitor: {
        isOpen: false,
        isPaused: false,
        refreshInterval: null,
        migrationId: null,
    },

    /**
     * Open live monitor modal
     */
    openLiveMonitor: function() {
        const modal = document.getElementById('live-monitor-modal');
        if (!modal) return;

        modal.style.display = 'flex';
        this.liveMonitor.isOpen = true;
        this.liveMonitor.isPaused = false;

        // Start fetching data
        this.refreshMonitorData();

        // Set up auto-refresh every 3 seconds
        this.liveMonitor.refreshInterval = setInterval(() => {
            if (!this.liveMonitor.isPaused) {
                this.refreshMonitorData();
            }
        }, 3000);
    },

    /**
     * Close live monitor modal
     */
    closeLiveMonitor: function() {
        const modal = document.getElementById('live-monitor-modal');
        if (!modal) return;

        modal.style.display = 'none';
        this.liveMonitor.isOpen = false;

        // Stop auto-refresh
        if (this.liveMonitor.refreshInterval) {
            clearInterval(this.liveMonitor.refreshInterval);
            this.liveMonitor.refreshInterval = null;
        }
    },

    /**
     * Toggle monitor refresh pause/resume
     */
    toggleMonitorRefresh: function() {
        this.liveMonitor.isPaused = !this.liveMonitor.isPaused;

        const pauseBtn = document.getElementById('monitor-pause-btn');
        const pauseText = document.getElementById('monitor-pause-text');

        if (this.liveMonitor.isPaused) {
            pauseText.textContent = 'Resume Refresh';
            pauseBtn.classList.add('submit');
        } else {
            pauseText.textContent = 'Pause Refresh';
            pauseBtn.classList.remove('submit');
            // Immediately refresh when resuming
            this.refreshMonitorData();
        }
    },

    /**
     * Fetch and display monitoring data
     */
    refreshMonitorData: function() {
        const loadingDiv = document.getElementById('monitor-loading');
        const noMigrationDiv = document.getElementById('monitor-no-migration');
        const activeDiv = document.getElementById('monitor-active');

        // Show loading on first load
        if (loadingDiv && loadingDiv.style.display !== 'none' && !this.liveMonitor.migrationId) {
            loadingDiv.style.display = 'block';
            noMigrationDiv.style.display = 'none';
            activeDiv.style.display = 'none';
        }

        const params = new URLSearchParams();
        if (this.liveMonitor.migrationId) {
            params.set('migrationId', this.liveMonitor.migrationId);
        }
        params.set('logLines', this.config.monitorLogLines ?? 0);

        const url = `${this.config.getLiveMonitorUrl}?${params.toString()}`;

        fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (loadingDiv) loadingDiv.style.display = 'none';

            if (!data.success || !data.hasMigration) {
                // No migration found
                if (noMigrationDiv) noMigrationDiv.style.display = 'block';
                if (activeDiv) activeDiv.style.display = 'none';
                document.getElementById('monitor-pause-btn').style.display = 'none';
                return;
            }

            // Show active migration
            if (noMigrationDiv) noMigrationDiv.style.display = 'none';
            if (activeDiv) activeDiv.style.display = 'block';
            document.getElementById('monitor-pause-btn').style.display = 'inline-block';

            this.updateMonitorDisplay(data);
        })
        .catch(error => {
            console.error('Failed to fetch monitoring data:', error);
            if (loadingDiv) loadingDiv.style.display = 'none';

            // Show error message
            const errorSection = document.getElementById('monitor-error-section');
            const errorMessage = document.getElementById('monitor-error-message');
            if (errorSection && errorMessage) {
                errorSection.style.display = 'block';
                errorMessage.textContent = 'Failed to fetch monitoring data: ' + error.message;
            }
        });
    },

    /**
     * Update monitor display with data
     */
    updateMonitorDisplay: function(data) {
        const migration = data.migration;
        this.liveMonitor.migrationId = migration.id;

        // Update migration info
        document.getElementById('monitor-migration-id').textContent = migration.id || '-';
        document.getElementById('monitor-phase').textContent = migration.phase || '-';
        document.getElementById('monitor-status').textContent = migration.status || '-';

        // Update status badge
        const statusBadge = document.getElementById('monitor-status-badge');
        if (statusBadge) {
            statusBadge.textContent = (migration.status || 'unknown').toUpperCase();
            statusBadge.className = 'badge ' + (migration.status || 'unknown');
            statusBadge.style.display = 'inline-block';
        }

        // Update process status
        const processText = migration.isProcessRunning
            ? `Running (PID: ${migration.pid})`
            : (migration.pid ? `Stopped (PID: ${migration.pid})` : 'No process');
        document.getElementById('monitor-process').textContent = processText;

        // Update progress
        const progressPercent = migration.progressPercent || 0;
        const progressFill = document.getElementById('monitor-progress-fill');
        if (progressFill) {
            progressFill.style.width = progressPercent + '%';
            // Color based on progress
            if (progressPercent >= 100) {
                progressFill.style.background = '#10b981'; // green
            } else if (progressPercent > 0) {
                progressFill.style.background = '#3b82f6'; // blue
            }
        }

        document.getElementById('monitor-progress-text').textContent =
            `${migration.processedCount || 0} / ${migration.totalCount || 0} items processed`;
        document.getElementById('monitor-progress-percent').textContent = progressPercent.toFixed(1) + '%';

        // Update stats
        const statsSection = document.getElementById('monitor-stats-section');
        const statsDiv = document.getElementById('monitor-stats');
        if (migration.stats && Object.keys(migration.stats).length > 0) {
            statsSection.style.display = 'block';
            statsDiv.innerHTML = '';

            for (const [key, value] of Object.entries(migration.stats)) {
                const statItem = document.createElement('div');
                statItem.className = 'monitor-stat-item';

                const label = document.createElement('span');
                label.className = 'monitor-stat-label';
                label.textContent = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                const valueSpan = document.createElement('span');
                valueSpan.className = 'monitor-stat-value';
                valueSpan.textContent = value;

                statItem.appendChild(label);
                statItem.appendChild(valueSpan);
                statsDiv.appendChild(statItem);
            }
        } else {
            statsSection.style.display = 'none';
        }

        // Update grouped logs
        const logTasksContainer = document.getElementById('monitor-log-tasks');
        if (logTasksContainer) {
            logTasksContainer.innerHTML = '';

            if (Array.isArray(data.logTasks) && data.logTasks.length) {
                data.logTasks.forEach((task) => {
                    const taskBlock = document.createElement('div');
                    taskBlock.className = 'monitor-log-task';

                    const heading = document.createElement('div');
                    heading.className = 'monitor-log-task__header';
                    heading.innerHTML = `
                        <div>
                            <div class="monitor-log-task__command">${task.command || 'Command'}</div>
                            <div class="monitor-log-task__meta">${task.migrationId || ''}</div>
                        </div>
                        <span class="badge ${task.status || 'unknown'}">${(task.status || 'unknown').toUpperCase()}</span>
                    `;

                    const logPre = document.createElement('pre');
                    logPre.className = 'monitor-logs';
                    const logText = Array.isArray(task.lines) ? task.lines.join('\n') : (task.lines || '');
                    logPre.textContent = logText || 'No logs available yet...';

                    taskBlock.appendChild(heading);
                    taskBlock.appendChild(logPre);
                    logTasksContainer.appendChild(taskBlock);
                });
            } else {
                const empty = document.createElement('div');
                empty.className = 'info-box';
                empty.textContent = 'Logs will appear here as soon as the queue starts processing.';
                logTasksContainer.appendChild(empty);
            }
        }

        // Update error section
        const errorSection = document.getElementById('monitor-error-section');
        const errorMessage = document.getElementById('monitor-error-message');
        if (migration.errorMessage) {
            errorSection.style.display = 'block';
            errorMessage.textContent = migration.errorMessage;
        } else {
            errorSection.style.display = 'none';
        }
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
