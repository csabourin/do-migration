/**
 * State Manager Module
 * Handles dashboard state persistence and synchronization with server
 */
(function(window) {
    'use strict';

    const StateManager = {
        state: {
            runningModules: new Set(),
            completedModules: new Set(),
            pollingIntervals: new Map(),
            lastFocusedElement: null,
            focusableElements: 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        },

        config: null,

        init: function(config) {
            this.config = config;
            this.loadStateFromServer();
        },

        /**
         * Load dashboard state from server
         */
        loadStateFromServer: function() {
            return this.checkStatus().then(data => {
                if (data && data.state) {
                    // Restore completed modules from server state
                    this.state.completedModules.clear();
                    if (data.state.completedModules && Array.isArray(data.state.completedModules)) {
                        data.state.completedModules.forEach(moduleId => {
                            this.state.completedModules.add(moduleId);
                        });
                    }
                }
                return data;
            });
        },

        /**
         * Check status from server
         */
        checkStatus: function() {
            return fetch(this.config.statusUrl, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    console.error('Status check failed:', data.error || 'Unknown error');
                    return null;
                }
                return data;
            })
            .catch(error => {
                console.error('Error checking status:', error);
                return null;
            });
        },

        /**
         * Persist state to server
         */
        persistState: function() {
            const formData = new FormData();
            formData.append(this.config.csrfTokenName || 'CRAFT_CSRF_TOKEN', this.config.csrfToken);
            formData.append('modules', JSON.stringify(Array.from(this.state.completedModules)));

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
                    console.error('Failed to persist state:', data.error || 'Unknown error');
                }
                return data;
            })
            .catch(error => {
                console.error('Error persisting state:', error);
                throw error;
            });
        },

        /**
         * Update module status on server
         */
        updateModuleStatus: function(moduleId, status, error = null) {
            const formData = new FormData();
            formData.append(this.config.csrfTokenName || 'CRAFT_CSRF_TOKEN', this.config.csrfToken);
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
                    console.error('Failed to update module status:', data.error || 'Unknown error');
                }
                return data;
            })
            .catch(error => {
                console.error('Error updating module status:', error);
                throw error;
            });
        },

        /**
         * Mark module as completed
         */
        markCompleted: function(moduleId) {
            this.state.completedModules.add(moduleId);
            return this.persistState();
        },

        /**
         * Check if module is completed
         */
        isCompleted: function(moduleId) {
            return this.state.completedModules.has(moduleId);
        },

        /**
         * Mark module as running
         */
        setRunning: function(command, isRunning) {
            if (isRunning) {
                this.state.runningModules.add(command);
            } else {
                this.state.runningModules.delete(command);
            }
        },

        /**
         * Check if module is running
         */
        isRunning: function(command) {
            return this.state.runningModules.has(command);
        },

        /**
         * Store polling interval for cleanup
         */
        setPollingInterval: function(command, interval) {
            this.state.pollingIntervals.set(command, interval);
        },

        /**
         * Clear polling interval
         */
        clearPollingInterval: function(command) {
            const interval = this.state.pollingIntervals.get(command);
            if (interval) {
                clearInterval(interval);
                this.state.pollingIntervals.delete(command);
            }
        }
    };

    // Export to window
    window.MigrationDashboard = window.MigrationDashboard || {};
    window.MigrationDashboard.StateManager = StateManager;

})(window);
