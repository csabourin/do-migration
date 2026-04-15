/**
 * AWS to DigitalOcean Migration Dashboard
 * Interactive dashboard for orchestrating the complete migration
 *
 * Architecture:
 * - Modular design with clear separation of concerns
 * - State management, UI updates, API calls, and command execution separated
 * - Commands stream live output and fall back to detached background monitoring when needed
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

        get streamMigrationUrl() {
            return this.data.streamMigrationUrl;
        },

        get liveMonitorUrl() {
            return this.data.liveMonitorUrl || this.data.getLiveMonitorUrl;
        },

        get checkpointUrl() {
            return this.data.checkpointUrl;
        },

        get migrationProgressUrl() {
            return this.data.migrationProgressUrl || this.data.getMigrationProgressUrl;
        },

        get cancelCommandUrl() {
            return this.data.cancelCommandUrl;
        },

        get cancelStreamingMigrationUrl() {
            return this.data.cancelStreamingMigrationUrl;
        },

        get testConnectionUrl() {
            return this.data.testConnectionUrl;
        },

        get changelogUrl() {
            return this.data.changelogUrl;
        },

        get rollbackCommandBase() {
            return this.data.rollbackCommandBase || './craft spaghetti-migrator/image-migration/rollback';
        },

        get workflowPhases() {
            return Array.isArray(this.data.workflowPhases) ? this.data.workflowPhases : [];
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

        async cancelRunningCommand(command, migrationId = null) {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Config.csrfToken);

            const url = migrationId ? Config.cancelStreamingMigrationUrl : Config.cancelCommandUrl;
            if (migrationId) {
                formData.append('migrationId', migrationId);
            } else {
                formData.append('command', command);
            }

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                return await response.json();
            } catch (error) {
                console.error('Failed to cancel command:', error);
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
                if (openModal.id === 'live-monitor-modal') {
                    LiveMonitor.close();
                } else {
                    UIManager.closeModal(openModal);
                }
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
            const outputSection = moduleCard.querySelector('.module-output');
            const outputContent = moduleCard.querySelector('.output-content');
            if (outputContent) {
                if (outputSection) {
                    outputSection.style.display = 'block';
                }
                outputContent.textContent += text;
                outputContent.scrollTop = outputContent.scrollHeight;
            }
        },

        updateModuleProgress(moduleCard, percent, text) {
            const progressSection = moduleCard.querySelector('.module-progress');
            const progressBar = moduleCard.querySelector('.progress-fill');
            const progressBarContainer = moduleCard.querySelector('.progress-bar');
            const progressText = moduleCard.querySelector('.progress-text');
            const progressPercent = moduleCard.querySelector('.progress-percent');

            if (progressSection) {
                progressSection.style.display = 'block';
            }

            if (progressBar) {
                progressBar.style.width = `${percent}%`;
            }

            if (progressBarContainer) {
                progressBarContainer.setAttribute('aria-valuenow', percent);
            }

            if (progressText && text) {
                progressText.textContent = text;
            }

            if (progressPercent) {
                progressPercent.textContent = `${Math.round(percent)}%`;
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

            if (runBtn && !runBtn.getAttribute('data-original-text')) {
                runBtn.setAttribute('data-original-text', runBtn.textContent.trim());
            }

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
        dependencies: {
            'image-migration': {
                requires: ['switch-to-do'],
                message: 'You must complete the Filesystem Switch (Phase 2) before running File Organization & Cleanup (Phase 3). Switching filesystems first ensures volumes point to DigitalOcean during cleanup.'
            },
            'switch-to-do': {
                requires: ['migration-check'],
                message: 'You must run Pre-Flight Checks (Phase 1) before switching filesystems (Phase 2).'
            },
            'url-replacement': {
                requires: ['volume-consolidation-status'],
                message: 'You must complete Volume Consolidation (Phase 4) before running URL Replacement (Phase 5). All file-moving work must finish before URLs are written to the database.'
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

        getWorkflowPhases() {
            return [...Config.workflowPhases].sort((a, b) => a.phase - b.phase);
        },

        getLatestCompletedPhase(phases = this.getWorkflowPhases()) {
            const completedPhases = phases.filter(phase => (
                Array.isArray(phase.moduleIds) &&
                phase.moduleIds.some(moduleId => StateManager.isCompleted(moduleId))
            ));

            if (completedPhases.length === 0) {
                return null;
            }

            return completedPhases.reduce((latest, phase) => (
                !latest || phase.phase > latest.phase ? phase : latest
            ), null);
        },

        getActivePhase(phases = this.getWorkflowPhases()) {
            const prerequisitePhase = phases.find(phase => phase.phase === -1) || null;
            const latestCompletedPhase = this.getLatestCompletedPhase(phases);

            if (!latestCompletedPhase) {
                return prerequisitePhase;
            }

            if (latestCompletedPhase.phase < 0) {
                return prerequisitePhase || latestCompletedPhase;
            }

            const nextPhase = phases.find(phase => phase.phase > latestCompletedPhase.phase);
            return nextPhase || latestCompletedPhase;
        },

        updateWorkflowHeader(activePhase, latestCompletedPhase) {
            const statusBadge = document.querySelector('.status-badge');
            const statusText = document.querySelector('.status-text');
            const statusDetail = document.querySelector('.status-detail');
            const jumpBtn = document.querySelector('.jump-to-phase-btn');

            if (statusBadge && activePhase) {
                statusBadge.setAttribute('data-status', String(activePhase.phase));
            }

            if (statusText && activePhase) {
                statusText.textContent = activePhase.phase === -1
                    ? 'Current focus: Prerequisites'
                    : `Current focus: Phase ${activePhase.phase} - ${activePhase.shortTitle || activePhase.title}`;
            }

            if (statusDetail) {
                statusDetail.textContent = latestCompletedPhase
                    ? `Latest completed: ${latestCompletedPhase.title}`
                    : 'Latest completed: Not started yet';
            }

            if (jumpBtn && activePhase) {
                jumpBtn.setAttribute('href', `#phase-${activePhase.phase}`);
                jumpBtn.setAttribute('aria-label', `Jump to ${activePhase.title}`);
                jumpBtn.textContent = '↓ Jump to current section';
            }
        },

        updateWorkflowStepper() {
            const workflowPhases = this.getWorkflowPhases();
            const activePhase = this.getActivePhase(workflowPhases);
            const latestCompletedPhase = this.getLatestCompletedPhase(workflowPhases);

            if (!activePhase) {
                return;
            }

            document.querySelectorAll('.stepper-step').forEach((step) => {
                const stepPhase = parseInt(step.getAttribute('data-phase'));
                step.classList.remove('active', 'completed');

                if (stepPhase < activePhase.phase) {
                    step.classList.add('completed');
                } else if (stepPhase === activePhase.phase) {
                    step.classList.add('active');
                }
            });

            this.updateWorkflowHeader(activePhase, latestCompletedPhase);
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
        updateMigrationProgress(moduleCard, migrationId, retryCount = 0) {
            const maxRetries = 3;

            fetch(`${Config.migrationProgressUrl}?migrationId=${migrationId}`, {
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
                title: 'Confirm File Organization & Cleanup',
                message: '<strong>IMPORTANT:</strong> This will reorganize and clean up assets that are already in DigitalOcean Spaces. Ensure you have:<br/><br/>• Completed Filesystem Switch (Phase 2)<br/>• Reviewed the latest pre-flight checks<br/>• Created a database backup<br/><br/>This process may run for a long time, supports checkpoint resume, and writes detailed change logs.'
            }
        },

        resolveModuleCard(command, triggerButton = null) {
            if (triggerButton) {
                const directCard = triggerButton.closest('.module-card');
                if (directCard) {
                    return directCard;
                }
            }

            return document.querySelector(`.module-card[data-command="${command}"]`);
        },

        runCommand(command, args = {}, triggerButton = null) {
            const moduleCard = this.resolveModuleCard(command, triggerButton);

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
                    this.runCommand(command, args, triggerButton);
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

            this.runCommandSSE(moduleCard, command, args);
        },

        runCommandSSE(moduleCard, command, args = {}) {
            const params = new URLSearchParams({
                command: command,
                dryRun: args.dryRun ? '1' : '0',
                skipBackup: args.skipBackup ? '1' : '0',
                skipInlineDetection: args.skipInlineDetection ? '1' : '0',
                resume: args.resume ? '1' : '0'
            });

            if (args.checkpointId) {
                params.set('checkpointId', args.checkpointId);
            }

            const url = `${Config.streamMigrationUrl}?${params.toString()}`;

            UIManager.showModuleOutput(moduleCard, 'Connecting to live output...\n');

            const eventSource = new EventSource(url);
            let migrationId = null;
            let detachedMode = false;

            eventSource.onopen = () => {
                UIManager.appendModuleOutput(moduleCard, 'Connected to stream. Starting...\n\n');
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
                    if (eventData.message) {
                        UIManager.appendModuleOutput(moduleCard, eventData.message + '\n');
                    }
                    break;

                case 'running':
                    if (eventData.message) {
                        UIManager.appendModuleOutput(moduleCard, eventData.message + '\n');
                    }
                    if (eventData.pid && Config.isDevMode) {
                        UIManager.appendModuleOutput(moduleCard, `Process ID: ${eventData.pid}\n`);
                    }
                    break;

                case 'detached':
                    // Process is running in background, switch to polling mode
                    if (eventData.message) {
                        UIManager.appendModuleOutput(moduleCard, eventData.message + '\n');
                    }
                    if (eventData.pollEndpoint) {
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
                        } else if (migration.status === 'paused') {
                            UIManager.appendModuleOutput(moduleCard, '\n⏸ Migration interrupted. Use Resume Migration to continue from the latest checkpoint.\n');
                            UIManager.updateModuleProgress(
                                moduleCard,
                                migration.progressPercent || 0,
                                'Interrupted - ready to resume'
                            );
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
                'Are you sure you want to stop this command? A cancel signal will be sent and the process will stop at the next safe interruption point.',
                async () => {
                    try {
                        const result = await APIClient.cancelRunningCommand(command, moduleCard._migrationId || null);

                        if (!result.success) {
                            throw new Error(result.error || 'Unknown cancellation error');
                        }

                        if (moduleCard._eventSource) {
                            moduleCard._eventSource.close();
                        }

                        if (moduleCard._pollInterval) {
                            clearInterval(moduleCard._pollInterval);
                            moduleCard._pollInterval = null;
                        }

                        StateManager.removeRunning(command);
                        UIManager.setModuleRunning(moduleCard, false);
                        UIManager.appendModuleOutput(
                            moduleCard,
                            `\n⚠ ${result.message || 'Cancel signal sent. The task will stop as soon as it reaches a safe checkpoint.'}\n`
                        );
                        Craft.cp.displayNotice(result.message || 'Cancel signal sent');
                    } catch (error) {
                        Craft.cp.displayError('Failed to cancel command: ' + error.message);
                    }
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

            const pauseBtn = document.getElementById('monitor-pause-btn');
            if (pauseBtn) {
                pauseBtn.addEventListener('click', () => this.toggleRefresh());
            }
        },

        open() {
            const modal = document.getElementById('live-monitor-modal');
            if (modal) {
                UIManager.openModal(modal);
                this.isOpen = true;
                this.refreshData();
                this.startAutoRefresh();
            }
        },

        close() {
            const modal = document.getElementById('live-monitor-modal');
            if (modal) {
                UIManager.closeModal(modal);
                this.isOpen = false;
                this.stopAutoRefresh();
            }
        },

        toggleRefresh() {
            if (this.refreshInterval) {
                this.stopAutoRefresh();
            } else {
                this.startAutoRefresh();
            }
        },

        startAutoRefresh() {
            this.stopAutoRefresh();
            const interval = 3000;
            const pauseText = document.getElementById('monitor-pause-text');
            if (pauseText) {
                pauseText.textContent = 'Pause Refresh';
            }

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

            const pauseText = document.getElementById('monitor-pause-text');
            if (pauseText) {
                pauseText.textContent = 'Resume Refresh';
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
            const loadingEl = document.getElementById('monitor-loading');
            const noMigrationEl = document.getElementById('monitor-no-migration');
            const activeEl = document.getElementById('monitor-active');
            const statusBadgeEl = document.getElementById('monitor-status-badge');
            const migrationIdEl = document.getElementById('monitor-migration-id');
            const phaseEl = document.getElementById('monitor-phase');
            const statusEl = document.getElementById('monitor-status');
            const processEl = document.getElementById('monitor-process');
            const progressEl = document.getElementById('monitor-progress-fill');
            const progressTextEl = document.getElementById('monitor-progress-text');
            const progressPercentEl = document.getElementById('monitor-progress-percent');
            const statsSectionEl = document.getElementById('monitor-stats-section');
            const statsEl = document.getElementById('monitor-stats');
            const logTasksContainer = document.getElementById('monitor-log-tasks');
            const errorSectionEl = document.getElementById('monitor-error-section');
            const errorMessageEl = document.getElementById('monitor-error-message');

            if (loadingEl) {
                loadingEl.style.display = 'none';
            }

            if (!data.hasMigration || !data.migration) {
                if (noMigrationEl) noMigrationEl.style.display = 'block';
                if (activeEl) activeEl.style.display = 'none';
                if (statusBadgeEl) {
                    statusBadgeEl.style.display = 'none';
                    statusBadgeEl.className = 'badge';
                    statusBadgeEl.textContent = '';
                }
                return;
            }

            if (noMigrationEl) noMigrationEl.style.display = 'none';
            if (activeEl) activeEl.style.display = 'block';

            const migration = data.migration;

            if (migrationIdEl) {
                migrationIdEl.textContent = migration.id || '-';
            }

            if (phaseEl) {
                phaseEl.textContent = migration.phase || '-';
            }

            if (statusEl) {
                statusEl.textContent = migration.status || 'Unknown';
            }

            if (statusBadgeEl) {
                const statusClass = migration.status || 'unknown';
                statusBadgeEl.style.display = 'inline-flex';
                statusBadgeEl.className = `badge ${statusClass}`;
                statusBadgeEl.textContent = (migration.status || 'unknown').toUpperCase();
            }

            if (processEl) {
                processEl.textContent = migration.pid
                    ? `${migration.isProcessRunning ? 'Running' : 'Stopped'} (PID: ${migration.pid})`
                    : (migration.isProcessRunning ? 'Running' : 'Background/Unknown');
            }

            if (progressEl) {
                progressEl.style.width = `${migration.progressPercent || 0}%`;
            }

            if (progressTextEl) {
                const processedCount = migration.processedCount || 0;
                const totalCount = migration.totalCount || 0;
                progressTextEl.textContent = `${processedCount} / ${totalCount} items processed`;
            }

            if (progressPercentEl) {
                progressPercentEl.textContent = `${migration.progressPercent || 0}%`;
            }

            if (statsSectionEl) {
                statsSectionEl.style.display = migration.stats && Object.keys(migration.stats).length > 0 ? 'block' : 'none';
            }

            if (statsEl) {
                statsEl.innerHTML = '';
                Object.entries(migration.stats || {}).forEach(([key, value]) => {
                    const stat = document.createElement('div');
                    stat.className = 'monitor-item';
                    stat.innerHTML = `<span class="monitor-label">${key}:</span><span class="monitor-value">${value}</span>`;
                    statsEl.appendChild(stat);
                });
            }

            if (logTasksContainer) {
                this.updateLogTasks(logTasksContainer, data.logTasks || []);
            }

            if (errorSectionEl) {
                errorSectionEl.style.display = migration.errorMessage ? 'block' : 'none';
            }

            if (errorMessageEl && migration.errorMessage) {
                errorMessageEl.textContent = migration.errorMessage;
            }
        },

        updateLogTasks(container, tasks) {
            if (!tasks || tasks.length === 0) {
                if (!container.querySelector('.info-box')) {
                    container.innerHTML = '';
                    const empty = document.createElement('div');
                    empty.className = 'info-box';
                    empty.textContent = 'Logs will appear here as soon as the migration writes output.';
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
                        <div class="monitor-log-task__header">
                            <span class="monitor-log-task__command">${task.command || 'Unknown Command'}</span>
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
            Craft.cp.displayNotice('Validating DigitalOcean configuration...');

            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Config.csrfToken);

            try {
                const response = await fetch(Config.testConnectionUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    Craft.cp.displayNotice(data.message || 'DigitalOcean configuration looks valid');
                } else {
                    const errorMessage = Array.isArray(data.errors) && data.errors.length > 0
                        ? data.errors.join(' ')
                        : (data.error || 'Unknown error');
                    Craft.cp.displayError('DigitalOcean configuration check failed: ' + errorMessage);
                }
            } catch (error) {
                Craft.cp.displayError('DigitalOcean configuration check failed: ' + error.message);
            }
        },

        showCheckpoints() {
            const modal = document.getElementById('checkpoint-modal');
            if (modal) {
                UIManager.openModal(modal);

                fetch(Config.checkpointUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    const content = document.getElementById('checkpoint-list');
                    if (content) {
                        if (data.checkpoints && data.checkpoints.length > 0) {
                            content.innerHTML = data.checkpoints.map((checkpoint) => {
                                const source = checkpoint.source === 'quick_state' ? 'Quick state' : 'Checkpoint';
                                const resumeButton = checkpoint.checkpointId
                                    ? `<button type="button" class="btn small resume-checkpoint-btn" data-checkpoint-id="${checkpoint.checkpointId}">Resume This Checkpoint</button>`
                                    : '';

                                return `
                                    <div class="checkpoint-entry" style="padding: 12px 0; border-bottom: 1px solid #e5e7eb;">
                                        <div><strong>${source}</strong></div>
                                        <div>Migration ID: <code>${checkpoint.migrationId || checkpoint.migration_id || 'unknown'}</code></div>
                                        <div>Phase: ${checkpoint.phase || 'unknown'}</div>
                                        <div>Processed: ${checkpoint.processedCount ?? checkpoint.processed ?? 0}</div>
                                        <div>Updated: ${checkpoint.timestamp || '-'}</div>
                                        <div style="margin-top: 8px;">${resumeButton}</div>
                                    </div>
                                `;
                            }).join('');

                            content.querySelectorAll('.resume-checkpoint-btn').forEach((button) => {
                                button.addEventListener('click', () => {
                                    UIManager.closeModal(modal);
                                    CommandExecutor.runCommand(
                                        'image-migration/migrate',
                                        {
                                            resume: true,
                                            checkpointId: button.getAttribute('data-checkpoint-id')
                                        }
                                    );
                                });
                            });
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
            const modal = document.getElementById('rollback-modal');
            if (!modal) {
                return;
            }

            this.updateRollbackCommandPreview();
            UIManager.openModal(modal);
        },

        updateRollbackCommandPreview() {
            const phaseSelect = document.getElementById('rollback-phase');
            const preview = document.getElementById('rollback-command-preview');
            if (!preview) {
                return;
            }

            const selectedPhase = phaseSelect ? phaseSelect.value : '';
            const commandParts = [Config.rollbackCommandBase, '--dryRun=1'];

            if (selectedPhase !== '') {
                commandParts.push(`--phases=${selectedPhase}`);
                commandParts.push('--mode=from');
            }

            preview.textContent = commandParts.join(' ');
        },

        copyRollbackCommand() {
            const preview = document.getElementById('rollback-command-preview');
            if (!preview) {
                return;
            }

            navigator.clipboard.writeText(preview.textContent).then(() => {
                Craft.cp.displayNotice('Rollback command copied to clipboard');
            }).catch((error) => {
                console.error('Failed to copy rollback command:', error);
                Craft.cp.displayError('Failed to copy rollback command');
            });
        },

        showChangelog() {
            const modal = document.getElementById('changelog-modal');
            if (modal) {
                UIManager.openModal(modal);

                fetch(Config.changelogUrl, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    const summary = document.getElementById('changelog-summary');
                    const list = document.getElementById('changelog-list');

                    if (!list) {
                        return;
                    }

                    if (summary) {
                        if (Array.isArray(data.changelogs) && data.changelogs.length > 0) {
                            summary.style.display = 'block';
                            summary.innerHTML = `<p><strong>${data.changelogs.length}</strong> change log file(s) found in <code>${data.directory || '-'}</code>.</p>`;
                        } else {
                            summary.style.display = 'none';
                            summary.innerHTML = '';
                        }
                    }

                    if (Array.isArray(data.changelogs) && data.changelogs.length > 0) {
                        list.innerHTML = data.changelogs.map((entry) => {
                            const summaryItems = entry.summary && typeof entry.summary === 'object'
                                ? Object.entries(entry.summary).map(([key, value]) => `<li><strong>${key}:</strong> ${value}</li>`).join('')
                                : '';

                            return `
                                <article class="changelog-entry">
                                    <div class="changelog-entry__header">
                                        <div>
                                            <h4>${entry.filename || 'Unknown file'}</h4>
                                            <div class="changelog-entry__meta">Operation: ${entry.operation || 'unknown'}</div>
                                        </div>
                                        <div class="changelog-entry__meta">${entry.timestamp || '-'}</div>
                                    </div>
                                    ${summaryItems ? `<ul class="changelog-entry__summary">${summaryItems}</ul>` : '<p class="changelog-entry__empty">No summary data available.</p>'}
                                </article>
                            `;
                        }).join('');
                    } else {
                        list.innerHTML = '<p>No change log entries found.</p>';
                    }
                })
                .catch(error => {
                    console.error('Failed to load changelog:', error);
                    Craft.cp.displayError('Failed to load change logs');
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
                        resume: supportsResume && resumeRequested,
                        checkpointId: this.getAttribute('data-checkpoint-id') || null
                    }, this);
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

            const rollbackPhaseSelect = document.getElementById('rollback-phase');
            if (rollbackPhaseSelect) {
                rollbackPhaseSelect.addEventListener('change', () => UtilityActions.updateRollbackCommandPreview());
            }

            const confirmRollbackBtn = document.getElementById('confirm-rollback-btn');
            if (confirmRollbackBtn) {
                confirmRollbackBtn.addEventListener('click', () => UtilityActions.copyRollbackCommand());
            }

            const viewChangelogBtn = document.getElementById('view-changelog-btn');
            if (viewChangelogBtn) {
                viewChangelogBtn.addEventListener('click', () => UtilityActions.showChangelog());
            }

            document.querySelectorAll('.modal-close').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = btn.closest('.modal');
                    if (modal) {
                        if (modal.id === 'live-monitor-modal') {
                            LiveMonitor.close();
                        } else {
                            UIManager.closeModal(modal);
                        }
                    }
                });
            });

            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        if (modal.id === 'live-monitor-modal') {
                            LiveMonitor.close();
                        } else {
                            UIManager.closeModal(modal);
                        }
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
