/**
 * SMM Panel - Vanilla JS Application Core (ES6+)
 */

// Global SweetAlert2 notification helper
window.notify = {
    success: (message, title = 'Success') => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'success',
                title: title,
                text: message,
                timer: 2500,
                showConfirmButton: false
            });
        } else {
            alert(message);
        }
    },
    error: (message, title = 'Error') => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: title,
                text: message
            });
        } else {
            alert(message);
        }
    },
    confirm: async (message, title = 'Are you sure?') => {
        if (typeof Swal !== 'undefined') {
            const res = await Swal.fire({
                title: title,
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, proceed'
            });
            return res.isConfirmed;
        }
        return window.confirm(message);
    }
};

// Global Premium Theme Manager (Zero-reload, persistent, system-aware)
window.ThemeManager = {
    get: function() {
        return localStorage.getItem('smm_theme') || 'system';
    },
    set: function(theme) {
        if (!['light', 'dark', 'system'].includes(theme)) return;
        localStorage.setItem('smm_theme', theme);
        this.apply();
        this.syncUI();
        window.dispatchEvent(new CustomEvent('themechanged', {
            detail: { theme: theme, isDark: document.documentElement.classList.contains('dark') }
        }));
    },
    apply: function() {
        var pref = this.get();
        var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var isDark = pref === 'dark' || (pref === 'system' && prefersDark);
        
        if (isDark) {
            document.documentElement.classList.add('dark');
            document.documentElement.style.colorScheme = 'dark';
            var metaDark = document.querySelector('meta[name="theme-color"]');
            if (metaDark) metaDark.setAttribute('content', '#0b0f19');
        } else {
            document.documentElement.classList.remove('dark');
            document.documentElement.style.colorScheme = 'light';
            var metaLight = document.querySelector('meta[name="theme-color"]');
            if (metaLight) metaLight.setAttribute('content', '#2563eb');
        }
    },
    syncUI: function() {
        var current = this.get();
        document.querySelectorAll('[data-theme-option]').forEach(function(el) {
            var val = el.getAttribute('data-theme-option');
            var isSelected = (val === current);
            el.setAttribute('aria-checked', isSelected ? 'true' : 'false');
            
            // Visual active indicator classes
            var checkIndicator = el.querySelector('.theme-check-icon');
            if (checkIndicator) {
                checkIndicator.classList.toggle('hidden', !isSelected);
            }
            var activeRing = el.querySelector('.theme-active-ring');
            if (activeRing) {
                activeRing.classList.toggle('border-blue-600', isSelected);
                activeRing.classList.toggle('dark:border-blue-500', isSelected);
                activeRing.classList.toggle('border-transparent', !isSelected);
                activeRing.classList.toggle('ring-2', isSelected);
                activeRing.classList.toggle('ring-blue-500/30', isSelected);
            }
        });
    },
    init: function() {
        this.apply();
        var self = this;
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
                if (self.get() === 'system') {
                    self.apply();
                    self.syncUI();
                }
            });
        }
    }
};

// Initialize Theme Manager immediately
window.ThemeManager.init();

document.addEventListener('DOMContentLoaded', () => {
    window.ThemeManager.syncUI();
    // Dismiss flash alert notifications via delegated click
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[role="alert"] button');
        if (btn) {
            btn.closest('[role="alert"]')?.remove();
        }
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('[role="alert"]').forEach(alert => {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 400);
        });
    }, 5000);

    // Register Progressive Web App (PWA) Service Worker
    if ('serviceWorker' in navigator && window.location.protocol.startsWith('http')) {
        navigator.serviceWorker.register('/sw.js').catch(err => {
            console.warn('PWA service worker registration skipped:', err);
        });
    }

    // Capture PWA Install Prompt
    let deferredInstallPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredInstallPrompt = e;
        const installBtns = document.querySelectorAll('.pwa-install-trigger');
        installBtns.forEach(btn => btn.classList.remove('hidden'));
    });

    window.installPWA = async () => {
        if (!deferredInstallPrompt) {
            notify.success('To install this app, tap Share on iOS or menu on Android and select "Add to Home Screen".', 'Install App');
            return;
        }
        deferredInstallPrompt.prompt();
        const { outcome } = await deferredInstallPrompt.userChoice;
        if (outcome === 'accepted') {
            document.querySelectorAll('.pwa-install-trigger').forEach(b => b.classList.add('hidden'));
        }
        deferredInstallPrompt = null;
    };

    // Global Currency Switcher
    window.switchCurrency = async (currencyCode) => {
        try {
            const formData = new FormData();
            formData.append('currency', currencyCode);
            const res = await fetch('/api/currency.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                notify.error(data.error || 'Failed to switch currency');
            }
        } catch (err) {
            console.error('Currency switch error:', err);
        }
    };
});
