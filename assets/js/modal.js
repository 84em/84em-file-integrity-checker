/**
 * File Integrity Checker Modal System
 * 
 * Provides beautiful modal dialogs to replace native browser alerts and confirms
 */
(function() {
    'use strict';

    // Create modal container if it doesn't exist
    let modalContainer = null;
    
    /**
     * Initialize modal container
     */
    function initModalContainer() {
        if (!modalContainer) {
            modalContainer = document.createElement('div');
            modalContainer.id = 'fic-modal-container';
            document.body.appendChild(modalContainer);
        }
    }

    /**
     * Create modal HTML structure
     */
    function createModal(options) {
        const defaults = {
            title: '',
            message: '',
            type: 'info', // info, warning, error, success, confirm
            confirmText: 'OK',
            cancelText: 'Cancel',
            showCancel: false,
            icon: null,
            onConfirm: null,
            onCancel: null
        };

        const settings = Object.assign({}, defaults, options);

        // Determine icon based on type
        if (!settings.icon) {
            switch (settings.type) {
                case 'warning':
                    settings.icon = 'dashicons-warning';
                    break;
                case 'error':
                    settings.icon = 'dashicons-dismiss';
                    break;
                case 'success':
                    settings.icon = 'dashicons-yes-alt';
                    break;
                case 'confirm':
                    settings.icon = 'dashicons-info';
                    break;
                default:
                    settings.icon = 'dashicons-info-outline';
            }
        }

        const modalHtml = `
            <div class="fic-modal-overlay">
                <div class="fic-modal fic-modal-${settings.type}">
                    <div class="fic-modal-header">
                        ${settings.title ? `<h3 class="fic-modal-title">${settings.title}</h3>` : ''}
                        <button class="fic-modal-close" aria-label="Close">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="fic-modal-body">
                        <div class="fic-modal-content">
                            ${settings.icon ? `<span class="fic-modal-icon dashicons ${settings.icon}"></span>` : ''}
                            <div class="fic-modal-message">${settings.message}</div>
                        </div>
                    </div>
                    <div class="fic-modal-footer">
                        ${settings.showCancel ? `<button class="button button-secondary fic-modal-cancel">${settings.cancelText}</button>` : ''}
                        <button class="button button-primary fic-modal-confirm">${settings.confirmText}</button>
                    </div>
                </div>
            </div>
        `;

        const modalElement = document.createElement('div');
        modalElement.innerHTML = modalHtml;
        return { element: modalElement.firstElementChild, settings };
    }

    /**
     * Show modal
     */
    function showModal(modal, settings) {
        initModalContainer();
        modalContainer.appendChild(modal);

        // Add event listeners
        const overlay = modal;
        const closeBtn = modal.querySelector('.fic-modal-close');
        const confirmBtn = modal.querySelector('.fic-modal-confirm');
        const cancelBtn = modal.querySelector('.fic-modal-cancel');

        function closeModal() {
            modal.classList.add('fic-modal-closing');
            setTimeout(() => {
                if (modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }
            }, 300);
        }

        function handleConfirm() {
            if (settings.onConfirm) {
                settings.onConfirm();
            }
            closeModal();
        }

        function handleCancel() {
            if (settings.onCancel) {
                settings.onCancel();
            }
            closeModal();
        }

        // Close on overlay click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                if (settings.showCancel) {
                    handleCancel();
                } else {
                    handleConfirm();
                }
            }
        });

        // Close button
        closeBtn.addEventListener('click', function() {
            if (settings.showCancel) {
                handleCancel();
            } else {
                handleConfirm();
            }
        });

        // Confirm button
        confirmBtn.addEventListener('click', handleConfirm);

        // Cancel button
        if (cancelBtn) {
            cancelBtn.addEventListener('click', handleCancel);
        }

        // ESC key to close
        function handleEsc(e) {
            if (e.key === 'Escape') {
                if (settings.showCancel) {
                    handleCancel();
                } else {
                    handleConfirm();
                }
                document.removeEventListener('keydown', handleEsc);
            }
        }
        document.addEventListener('keydown', handleEsc);

        // Animate in
        setTimeout(() => {
            modal.classList.add('fic-modal-show');
        }, 10);

        // Focus on confirm button
        setTimeout(() => {
            confirmBtn.focus();
        }, 100);
    }

    /**
     * Public API
     */
    window.FICModal = {
        /**
         * Show alert modal
         */
        alert: function(message, title = '', type = 'info') {
            return new Promise((resolve) => {
                const { element, settings } = createModal({
                    title: title || 'Notice',
                    message: message,
                    type: type,
                    showCancel: false,
                    onConfirm: resolve
                });
                showModal(element, settings);
            });
        },

        /**
         * Show confirm modal
         */
        confirm: function(message, title = '', confirmText = 'Yes', cancelText = 'Cancel') {
            return new Promise((resolve, reject) => {
                const { element, settings } = createModal({
                    title: title || 'Confirm',
                    message: message,
                    type: 'confirm',
                    showCancel: true,
                    confirmText: confirmText,
                    cancelText: cancelText,
                    onConfirm: () => resolve(true),
                    onCancel: () => resolve(false)
                });
                showModal(element, settings);
            });
        },

        /**
         * Show success modal
         */
        success: function(message, title = '') {
            return this.alert(message, title || 'Success', 'success');
        },

        /**
         * Show error modal
         */
        error: function(message, title = '') {
            return this.alert(message, title || 'Error', 'error');
        },

        /**
         * Show warning modal
         */
        warning: function(message, title = '') {
            return this.alert(message, title || 'Warning', 'warning');
        }
    };
})();