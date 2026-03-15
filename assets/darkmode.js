class DarkModeToggle {
    constructor() {
        this.themes = {
            dark: {
                '--bg': '#080810',
                '--bg2': '#0d0d1a',
                '--card': '#0f0f20',
                '--card2': '#141428',
                '--border': '#1e1e3a',
                '--border2': '#2a2a48',
                '--text': '#c8c8e8',
                '--muted': '#454568',
                '--dim': '#181830'
            },
            light: {
                '--bg': '#f8f9fa',
                '--bg2': '#ffffff',
                '--card': '#ffffff',
                '--card2': '#f1f3f5',
                '--border': '#dee2e6',
                '--border2': '#adb5bd',
                '--text': '#212529',
                '--muted': '#6c757d',
                '--dim': '#e9ecef'
            }
        };

        this.currentTheme = this.getStoredTheme();
        this.init();
    }

    init() {
        this.applyTheme(this.currentTheme, false);
        this.createToggleButton();
    }

    getStoredTheme() {
        const stored = localStorage.getItem('theme');
        if (stored) return stored;

        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        return prefersDark ? 'dark' : 'light';
    }

    applyTheme(theme, animate = true) {
        const colors = this.themes[theme];
        const root = document.documentElement;

        if (animate) {
            document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';
            setTimeout(() => {
                document.body.style.transition = '';
            }, 300);
        }

        Object.entries(colors).forEach(([property, value]) => {
            root.style.setProperty(property, value);
        });

        this.currentTheme = theme;
        localStorage.setItem('theme', theme);

        document.body.classList.remove('theme-dark', 'theme-light');
        document.body.classList.add(`theme-${theme}`);

        this.updateToggleButton();
    }

    toggle() {
        const newTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
        this.applyTheme(newTheme, true);

        if (window.toast) {
            window.toast.info(
                `${newTheme === 'dark' ? 'Dark' : 'Light'} mode activated`,
                ''
            );
        }
    }

    createToggleButton() {
        const style = document.createElement('style');
        style.textContent = `
            .theme-toggle {
                position: fixed;
                bottom: 24px;
                right: 24px;
                width: 56px;
                height: 56px;
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                z-index: 9998;
                transition: all 0.3s ease;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            }

            .theme-toggle:hover {
                transform: scale(1.1) rotate(180deg);
                box-shadow: 0 6px 30px rgba(240, 165, 0, 0.3);
                border-color: var(--a1);
            }

            .theme-toggle-icon {
                font-size: 24px;
                transition: transform 0.3s ease;
            }

            .theme-toggle:active {
                transform: scale(0.95);
            }

            @media (max-width: 768px) {
                .theme-toggle {
                    bottom: 20px;
                    right: 20px;
                    width: 48px;
                    height: 48px;
                }

                .theme-toggle-icon {
                    font-size: 20px;
                }
            }
        `;
        document.head.appendChild(style);

        const button = document.createElement('button');
        button.className = 'theme-toggle';
        button.setAttribute('aria-label', 'Toggle dark mode');
        button.innerHTML = '<span class="theme-toggle-icon">🌙</span>';

        button.addEventListener('click', () => this.toggle());

        document.body.appendChild(button);
        this.toggleButton = button;

        this.updateToggleButton();
    }

    updateToggleButton() {
        if (!this.toggleButton) return;

        const icon = this.toggleButton.querySelector('.theme-toggle-icon');
        icon.textContent = this.currentTheme === 'dark' ? '☀️' : '🌙';
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.darkMode = new DarkModeToggle();
    });
} else {
    window.darkMode = new DarkModeToggle();
}
