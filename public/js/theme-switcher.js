// Theme Switcher JavaScript

class ThemeSwitcher {
    constructor() {
        this.themeToggle = document.getElementById('themeToggle');
        this.body = document.body;
        this.currentTheme = localStorage.getItem('theme') || 'dark';

        this.init();
    }

    init() {
        // Set initial theme
        this.setTheme(this.currentTheme);

        // Add event listener
        this.themeToggle.addEventListener('click', () => {
            this.toggleTheme();
        });

        // Add keyboard shortcut (Ctrl/Cmd + T)
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 't') {
                e.preventDefault();
                this.toggleTheme();
            }
        });

        // Add smooth transitions
        this.addTransitionClass();
    }

    setTheme(theme) {
        this.currentTheme = theme;
        this.body.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);

        // Update toggle position
        this.updateTogglePosition(theme);

        // Dispatch custom event
        this.dispatchThemeChangeEvent(theme);
    }

    toggleTheme() {
        const newTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
        this.setTheme(newTheme);

        // Add animation class
        this.addToggleAnimation();
    }

    updateTogglePosition(theme) {
        const thumb = this.themeToggle.querySelector('.toggle-thumb');
        const lightIcon = this.themeToggle.querySelector('.light-icon');
        const darkIcon = this.themeToggle.querySelector('.dark-icon');

        if (theme === 'light') {
            thumb.style.right = '32px';
            lightIcon.style.opacity = '1';
            lightIcon.style.transform = 'scale(1)';
            darkIcon.style.opacity = '0';
            darkIcon.style.transform = 'scale(0)';
        } else {
            thumb.style.right = '2px';
            lightIcon.style.opacity = '0';
            lightIcon.style.transform = 'scale(0)';
            darkIcon.style.opacity = '1';
            darkIcon.style.transform = 'scale(1)';
        }
    }

    addToggleAnimation() {
        const thumb = this.themeToggle.querySelector('.toggle-thumb');
        thumb.style.transform = 'scale(1.1)';

        setTimeout(() => {
            thumb.style.transform = 'scale(1)';
        }, 150);
    }

    addTransitionClass() {
        // Add transition class to elements that should animate
        const elements = document.querySelectorAll('.video-card, .folder-card, .navbar, .footer, .upload-card');
        elements.forEach(el => {
            el.classList.add('theme-transition');
        });
    }

    dispatchThemeChangeEvent(theme) {
        const event = new CustomEvent('themeChange', {
            detail: { theme: theme }
        });
        document.dispatchEvent(event);
    }

    // Public method to get current theme
    getCurrentTheme() {
        return this.currentTheme;
    }

    // Public method to check if theme is dark
    isDark() {
        return this.currentTheme === 'dark';
    }

    // Public method to check if theme is light
    isLight() {
        return this.currentTheme === 'light';
    }
}

// Initialize theme switcher when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.themeSwitcher = new ThemeSwitcher();

    // Listen for theme change events
    document.addEventListener('themeChange', (e) => {
        console.log('Theme changed to:', e.detail.theme);

        // Update meta theme color
        updateMetaThemeColor(e.detail.theme);

        // Update any theme-dependent components
        updateThemeDependentComponents(e.detail.theme);
    });
});

// Update meta theme color for mobile browsers
function updateMetaThemeColor(theme) {
    let metaThemeColor = document.querySelector('meta[name="theme-color"]');

    if (!metaThemeColor) {
        metaThemeColor = document.createElement('meta');
        metaThemeColor.name = 'theme-color';
        document.head.appendChild(metaThemeColor);
    }

    metaThemeColor.content = theme === 'dark' ? '#141414' : '#ffffff';
}

// Update theme-dependent components
function updateThemeDependentComponents(theme) {
    // Update video player theme if exists
    const videos = document.querySelectorAll('video');
    videos.forEach(video => {
        if (theme === 'dark') {
            video.style.filter = 'brightness(1)';
        } else {
            video.style.filter = 'brightness(0.9)';
        }
    });

    // Update any charts or graphs if they exist
    if (window.charts) {
        window.charts.forEach(chart => {
            chart.update({
                options: {
                    theme: theme
                }
            });
        });
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ThemeSwitcher;
}
