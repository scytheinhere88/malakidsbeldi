class KeyboardShortcuts {
    constructor() {
        this.shortcuts = new Map();
        this.modalOpen = false;
        this.init();
    }

    init() {
        this.registerDefaultShortcuts();
        this.attachEventListeners();
        this.createHelpModal();
    }

    registerDefaultShortcuts() {
        this.register('?', () => this.toggleHelpModal(), 'Show keyboard shortcuts');
        this.register('/', () => this.focusSearch(), 'Focus search (if available)');
        this.register('Escape', () => this.handleEscape(), 'Close modals / Cancel');
        this.register('g d', () => this.navigate('/dashboard/'), 'Go to Dashboard');
        this.register('g c', () => this.navigate('/dashboard/csv_generator.php'), 'Go to CSV Generator');
        this.register('g a', () => this.navigate('/dashboard/autopilot.php'), 'Go to Autopilot');
        this.register('g z', () => this.navigate('/dashboard/zip_manager.php'), 'Go to ZIP Manager');
        this.register('g b', () => this.navigate('/dashboard/billing.php'), 'Go to Billing');
        this.register('g h', () => this.navigate('/'), 'Go to Home');

        this.register('Ctrl+k', (e) => {
            e.preventDefault();
            this.openCommandPalette();
        }, 'Open command palette', true);

        this.register('Alt+t', (e) => {
            e.preventDefault();
            if (window.darkMode) window.darkMode.toggle();
        }, 'Toggle dark mode', true);
    }

    register(keys, callback, description = '', preventDefault = false) {
        this.shortcuts.set(keys.toLowerCase(), { callback, description, preventDefault });
    }

    attachEventListeners() {
        let sequence = '';
        let sequenceTimer = null;

        document.addEventListener('keydown', (e) => {
            if (this.shouldIgnoreEvent(e)) return;

            const key = this.getKeyString(e);

            if (sequenceTimer) clearTimeout(sequenceTimer);

            sequence += key;

            const shortcut = this.shortcuts.get(sequence);
            if (shortcut) {
                if (shortcut.preventDefault) e.preventDefault();
                shortcut.callback(e);
                sequence = '';
            } else {
                sequenceTimer = setTimeout(() => {
                    sequence = '';
                }, 1000);
            }
        });
    }

    shouldIgnoreEvent(e) {
        const target = e.target;
        const isInput = target.tagName === 'INPUT' ||
                       target.tagName === 'TEXTAREA' ||
                       target.isContentEditable;

        if (isInput && e.key !== 'Escape') return true;

        return false;
    }

    getKeyString(e) {
        const parts = [];
        if (e.ctrlKey && e.key !== 'Control') parts.push('Ctrl');
        if (e.altKey && e.key !== 'Alt') parts.push('Alt');
        if (e.shiftKey && e.key !== 'Shift') parts.push('Shift');
        if (e.metaKey && e.key !== 'Meta') parts.push('Meta');

        const key = e.key.toLowerCase();
        if (key !== 'control' && key !== 'alt' && key !== 'shift' && key !== 'meta') {
            parts.push(key === ' ' ? 'space' : key);
        }

        return parts.join('+');
    }

    focusSearch() {
        const searchInput = document.querySelector('input[type="search"], input[name="search"], input[placeholder*="Search"]');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }

    handleEscape() {
        if (this.modalOpen) {
            this.toggleHelpModal();
            return;
        }

        const modals = document.querySelectorAll('.progress-modal, [role="dialog"]');
        if (modals.length > 0) {
            const lastModal = modals[modals.length - 1];
            const closeBtn = lastModal.querySelector('[data-close], .close, button[aria-label*="Close"]');
            if (closeBtn) closeBtn.click();
        }

        const activeElement = document.activeElement;
        if (activeElement && (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA')) {
            activeElement.blur();
        }
    }

    navigate(url) {
        if (window.location.hostname === new URL(url, window.location.origin).hostname) {
            window.location.href = url;
        }
    }

    openCommandPalette() {
        if (window.toast) {
            window.toast.info('Command palette coming soon!', '');
        }
    }

    createHelpModal() {
        const style = document.createElement('style');
        style.textContent = `
            .shortcuts-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.85);
                backdrop-filter: blur(8px);
                z-index: 10001;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
                animation: fadeIn 0.2s ease;
            }

            .shortcuts-modal.active {
                display: flex;
            }

            .shortcuts-content {
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 16px;
                padding: 32px;
                max-width: 600px;
                width: 100%;
                max-height: 80vh;
                overflow-y: auto;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
            }

            .shortcuts-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 24px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--border);
            }

            .shortcuts-title {
                font-family: 'Syne', sans-serif;
                font-size: 24px;
                font-weight: 900;
                color: #fff;
            }

            .shortcuts-close {
                background: none;
                border: none;
                color: var(--muted);
                cursor: pointer;
                font-size: 24px;
                padding: 0;
                line-height: 1;
                transition: color 0.2s;
            }

            .shortcuts-close:hover {
                color: #fff;
            }

            .shortcuts-section {
                margin-bottom: 24px;
            }

            .shortcuts-section:last-child {
                margin-bottom: 0;
            }

            .shortcuts-section-title {
                font-family: 'JetBrains Mono', monospace;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 1.5px;
                color: var(--muted);
                margin-bottom: 12px;
            }

            .shortcuts-list {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .shortcut-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 12px;
                background: rgba(255, 255, 255, 0.02);
                border: 1px solid var(--border);
                border-radius: 8px;
                transition: background 0.2s;
            }

            .shortcut-item:hover {
                background: rgba(255, 255, 255, 0.04);
            }

            .shortcut-desc {
                font-size: 13px;
                color: var(--text);
            }

            .shortcut-keys {
                display: flex;
                gap: 4px;
            }

            .shortcut-key {
                font-family: 'JetBrains Mono', monospace;
                font-size: 11px;
                padding: 4px 8px;
                background: rgba(240, 165, 0, 0.1);
                border: 1px solid rgba(240, 165, 0, 0.3);
                border-radius: 4px;
                color: var(--a1);
                font-weight: 600;
            }

            @media (max-width: 768px) {
                .shortcuts-content {
                    padding: 24px;
                }

                .shortcuts-title {
                    font-size: 20px;
                }
            }
        `;
        document.head.appendChild(style);

        const modal = document.createElement('div');
        modal.className = 'shortcuts-modal';
        modal.innerHTML = `
            <div class="shortcuts-content">
                <div class="shortcuts-header">
                    <div class="shortcuts-title">⌨️ Keyboard Shortcuts</div>
                    <button class="shortcuts-close" aria-label="Close">×</button>
                </div>
                <div class="shortcuts-sections"></div>
            </div>
        `;

        const closeBtn = modal.querySelector('.shortcuts-close');
        closeBtn.addEventListener('click', () => this.toggleHelpModal());

        modal.addEventListener('click', (e) => {
            if (e.target === modal) this.toggleHelpModal();
        });

        document.body.appendChild(modal);
        this.helpModal = modal;

        this.updateHelpModal();
    }

    updateHelpModal() {
        const sections = {
            'General': ['?', 'escape', 'alt+t'],
            'Navigation': ['g d', 'g c', 'g a', 'g z', 'g b', 'g h'],
            'Actions': ['ctrl+k', '/']
        };

        const container = this.helpModal.querySelector('.shortcuts-sections');
        container.innerHTML = '';

        Object.entries(sections).forEach(([title, keys]) => {
            const section = document.createElement('div');
            section.className = 'shortcuts-section';
            section.innerHTML = `
                <div class="shortcuts-section-title">${title}</div>
                <div class="shortcuts-list"></div>
            `;

            const list = section.querySelector('.shortcuts-list');

            keys.forEach(key => {
                const shortcut = this.shortcuts.get(key);
                if (!shortcut) return;

                const item = document.createElement('div');
                item.className = 'shortcut-item';

                const keyParts = key.split('+').map(k => k.trim());
                const keysHTML = keyParts.map(k => {
                    const displayKey = k === ' ' ? 'Space' : k.charAt(0).toUpperCase() + k.slice(1);
                    return `<span class="shortcut-key">${displayKey}</span>`;
                }).join('');

                item.innerHTML = `
                    <span class="shortcut-desc">${shortcut.description}</span>
                    <div class="shortcut-keys">${keysHTML}</div>
                `;

                list.appendChild(item);
            });

            container.appendChild(section);
        });
    }

    toggleHelpModal() {
        this.modalOpen = !this.modalOpen;
        this.helpModal.classList.toggle('active');
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.shortcuts = new KeyboardShortcuts();
    });
} else {
    window.shortcuts = new KeyboardShortcuts();
}
