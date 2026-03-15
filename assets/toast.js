class Toast {
    constructor() {
        this.container = null;
        this.init();
    }

    init() {
        if (!document.getElementById('toast-container')) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);

            const style = document.createElement('style');
            style.textContent = `
                .toast-container {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    max-width: 400px;
                    pointer-events: none;
                }

                .toast {
                    background: var(--card);
                    border: 1px solid var(--border);
                    border-radius: 12px;
                    padding: 16px 20px;
                    display: flex;
                    align-items: flex-start;
                    gap: 12px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
                    animation: toastSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
                    pointer-events: auto;
                    position: relative;
                    overflow: hidden;
                }

                .toast.toast-success {
                    border-left: 4px solid #00e676;
                }

                .toast.toast-error {
                    border-left: 4px solid #ff5252;
                }

                .toast.toast-warning {
                    border-left: 4px solid #ffc107;
                }

                .toast.toast-info {
                    border-left: 4px solid var(--a1);
                }

                .toast-icon {
                    font-size: 20px;
                    flex-shrink: 0;
                    margin-top: 2px;
                }

                .toast-content {
                    flex: 1;
                    min-width: 0;
                }

                .toast-title {
                    font-weight: 700;
                    font-size: 14px;
                    color: #fff;
                    margin-bottom: 4px;
                }

                .toast-message {
                    font-size: 13px;
                    color: var(--muted);
                    line-height: 1.5;
                }

                .toast-close {
                    background: none;
                    border: none;
                    color: var(--muted);
                    cursor: pointer;
                    padding: 0;
                    font-size: 18px;
                    line-height: 1;
                    opacity: 0.6;
                    transition: opacity 0.2s;
                }

                .toast-close:hover {
                    opacity: 1;
                }

                .toast-progress {
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    height: 3px;
                    background: var(--a1);
                    animation: toastProgress linear;
                    transform-origin: left;
                }

                .toast.toast-removing {
                    animation: toastSlideOut 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
                }

                @keyframes toastSlideIn {
                    from {
                        transform: translateX(calc(100% + 20px));
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }

                @keyframes toastSlideOut {
                    from {
                        transform: translateX(0);
                        opacity: 1;
                    }
                    to {
                        transform: translateX(calc(100% + 20px));
                        opacity: 0;
                    }
                }

                @keyframes toastProgress {
                    from {
                        transform: scaleX(1);
                    }
                    to {
                        transform: scaleX(0);
                    }
                }

                @media (max-width: 768px) {
                    .toast-container {
                        left: 16px;
                        right: 16px;
                        top: 16px;
                        max-width: none;
                    }
                }
            `;
            document.head.appendChild(style);
        } else {
            this.container = document.getElementById('toast-container');
        }
    }

    show(options) {
        const {
            type = 'info',
            title = '',
            message = '',
            duration = 5000,
            closeable = true
        } = options;

        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        toast.innerHTML = `
            <div class="toast-icon">${icons[type]}</div>
            <div class="toast-content">
                ${title ? `<div class="toast-title">${title}</div>` : ''}
                <div class="toast-message">${message}</div>
            </div>
            ${closeable ? '<button class="toast-close" aria-label="Close">×</button>' : ''}
            ${duration > 0 ? `<div class="toast-progress" style="animation-duration: ${duration}ms;"></div>` : ''}
        `;

        this.container.appendChild(toast);

        if (closeable) {
            const closeBtn = toast.querySelector('.toast-close');
            closeBtn.addEventListener('click', () => this.remove(toast));
        }

        if (duration > 0) {
            setTimeout(() => this.remove(toast), duration);
        }

        return toast;
    }

    remove(toast) {
        toast.classList.add('toast-removing');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }

    success(message, title = 'Success') {
        return this.show({ type: 'success', title, message });
    }

    error(message, title = 'Error') {
        return this.show({ type: 'error', title, message, duration: 7000 });
    }

    warning(message, title = 'Warning') {
        return this.show({ type: 'warning', title, message, duration: 6000 });
    }

    info(message, title = '') {
        return this.show({ type: 'info', title, message });
    }
}

window.toast = new Toast();
