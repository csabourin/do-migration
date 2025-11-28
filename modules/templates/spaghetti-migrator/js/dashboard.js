/**
 * AWS to DigitalOcean Migration Dashboard
 * Interactive dashboard for orchestrating the complete migration
 *
 * Architecture:
 * - Modular design with clear separation of concerns
 * - State management, UI updates, API calls, and command execution separated
 * - All commands run via queue system (non-blocking)
 */

(function() {
    'use strict';

    // ============================================================================
    // STATE MANAGER
    // ============================================================================
    const StateManager = {
        runningModules: new Set(),
        completedModules: new Set(),
        pollingIntervals: new Map(),
        lastFocusedElement: null,
        focusableElements: 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',

        addRunning(command) {
            this.runningModules.add(command);
        },

        removeRunning(command) {
            this.runningModules.delete(command);
        },

        isRunning(command) {
            return this.runningModules.has(command);
        },

        addCompleted(moduleId) {
            this.completedModules.add(moduleId);
        },

        isCompleted(moduleId) {
            return this.completedModules.has(moduleId);
        },

        setPollingInterval(key, intervalId) {
            if (this.pollingIntervals.has(key)) {
                clearInterval(this.pollingIntervals.get(key));
            }
            this.pollingIntervals.set(key, intervalId);
        },

        clearPollingInterval(key) {
            if (this.pollingIntervals.has(key)) {
                clearInterval(this.pollingIntervals.get(key));
                this.pollingIntervals.delete(key);
            }
        },

        clearAllPolling() {
            this.pollingIntervals.forEach(intervalId => clearInterval(intervalId));
            this.pollingIntervals.clear();
        }
    };

    // ============================================================================
    // CONFIGURATION
    // ============================================================================
    const Config = {
        get data() {
            return window.migrationDashboard || {};
        },

        get csrfToken() {
            return this.data.csrfToken;
        },

        get statusUrl() {
            return this.data.statusUrl;
        },

        get updateStatusUrl() {
            return this.data.updateStatusUrl;
        },

        get updateModuleStatusUrl() {
            return this.data.updateModuleStatusUrl;
        },

        get runCommandQueueUrl() {
            return this.data.runCommandQueueUrl;
        },

        get streamMigrationUrl() {
            return this.data.streamMigrationUrl;
        },

        get liveMonitorUrl() {
            return this.data.liveMonitorUrl || this.data.getLiveMonitorUrl;
        },

        get executionMode() {
            // Always use SSE (hybrid) mode
            return 'sse';
        },

        // Check if dev mode is enabled (via Craft's devMode setting)
        get isDevMode() {
            return this.data.devMode || false;
        }
    };

    // ============================================================================
    // API CLIENT
    // ============================================================================
    const APIClient = {
        async checkStatus() {
            try {
                const response = await fetch(Config.statusUrl, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                return await response.json();
            } catch (error) {
                console.error('Failed to check status:', error);
                throw error;
            }
        },

        async updateModuleStatus(moduleId, status, error = null) {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Config.csrfToken);
            formData.append('moduleId', moduleId);
            formData.append('status', status);
            if (error) {
                formData.append('error', error);
            }

            try {
                const response = await fetch(Config.updateModuleStatusUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                return await response.json();
            } catch (error) {
                console.error('Error updating module status:', error);
                throw error;
            }
        },

        async queueCommand(command, args = {}) {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Config.csrfToken);
            formData.append('command', command);
            formData.append('args', JSON.stringify(args));
            formData.append('dryRun', args.dryRun ? '1' : '0');

            try {
                const response = await fetch(Config.runCommandQueueUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                return await response.json();
            } catch (error) {
                console.error('Failed to queue command:', error);
                throw error;
            }
        }
    };

    // ============================================================================
    // ACCESSIBILITY MANAGER
    // ============================================================================
    const AccessibilityManager = {
        init() {
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    this.handleEscapeKey();
                }
            });
        },

        handleEscapeKey() {
            const openModal = document.querySelector('.modal[style*="display: flex"]');
            if (openModal) {
                UIManager.closeModal(openModal);
                return;
            }

            const openDialog = document.querySelector('.confirmation-dialog');
            if (openDialog) {
                openDialog.remove();
                if (StateManager.lastFocusedElement) {
                    StateManager.lastFocusedElement.focus();
                    StateManager.lastFocusedElement = null;
                }
            }
        },

        announceToScreenReader(message) {
            const announcer = document.getElementById('sr-announcements');
            if (announcer) {
                announcer.textContent = message;
                setTimeout(() => {
                    announcer.textContent = '';
                }, 1000);
            }
        },

        trapFocus(modal) {
            const focusableElements = Array.from(modal.querySelectorAll(StateManager.focusableElements));
            if (focusableElements.length === 0) return;

            const firstFocusable = focusableElements[0];
            const lastFocusable = focusableElements[focusableElements.length - 1];

            if (modal._focusTrapHandler) {
                modal.removeEventListener('keydown', modal._focusTrapHandler);
            }

            const trapHandler = (e) => {
                if (e.key !== 'Tab' && e.keyCode !== 9) return;

                if (e.shiftKey) {
                    if (document.activeElement === firstFocusable) {
                        lastFocusable.focus();
                        e.preventDefault();
                    }
                } else {
                    if (document.activeElement === lastFocusable) {
                        firstFocusable.focus();
                        e.preventDefault();
                    }
                }
            };

            modal.addEventListener('keydown', trapHandler);
            modal._focusTrapHandler = trapHandler;
        }
    };

    // ============================================================================
    // UI MANAGER
    // ============================================================================
    const UIManager = {
        showModuleOutput(moduleCard, output) {
            const outputSection = moduleCard.querySelector('.module-output');
            const outputContent = moduleCard.querySelector('.output-content');

            if (outputSection && outputContent) {
                outputContent.textContent = output;
                outputSection.style.display = 'block';
            }
        },

        appendModuleOutput(moduleCard, text) {
            const outputContent = moduleCard.querySelector('.output-content');
            if (outputContent) {
                outputContent.textContent += text;
                outputContent.scrollTop = outputContent.scrollHeight;
            }
        },

        updateModuleProgress(moduleCard, percent, text) {
            const progressSection = moduleCard.querySelector('.module-progress');
            const progressBar = moduleCard.querySelector('.progress-bar-fill');
            const progressText = moduleCard.querySelector('.progress-text');

            if (progressSection) {
                progressSection.style.display = 'block';
            }

            if (progressBar) {
                progressBar.style.width = `${percent}%`;
                progressBar.setAttribute('aria-valuenow', percent);
            }

            if (progressText && text) {
                progressText.textContent = text;
            }
        },

        updateModuleStats(moduleCard, stats) {
            const statsContainer = moduleCard.querySelector('.module-stats');
            if (!statsContainer) return;

            statsContainer.innerHTML = '';
            statsContainer.style.display = 'block';

            const statsList = document.createElement('ul');
            statsList.className = 'stats-list';

            const statMappings = {
                processedAssets: 'Processed',
                totalAssets: 'Total Assets',
                filesProcessed: 'Files Processed',
                filesCopied: 'Files Copied',
                filesSkipped: 'Files Skipped',
                errors: 'Errors',
                currentPhase: 'Phase',
                elapsedTime: 'Time Elapsed',
                estimatedRemaining: 'Time Remaining'
            };

            Object.entries(stats).forEach(([key, value]) => {
                if (statMappings[key] && value !== null && value !== undefined && value !== '') {
                    const li = document.createElement('li');
                    li.innerHTML = `<strong>${statMappings[key]}:</strong> ${value}`;
                    statsList.appendChild(li);
                }
            });

            if (statsList.children.length > 0) {
                statsContainer.appendChild(statsList);
            }
        },

        setModuleRunning(moduleCard, isRunning) {
            const runBtn = moduleCard.querySelector('.run-module-btn');
            const cancelBtn = moduleCard.querySelector('.cancel-module-btn');
            const statusIndicator = moduleCard.querySelector('.status-indicator');

            if (isRunning) {
                moduleCard.classList.add('module-running');
                moduleCard.classList.remove('module-completed');

                if (runBtn) {
                    runBtn.disabled = true;
                    runBtn.textContent = 'Running...';
                }

                if (cancelBtn) {
                    cancelBtn.style.display = 'inline-block';
                }

                if (statusIndicator) {
                    statusIndicator.textContent = '⟳';
                    statusIndicator.classList.add('running');
                    statusIndicator.classList.remove('completed');
                }
            } else {
                moduleCard.classList.remove('module-running');

                if (runBtn) {
                    runBtn.disabled = false;
                    const originalText = runBtn.getAttribute('data-original-text') || 'Run';
                    runBtn.textContent = originalText;
                }

                if (cancelBtn) {
                    cancelBtn.style.display = 'none';
                }

                if (statusIndicator) {
                    statusIndicator.textContent = '○';
                    statusIndicator.classList.remove('running');
                }
            }
        },

        markModuleCompleted(moduleCard, command) {
            moduleCard.classList.remove('module-running');
            moduleCard.classList.add('module-completed');

            const statusIndicator = moduleCard.querySelector('.status-indicator');
            if (statusIndicator) {
                statusIndicator.textContent = '✓';
                statusIndicator.classList.remove('running');
                statusIndicator.classList.add('completed');
            }

            const runBtn = moduleCard.querySelector('.run-module-btn');
            if (runBtn) {
                runBtn.textContent = 'Completed ✓';
                runBtn.disabled = true;
            }

            const cancelBtn = moduleCard.querySelector('.cancel-module-btn');
            if (cancelBtn) {
                cancelBtn.style.display = 'none';
            }

            const moduleId = moduleCard.getAttribute('data-module-id');
            if (moduleId) {
                StateManager.addCompleted(moduleId);
                APIClient.updateModuleStatus(moduleId, 'completed').catch(err => {
                    console.error('Failed to save completed status:', err);
                });
            }

            WorkflowManager.updateWorkflowStepper();

            Craft.cp.displayNotice('✓ Command completed successfully');
            AccessibilityManager.announceToScreenReader('Command completed successfully');
        },

        openModal(modal) {
            StateManager.lastFocusedElement = document.activeElement;
            modal.style.display = 'flex';

            const focusableElements = modal.querySelectorAll(StateManager.focusableElements);
            if (focusableElements.length > 0) {
                focusableElements[0].focus();
            }

            AccessibilityManager.trapFocus(modal);
        },

        closeModal(modal) {
            modal.style.display = 'none';

            if (StateManager.lastFocusedElement) {
                StateManager.lastFocusedElement.focus();
                StateManager.lastFocusedElement = null;
            }
        },

        showConfirmationDialog(title, message, onConfirm, options = {}) {
            StateManager.lastFocusedElement = document.activeElement;

            const dialog = document.createElement('div');
            dialog.className = options.className || 'confirmation-dialog';
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-labelledby', 'confirm-dialog-title');
            dialog.setAttribute('aria-describedby', 'confirm-dialog-message');

            const icon = options.icon || '⚠️';
            const confirmText = options.confirmText || 'Confirm & Proceed';
            const cancelText = options.cancelText || 'Cancel';

            dialog.innerHTML = `
                <div class="confirmation-dialog-content">
                    <div class="confirmation-dialog-icon" aria-hidden="true">${icon}</div>
                    <h3 id="confirm-dialog-title" class="confirmation-dialog-title">${title}</h3>
                    <div id="confirm-dialog-message" class="confirmation-dialog-message">${message}</div>
                    <div class="confirmation-dialog-actions">
                        <button type="button" class="btn secondary cancel-btn">${cancelText}</button>
                        <button type="button" class="btn submit confirm-btn">${confirmText}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(dialog);

            setTimeout(() => {
                const cancelBtn = dialog.querySelector('.cancel-btn');
                if (cancelBtn) cancelBtn.focus();
            }, 10);

            AccessibilityManager.trapFocus(dialog);

            const closeDialog = () => {
                dialog.remove();
                if (StateManager.lastFocusedElement) {
                    StateManager.lastFocusedElement.focus();
                    StateManager.lastFocusedElement = null;
                }
            };

            dialog.querySelector('.cancel-btn').addEventListener('click', closeDialog);

            dialog.querySelector('.confirm-btn').addEventListener('click', () => {
                dialog.remove();
                if (onConfirm) onConfirm();
            });

            dialog.addEventListener('click', (e) => {
                if (e.target === dialog) closeDialog();
            });
        },

        showWarningBanner(title, message) {
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

                setTimeout(() => {
                    if (banner.parentElement) {
                        banner.remove();
                    }
                }, 10000);
            }
        },

        setupCollapsiblePhases() {
            const phaseSections = document.querySelectorAll('.phase-section');

            phaseSections.forEach(section => {
                const phaseId = section.getAttribute('data-phase-id');
                const phaseHeader = section.querySelector('.phase-header');

                if (!phaseHeader) return;

                section.classList.add('collapsible');

                const phaseNumber = phaseHeader.querySelector('.phase-number');
                const phaseInfo = phaseHeader.querySelector('.phase-info');

                if (phaseNumber && phaseInfo) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'phase-header-wrapper';

                    const collapseIcon = document.createElement('span');
                    collapseIcon.className = 'phase-collapse-icon';
                    collapseIcon.setAttribute('aria-hidden', 'true');
                    collapseIcon.textContent = '▼';

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

                phaseHeader.addEventListener('click', function() {
                    section.classList.toggle('collapsed');

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

                const collapsedPhases = JSON.parse(localStorage.getItem('collapsedPhases') || '[]');
                if (collapsedPhases.includes(phaseId)) {
                    section.classList.add('collapsed');
                }
            });
        }
    };

    // ============================================================================
    // WORKFLOW MANAGER
    // ============================================================================
    const WorkflowManager = {
        phaseCheckpoints: {
            0: ['filesystem', 'volume-config'],
            1: ['migration-check'],
            2: ['url-replacement'],
            3: ['template-replace'],
            4: ['switch-to-do'],
            5: ['image-migration'],
            6: ['migration-diag'],
            7: ['transform-discovery-all']
        },

        dependencies: {
            'image-migration': {
                requires: ['switch-to-do'],
                message: 'You must complete the Filesystem Switch (Phase 4) before running File Migration (Phase 5). Switching filesystems first ensures volumes point to DigitalOcean during migration.'
            },
            'switch-to-do': {
                requires: ['migration-check'],
                message: 'You must run Pre-Flight Checks (Phase 1) before switching filesystems.'
            }
        },

        validateWorkflowOrder(moduleId) {
            const dep = this.dependencies[moduleId];
            if (!dep) return true;

            const missing = dep.requires.filter(reqId => !StateManager.isCompleted(reqId));

            if (missing.length > 0) {
                UIManager.showWarningBanner('Workflow Order Issue', dep.message);
                return false;
            }

            return true;
        },

        updateWorkflowStepper() {
            let currentPhase = 0;

            for (let phase = 7; phase >= 0; phase--) {
                const phaseModules = this.phaseCheckpoints[phase];
                if (phaseModules && phaseModules.some(moduleId => StateManager.isCompleted(moduleId))) {
                    currentPhase = phase + 1;
                    break;
                }
            }

            document.querySelectorAll('.stepper-step').forEach((step) => {
                const stepPhase = parseInt(step.getAttribute('data-phase'));
                step.classList.remove('active', 'completed');

                if (stepPhase < currentPhase) {
                    step.classList.add('completed');
                } else if (stepPhase === currentPhase) {
                    step.classList.add('active');
                }
            });
        },

        handleManualStepCompletion(moduleCard, moduleId, moduleTitle) {
            if (StateManager.isCompleted(moduleId)) {
                Craft.cp.displayNotice('This step is already marked as completed');
                return;
            }

            const message = `
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
            `;

            UIManager.showConfirmationDialog(
                'Confirm Manual Step Completion',
                message,
                () => {
                    if (moduleCard && moduleId) {
                        moduleCard.classList.add('module-completed', 'manual-completed');
                        const statusIndicator = moduleCard.querySelector('.status-indicator');
                        if (statusIndicator) {
                            statusIndicator.textContent = '✓';
                            statusIndicator.classList.add('completed');
                        }

                        const runBtn = moduleCard.querySelector('.run-module-btn');
                        if (runBtn) {
                            runBtn.textContent = 'Completed ✓';
                            runBtn.disabled = true;
                        }

                        StateManager.addCompleted(moduleId);
                        APIClient.updateModuleStatus(moduleId, 'completed').catch(console.error);

                        this.updateWorkflowStepper();

                        Craft.cp.displayNotice(`✓ ${moduleTitle} marked as completed`);
                        AccessibilityManager.announceToScreenReader(`${moduleTitle} marked as completed`);
                    }
                },
                {
                    icon: '📋',
                    className: 'confirmation-dialog manual-completion-modal',
                    confirmText: "Yes, I've Completed This Step",
                    cancelText: 'Not Yet'
                }
            );
        }
    };

    // ============================================================================
    // PROGRESS MONITOR
    // ============================================================================
    const ProgressMonitor = {
        pollQueueJobProgress(moduleCard, command, jobId, migrationId, isDryRun) {
            const commandName = command.split('/').pop().replace(/-/g, ' ');
            UIManager.updateModuleProgress(moduleCard, 5, 'Waiting for queue to pick up job...');

            const pollInterval = setInterval(async () => {
                try {
                    const response = await fetch(`${Config.data.queueProgressUrl}?jobId=${jobId}&migrationId=${migrationId}`, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    });

                    const data = await response.json();

                    if (data.status === 'completed') {
                        clearInterval(pollInterval);
                        StateManager.clearPollingInterval(`queue-${jobId}`);

                        UIManager.updateModuleProgress(moduleCard, 100, 'Completed!');

                        if (data.output) {
                            UIManager.showModuleOutput(moduleCard, data.output);
                        }

                        UIManager.markModuleCompleted(moduleCard, command);
                        StateManager.removeRunning(command);
                        UIManager.setModuleRunning(moduleCard, false);
                    } else if (data.status === 'failed') {
                        clearInterval(pollInterval);
                        StateManager.clearPollingInterval(`queue-${jobId}`);

                        UIManager.showModuleOutput(moduleCard, data.error || 'Job failed');
                        Craft.cp.displayError('Command failed: ' + (data.error || 'Unknown error'));

                        StateManager.removeRunning(command);
                        UIManager.setModuleRunning(moduleCard, false);
                    } else if (data.status === 'running') {
                        if (data.progress !== undefined) {
                            UIManager.updateModuleProgress(moduleCard, data.progress, data.progressText || 'Processing...');
                        }

                        if (data.output) {
                            UIManager.showModuleOutput(moduleCard, data.output);
                        }

                        if (data.stats) {
                            UIManager.updateModuleStats(moduleCard, data.stats);
                        }
                    }
                } catch (error) {
                    console.error('Failed to poll job progress:', error);
                }
            }, 2000);

            StateManager.setPollingInterval(`queue-${jobId}`, pollInterval);
        },

        updateMigrationProgress(moduleCard, migrationId, retryCount = 0) {
            const maxRetries = 3;

            fetch(`${Config.data.migrationProgressUrl}?migrationId=${migrationId}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.progress !== undefined) {
                    UIManager.updateModuleProgress(
                        moduleCard,
                        data.progress,
                        data.currentPhase || 'Processing...'
                    );
                }

                if (data.output) {
                    UIManager.showModuleOutput(moduleCard, data.output);
                }

                if (data.stats) {
                    UIManager.updateModuleStats(moduleCard, data.stats);
                }
            })
            .catch(error => {
                if (retryCount < maxRetries) {
                    setTimeout(() => {
                        this.updateMigrationProgress(moduleCard, migrationId, retryCount + 1);
                    }, 2000);
                }
            });
        },

        parseProgressFromOutput(moduleCard, line) {
            const progressMatch = line.match(/(\d+)%/);
            if (progressMatch) {
                const percent = parseInt(progressMatch[1], 10);
                UIManager.updateModuleProgress(moduleCard, percent, line);
            }

            const statsMatch = line.match(/(\d+)\/(\d+)/);
            if (statsMatch) {
                const current = parseInt(statsMatch[1], 10);
                const total = parseInt(statsMatch[2], 10);
                const percent = Math.round((current / total) * 100);
                UIManager.updateModuleProgress(moduleCard, percent, line);
            }
        }
    };

    // ============================================================================
    // COMMAND EXECUTOR
    // ============================================================================
    const CommandExecutor = {
        criticalModules: ['switch-to-do', 'image-migration', 'filesystem-switch/to-do'],

        confirmations: {
            'switch-to-do': {
                title: 'Confirm Filesystem Switch',
                message: '<strong>CRITICAL OPERATION:</strong> This will switch all volumes to use DigitalOcean Spaces. Ensure you have:<br/><br/>• Completed all previous phases<br/>• Synced files from AWS to DO using rclone<br/>• Created a database backup<br/><br/>This operation is reversible, but should be done carefully.'
            },
            'image-migration': {
                title: 'Confirm File Migration',
                message: '<strong>IMPORTANT:</strong> This will migrate all asset files from AWS to DigitalOcean. Ensure you have:<br/><br/>• Completed Filesystem Switch (Phase 4)<br/>• Sufficient disk space<br/>• Created a database backup<br/><br/>This process may take several hours and creates automatic backups.'
            }
        },

        runCommand(command, args = {}) {
            let moduleCard = document.querySelector(`.module-card[data-command="${command}"]`);

            if (!moduleCard) {
                const triggerButton = document.querySelector(`.run-module-btn[data-command="${command}"]`);
                if (triggerButton) {
                    moduleCard = triggerButton.closest('.module-card');
                }
            }

            if (!moduleCard) {
                Craft.cp.displayError('Unable to locate module card for command: ' + command);
                return;
            }

            const moduleId = moduleCard.getAttribute('data-module-id');

            if (StateManager.isRunning(command)) {
                Craft.cp.displayNotice('This module is already running');
                return;
            }

            if (!args.dryRun) {
                if (!WorkflowManager.validateWorkflowOrder(moduleId)) {
                    return;
                }
            }

            if (this.criticalModules.includes(moduleId) && !args.yes && !args.dryRun) {
                const config = this.confirmations[moduleId] || this.confirmations['switch-to-do'];

                UIManager.showConfirmationDialog(config.title, config.message, () => {
                    args.yes = true;
                    this.runCommand(command, args);
                });
                return;
            }

            StateManager.addRunning(command);
            UIManager.setModuleRunning(moduleCard, true);

            if (moduleId && !args.dryRun) {
                APIClient.updateModuleStatus(moduleId, 'running').catch(console.error);
            }

            const progressSection = moduleCard.querySelector('.module-progress');
            if (progressSection) {
                progressSection.style.display = 'block';
            }

            // Always use SSE (hybrid) mode
            this.runCommandSSE(moduleCard, command, args);
        },

        async runCommandQueue(moduleCard, command, args = {}) {
            const commandName = command.split('/').pop().replace(/-/g, ' ');
            UIManager.showModuleOutput(moduleCard, `Starting ${commandName} via queue system...\n`);
            UIManager.updateModuleProgress(moduleCard, 0, 'Queuing job...');

            try {
                const data = await APIClient.queueCommand(command, args);

                if (data.success) {
                    const { jobId, migrationId } = data;

                    UIManager.showModuleOutput(moduleCard,
                        `Job queued successfully!\n` +
                        `Job ID: ${jobId}\n` +
                        `Migration ID: ${migrationId}\n\n` +
                        `The command is now running in the background via Craft Queue.\n` +
                        `You can safely refresh this page or open other admin windows.\n` +
                        `The site/Control Panel will remain fully responsive.\n\n` +
                        `Polling for progress updates every 2 seconds...\n\n`
                    );

                    Craft.cp.displayNotice(data.message || 'Command queued successfully');

                    ProgressMonitor.pollQueueJobProgress(moduleCard, command, jobId, migrationId, args.dryRun);
                } else {
                    throw new Error(data.error || 'Failed to queue command');
                }
            } catch (error) {
                UIManager.showModuleOutput(moduleCard, `Error: ${error.message}\n`);
                Craft.cp.displayError('Failed to queue command: ' + error.message);
                StateManager.removeRunning(command);
                UIManager.setModuleRunning(moduleCard, false);
            }
        },

        runCommandSSE(moduleCard, command, args = {}) {
            const params = new URLSearchParams({
                command: command,
                dryRun: args.dryRun ? '1' : '0',
                skipBackup: args.skipBackup ? '1' : '0',
                skipInlineDetection: args.skipInlineDetection ? '1' : '0'
            });

            const url = `${Config.streamMigrationUrl}?${params.toString()}`;

            if (Config.isDevMode) {
                UIManager.showModuleOutput(moduleCard, 'Connecting to stream...\n');
            }

            const eventSource = new EventSource(url);
            let migrationId = null;
            let detachedMode = false;

            eventSource.onopen = () => {
                if (Config.isDevMode) {
                    UIManager.appendModuleOutput(moduleCard, 'Connected to stream. Starting migration...\n\n');
                }
            };

            eventSource.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);

                    // Store migrationId from backend
                    if (data.migrationId && !migrationId) {
                        migrationId = data.migrationId;
                        moduleCard._migrationId = migrationId;
                    }

                    // Handle both 'type' (legacy) and 'status' (current) fields
                    const eventType = data.type || data.status;
                    this.handleStreamEvent(moduleCard, command, eventType, data, args.dryRun);

                    // If we received 'detached' status, mark for graceful close
                    if (data.status === 'detached') {
                        detachedMode = true;
                    }
                } catch (error) {
                    UIManager.appendModuleOutput(moduleCard, event.data + '\n');
                }
            };

            eventSource.onerror = (error) => {
                console.error('SSE Error:', error);
                eventSource.close();

                // Only show error if not in detached mode (expected close)
                if (!detachedMode) {
                    StateManager.removeRunning(command);
                    UIManager.setModuleRunning(moduleCard, false);

                    if (error.target.readyState === EventSource.CLOSED) {
                        UIManager.appendModuleOutput(moduleCard, '\nStream closed unexpectedly.\n');
                    }
                }
                // In detached mode, polling will handle progress updates
            };

            moduleCard._eventSource = eventSource;
        },

        handleStreamEvent(moduleCard, command, eventType, eventData, isDryRun) {
            switch (eventType) {
                case 'starting':
                    if (eventData.message && Config.isDevMode) {
                        UIManager.appendModuleOutput(moduleCard, eventData.message + '\n');
                    }
                    break;

                case 'running':
                    if (eventData.message && Config.isDevMode) {
                        UIManager.appendModuleOutput(moduleCard, eventData.message + '\n');
                    }
                    if (eventData.pid && Config.isDevMode) {
                        UIManager.appendModuleOutput(moduleCard, `Process ID: ${eventData.pid}\n`);
                    }
                    break;

                case 'detached':
                    // Process is running in background, switch to polling mode
                    if (eventData.message && Config.isDevMode) {
                        UIManager.appendModuleOutput(moduleCard, eventData.message + '\n');
                    }
                    if (eventData.pollEndpoint) {
                        if (Config.isDevMode) {
                            UIManager.appendModuleOutput(moduleCard, 'Switching to polling mode for progress updates...\n');
                        }
                        this.startPollingProgress(moduleCard, command, eventData.migrationId);
                    }
                    // Close the SSE connection gracefully
                    if (moduleCard._eventSource) {
                        moduleCard._eventSource.close();
                    }
                    break;

                case 'progress':
                    if (eventData.percent !== undefined) {
                        UIManager.updateModuleProgress(moduleCard, eventData.percent, eventData.message || '');
                    }
                    if (eventData.message) {
                        UIManager.appendModuleOutput(moduleCard, eventData.message + '\n');
                    }
                    if (eventData.output) {
                        UIManager.appendModuleOutput(moduleCard, eventData.output);
                    }
                    break;

                case 'output':
                    if (eventData.line) {
                        UIManager.appendModuleOutput(moduleCard, eventData.line + '\n');
                        ProgressMonitor.parseProgressFromOutput(moduleCard, eventData.line);
                    }
                    break;

                case 'stats':
                    UIManager.updateModuleStats(moduleCard, eventData);
                    break;

                case 'completed':
                case 'complete':
                    UIManager.updateModuleProgress(moduleCard, 100, 'Completed!');
                    UIManager.appendModuleOutput(moduleCard, '\n✓ Command completed successfully!\n');
                    UIManager.markModuleCompleted(moduleCard, command);
                    StateManager.removeRunning(command);
                    UIManager.setModuleRunning(moduleCard, false);
                    if (moduleCard._eventSource) {
                        moduleCard._eventSource.close();
                    }
                    // Stop polling if active
                    if (moduleCard._pollInterval) {
                        clearInterval(moduleCard._pollInterval);
                        moduleCard._pollInterval = null;
                    }
                    break;

                case 'failed':
                case 'error':
                    UIManager.appendModuleOutput(moduleCard, `\n✗ Error: ${eventData.message || eventData.error}\n`);
                    if (eventData.error || eventData.message) {
                        Craft.cp.displayError('Command failed: ' + (eventData.error || eventData.message));
                    }
                    StateManager.removeRunning(command);
                    UIManager.setModuleRunning(moduleCard, false);
                    if (moduleCard._eventSource) {
                        moduleCard._eventSource.close();
                    }
                    // Stop polling if active
                    if (moduleCard._pollInterval) {
                        clearInterval(moduleCard._pollInterval);
                        moduleCard._pollInterval = null;
                    }
                    break;
            }
        },

        startPollingProgress(moduleCard, command, migrationId) {
            // Poll every 2 seconds for progress updates
            const pollInterval = 2000;

            const pollForProgress = async () => {
                try {
                    const url = `${Config.liveMonitorUrl}?migrationId=${encodeURIComponent(migrationId)}`;
                    const response = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const data = await response.json();

                    if (data.success && data.migration) {
                        const migration = data.migration;

                        // Update progress
                        if (migration.progressPercent !== undefined) {
                            UIManager.updateModuleProgress(moduleCard, migration.progressPercent, migration.phase || '');
                        }

                        // Append new output if available
                        if (data.logs && data.logs.length > 0) {
                            const lastOutputLine = moduleCard._lastOutputLine || 0;
                            const newLines = data.logs.slice(lastOutputLine);
                            if (newLines.length > 0) {
                                newLines.forEach(line => {
                                    UIManager.appendModuleOutput(moduleCard, line + '\n');
                                });
                                moduleCard._lastOutputLine = data.logs.length;
                            }
                        }

                        // Check if completed or failed
                        if (migration.status === 'completed') {
                            UIManager.updateModuleProgress(moduleCard, 100, 'Completed!');
                            UIManager.appendModuleOutput(moduleCard, '\n✓ Command completed successfully!\n');
                            UIManager.markModuleCompleted(moduleCard, command);
                            StateManager.removeRunning(command);
                            UIManager.setModuleRunning(moduleCard, false);
                            if (moduleCard._pollInterval) {
                                clearInterval(moduleCard._pollInterval);
                                moduleCard._pollInterval = null;
                            }
                        } else if (migration.status === 'failed') {
                            UIManager.appendModuleOutput(moduleCard, `\n✗ Command failed: ${migration.errorMessage || 'Unknown error'}\n`);
                            StateManager.removeRunning(command);
                            UIManager.setModuleRunning(moduleCard, false);
                            if (moduleCard._pollInterval) {
                                clearInterval(moduleCard._pollInterval);
                                moduleCard._pollInterval = null;
                            }
                        }
                    }
                } catch (error) {
                    console.error('Polling error:', error);
                    // Continue polling despite errors
                }
            };

            // Start polling
            moduleCard._pollInterval = setInterval(pollForProgress, pollInterval);
            // Poll immediately
            pollForProgress();
        },

        cancelCommand(moduleCard, command) {
            UIManager.showConfirmationDialog(
                'Cancel Command',
                'Are you sure you want to cancel this command? The process will be terminated.',
                () => {
                    if (moduleCard._eventSource) {
                        moduleCard._eventSource.close();
                    }

                    StateManager.removeRunning(command);
                    UIManager.setModuleRunning(moduleCard, false);
                    UIManager.appendModuleOutput(moduleCard, '\n⚠ Command cancelled by user\n');
                    Craft.cp.displayNotice('Command cancelled');
                }
            );
        }
    };

    // ============================================================================
    // LIVE MONITOR
    // ============================================================================
    const LiveMonitor = {
        isOpen: false,
        refreshInterval: null,

        init() {
            const openBtn = document.getElementById('open-live-monitor-btn');
            if (openBtn) {
                openBtn.addEventListener('click', () => this.open());
            }

            const closeBtn = document.getElementById('close-live-monitor-btn');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => this.close());
            }

            const refreshToggle = document.getElementById('monitor-auto-refresh');
            if (refreshToggle) {
                refreshToggle.addEventListener('change', () => this.toggleRefresh());
            }

            const manualRefreshBtn = document.getElementById('monitor-manual-refresh');
            if (manualRefreshBtn) {
                manualRefreshBtn.addEventListener('click', () => this.refreshData());
            }
        },

        open() {
            const overlay = document.getElementById('live-monitor-overlay');
            if (overlay) {
                overlay.classList.add('active');
                this.isOpen = true;
                this.refreshData();

                const refreshToggle = document.getElementById('monitor-auto-refresh');
                if (refreshToggle && refreshToggle.checked) {
                    this.startAutoRefresh();
                }
            }
        },

        close() {
            const overlay = document.getElementById('live-monitor-overlay');
            if (overlay) {
                overlay.classList.remove('active');
                this.isOpen = false;
                this.stopAutoRefresh();
            }
        },

        toggleRefresh() {
            const refreshToggle = document.getElementById('monitor-auto-refresh');
            if (refreshToggle && refreshToggle.checked) {
                this.startAutoRefresh();
            } else {
                this.stopAutoRefresh();
            }
        },

        startAutoRefresh() {
            this.stopAutoRefresh();

            const intervalSelect = document.getElementById('monitor-refresh-interval');
            const interval = intervalSelect ? parseInt(intervalSelect.value) * 1000 : 5000;

            this.refreshInterval = setInterval(() => {
                if (this.isOpen) {
                    this.refreshData();
                }
            }, interval);
        },

        stopAutoRefresh() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        },

        async refreshData() {
            try {
                const response = await fetch(Config.liveMonitorUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();
                this.updateDisplay(data);
            } catch (error) {
                console.error('Failed to refresh live monitor:', error);
            }
        },

        updateDisplay(data) {
            const statusEl = document.getElementById('monitor-status');
            const progressEl = document.getElementById('monitor-progress-bar-fill');
            const progressTextEl = document.getElementById('monitor-progress-text');
            const statsEl = document.getElementById('monitor-stats-content');
            const logTasksContainer = document.getElementById('monitor-log-tasks');

            if (!data.migration) {
                if (statusEl) statusEl.textContent = 'No active migration';
                if (progressEl) progressEl.style.width = '0%';
                if (progressTextEl) progressTextEl.textContent = 'Idle';
                return;
            }

            const migration = data.migration;

            if (statusEl) {
                statusEl.textContent = migration.status || 'Unknown';
                statusEl.className = `status-badge ${migration.status || 'unknown'}`;
            }

            if (progressEl && migration.progress !== undefined) {
                progressEl.style.width = `${migration.progress}%`;
            }

            if (progressTextEl) {
                progressTextEl.textContent = migration.currentPhase || 'Processing...';
            }

            if (statsEl && migration.stats) {
                statsEl.innerHTML = '';
                Object.entries(migration.stats).forEach(([key, value]) => {
                    const stat = document.createElement('div');
                    stat.className = 'stat-item';
                    stat.innerHTML = `<strong>${key}:</strong> ${value}`;
                    statsEl.appendChild(stat);
                });
            }

            if (logTasksContainer && migration.logTasks) {
                this.updateLogTasks(logTasksContainer, migration.logTasks);
            }
        },

        updateLogTasks(container, tasks) {
            if (!tasks || tasks.length === 0) {
                if (!container.querySelector('.info-box')) {
                    container.innerHTML = '';
                    const empty = document.createElement('div');
                    empty.className = 'info-box';
                    empty.textContent = 'Logs will appear here as soon as the queue starts processing.';
                    container.appendChild(empty);
                }
                return;
            }

            const currentMigrationIds = new Set(tasks.map(t => t.migrationId));

            tasks.forEach(task => {
                let taskBlock = container.querySelector(`[data-migration-id="${task.migrationId}"]`);

                if (!taskBlock) {
                    taskBlock = document.createElement('div');
                    taskBlock.className = 'monitor-log-task';
                    taskBlock.setAttribute('data-migration-id', task.migrationId);
                    taskBlock.innerHTML = `
                        <div class="task-header">
                            <h4>${task.command || 'Unknown Command'}</h4>
                            <span class="badge ${task.status || 'unknown'}">${(task.status || 'unknown').toUpperCase()}</span>
                        </div>
                        <pre class="monitor-logs">Loading...</pre>
                    `;
                    container.appendChild(taskBlock);
                }

                const badge = taskBlock.querySelector('.badge');
                if (badge) {
                    badge.className = `badge ${task.status || 'unknown'}`;
                    badge.textContent = (task.status || 'unknown').toUpperCase();
                }

                const logPre = taskBlock.querySelector('.monitor-logs');
                if (logPre) {
                    const logText = Array.isArray(task.lines) ? task.lines.join('\n') : (task.lines || '');
                    const newContent = logText || 'No logs available yet...';

                    if (logPre.textContent !== newContent) {
                        const wasAtBottom = (logPre.scrollHeight - logPre.scrollTop - logPre.clientHeight) < 100;
                        logPre.textContent = newContent;

                        if (wasAtBottom) {
                            setTimeout(() => {
                                logPre.scrollTop = logPre.scrollHeight;
                            }, 0);
                        }
                    }
                }
            });

            const existingTasks = container.querySelectorAll('.monitor-log-task[data-migration-id]');
            existingTasks.forEach((taskBlock) => {
                const migrationId = taskBlock.getAttribute('data-migration-id');
                if (!currentMigrationIds.has(migrationId)) {
                    taskBlock.remove();
                }
            });
        }
    };

    // ============================================================================
    // UTILITY FUNCTIONS
    // ============================================================================
    const UtilityActions = {
        async testConnection() {
            Craft.cp.displayNotice('Testing connection...');

            try {
                const response = await fetch(Config.data.testConnectionUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: new FormData()
                });

                const data = await response.json();

                if (data.success) {
                    Craft.cp.displayNotice('✓ Connection test successful');
                } else {
                    Craft.cp.displayError('Connection test failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                Craft.cp.displayError('Connection test failed: ' + error.message);
            }
        },

        showCheckpoints() {
            const modal = document.getElementById('checkpoint-modal');
            if (modal) {
                UIManager.openModal(modal);

                fetch(Config.data.checkpointsUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    const content = modal.querySelector('.modal-body');
                    if (content) {
                        if (data.checkpoints && data.checkpoints.length > 0) {
                            content.innerHTML = `<pre>${JSON.stringify(data.checkpoints, null, 2)}</pre>`;
                        } else {
                            content.innerHTML = '<p>No checkpoints found.</p>';
                        }
                    }
                })
                .catch(error => {
                    console.error('Failed to load checkpoints:', error);
                });
            }
        },

        showRollbackModal() {
            UIManager.showConfirmationDialog(
                'Confirm Rollback',
                'Are you sure you want to rollback the migration? This cannot be undone.',
                () => {
                    fetch(Config.data.rollbackUrl, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: new FormData()
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Craft.cp.displayNotice('Rollback completed successfully');
                        } else {
                            Craft.cp.displayError('Rollback failed: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        Craft.cp.displayError('Rollback failed: ' + error.message);
                    });
                }
            );
        },

        showChangelog() {
            const modal = document.getElementById('changelog-modal');
            if (modal) {
                UIManager.openModal(modal);

                fetch(Config.data.changelogUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    const content = modal.querySelector('.modal-body');
                    if (content) {
                        if (data.changelog && data.changelog.length > 0) {
                            content.innerHTML = `<pre>${JSON.stringify(data.changelog, null, 2)}</pre>`;
                        } else {
                            content.innerHTML = '<p>No changelog entries found.</p>';
                        }
                    }
                })
                .catch(error => {
                    console.error('Failed to load changelog:', error);
                });
            }
        }
    };

    // ============================================================================
    // EVENT MANAGER
    // ============================================================================
    const EventManager = {
        attachEventListeners() {
            UIManager.setupCollapsiblePhases();

            document.querySelectorAll('.run-module-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const command = this.getAttribute('data-command');
                    const dryRun = this.getAttribute('data-dry-run') === 'true';
                    const supportsResume = this.getAttribute('data-supports-resume') === 'true';
                    const resumeRequested = this.getAttribute('data-resume') === 'true';
                    const isManualStep = this.hasAttribute('data-manual-step');

                    if (isManualStep) {
                        const moduleCard = this.closest('.module-card');
                        const moduleId = moduleCard ? moduleCard.getAttribute('data-module-id') : null;
                        const moduleTitle = moduleCard ? moduleCard.querySelector('.module-title')?.textContent : 'this step';
                        WorkflowManager.handleManualStepCompletion(moduleCard, moduleId, moduleTitle);
                        return;
                    }

                    CommandExecutor.runCommand(command, {
                        dryRun: dryRun,
                        resume: supportsResume && resumeRequested ? '1' : '0'
                    });
                });
            });

            const testConnectionBtn = document.getElementById('test-connection-btn');
            if (testConnectionBtn) {
                testConnectionBtn.addEventListener('click', () => UtilityActions.testConnection());
            }

            const viewCheckpointBtn = document.getElementById('view-checkpoint-btn');
            if (viewCheckpointBtn) {
                viewCheckpointBtn.addEventListener('click', () => UtilityActions.showCheckpoints());
            }

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

            document.querySelectorAll('.cancel-module-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const moduleCard = this.closest('.module-card');
                    const runBtn = moduleCard.querySelector('.run-module-btn');
                    const command = runBtn.getAttribute('data-command');
                    if (command) {
                        CommandExecutor.cancelCommand(moduleCard, command);
                    }
                });
            });

            const rollbackBtn = document.getElementById('rollback-btn');
            if (rollbackBtn) {
                rollbackBtn.addEventListener('click', () => UtilityActions.showRollbackModal());
            }

            const viewChangelogBtn = document.getElementById('view-changelog-btn');
            if (viewChangelogBtn) {
                viewChangelogBtn.addEventListener('click', () => UtilityActions.showChangelog());
            }

            document.querySelectorAll('.modal-close').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = btn.closest('.modal');
                    if (modal) {
                        UIManager.closeModal(modal);
                    }
                });
            });

            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        UIManager.closeModal(modal);
                    }
                });
            });
        }
    };

    // ============================================================================
    // MAIN DASHBOARD CONTROLLER
    // ============================================================================
    const MigrationDashboard = {
        init() {
            if (!window.migrationDashboard) {
                console.error('Migration Dashboard config not found! window.migrationDashboard is undefined.');
                return;
            }

            AccessibilityManager.init();
            EventManager.attachEventListeners();
            LiveMonitor.init();
            this.loadStateFromServer();
        },

        async loadStateFromServer() {
            try {
                const data = await APIClient.checkStatus();

                if (data.success && data.state) {
                    if (data.state.completedModules && Array.isArray(data.state.completedModules)) {
                        data.state.completedModules.forEach(module => {
                            StateManager.addCompleted(module);
                        });
                    }

                    this.updateModuleStates();
                }
            } catch (error) {
                console.error('Failed to load state from server:', error);
            }
        },

        updateModuleStates() {
            StateManager.completedModules.forEach(moduleId => {
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

            WorkflowManager.updateWorkflowStepper();
        }
    };

    // ============================================================================
    // INITIALIZATION
    // ============================================================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            MigrationDashboard.init();
        });
    } else {
        MigrationDashboard.init();
    }

    window.MigrationDashboard = MigrationDashboard;

})();
