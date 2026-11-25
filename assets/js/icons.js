/**
 * Turf Teams - Vector Iconography & Theme Engine
 * 100% Pure Monochrome (Black & White) System.
 * Zero external dependencies.
 */

(function () {
    'use strict';

    const svgs = {
        ball: '<circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/><circle cx="12" cy="12" r="3"/>',
        clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        whistle: '<path d="M11 5a6 6 0 1 0 6 6v-1a2 2 0 0 0-2-2h-4"/><path d="M15 8h4a2 2 0 0 1 2 2v1a6 6 0 1 1-12 0v-1a2 2 0 0 1 2-2h4z"/><circle cx="8" cy="14" r="2"/>',
        shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        trophy: '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        medal: '<path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/><circle cx="12" cy="17" r="5"/>',
        award: '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
        history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><polyline points="12 7 12 12 15 15"/>',
        user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        unlock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
        plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
        trash: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        edit: '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/>',
        refresh: '<path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/>',
        alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
        check: '<polyline points="20 6 9 17 4 12"/>',
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        settings: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>'
    };

    function generateIcon(name, opts = {}) {
        const body = svgs[name] || svgs.ball;
        const width = opts.width || 16;
        const height = opts.height || width;
        const cls = opts.class ? ` ${opts.class}` : '';
        const strokeWidth = opts.strokeWidth || 2;
        return `<svg class="icon icon-${name}${cls}" width="${width}" height="${height}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round">${body}</svg>`;
    }

    function teamBadge(teamName, size = 32) {
        const cleanName = (teamName || 'T').trim();
        const words = cleanName.split(/\s+/);
        let initials = '';
        if (words.length >= 2) {
            initials = (words[0][0] + words[1][0]).toUpperCase();
        } else {
            initials = cleanName.substring(0, 3).toUpperCase();
        }
        return `<span class="club-crest-pill" style="min-width:${size}px; height:${Math.max(22, size * 0.7)}px; padding:0 0.5rem; font-size:${Math.max(10, size * 0.32)}px;">${initials}</span>`;
    }

    const TurfIcons = {
        icon: generateIcon,
        teamBadge: teamBadge,
        rundaBadge: function (size = 32) { return teamBadge('Runda FC', size); },
        makoBadge: function (size = 32) { return teamBadge('Makongeni FC', size); }
    };

    // Expose direct properties so TurfIcons.ball returns an SVG string
    Object.keys(svgs).forEach(name => {
        TurfIcons[name] = generateIcon(name);
    });

    // Theme Management: Black & White Only
    const TurfTheme = {
        KEY: 'turf_theme_mode',
        get: function () {
            return localStorage.getItem(this.KEY) || 'dark';
        },
        set: function (theme) {
            const mode = (theme === 'light') ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', mode);
            try {
                localStorage.setItem(this.KEY, mode);
            } catch (e) {}
            this.updateToggleButtons(mode);
        },
        toggle: function () {
            const current = this.get();
            const next = (current === 'dark') ? 'light' : 'dark';
            this.set(next);
            return next;
        },
        updateToggleButtons: function (mode) {
            const btns = document.querySelectorAll('.theme-toggle-btn');
            btns.forEach(btn => {
                const isDark = (mode === 'dark');
                btn.setAttribute('aria-label', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
                btn.innerHTML = `<span class="theme-toggle-indicator" style="display:inline-flex;align-items:center;gap:0.4rem;">
                    ${generateIcon(isDark ? 'sun' : 'moon', { width: 14, height: 14 })}
                    <span style="font-size:0.75rem;font-weight:700;letter-spacing:0.04em;">${isDark ? 'Light Mode' : 'Dark Mode'}</span>
                </span>`;
            });
        },
        init: function () {
            const theme = this.get();
            this.set(theme);
        }
    };

    // Auto-init theme as early as possible
    TurfTheme.init();

    // Ensure buttons are populated once DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            TurfTheme.updateToggleButtons(TurfTheme.get());
        });
    } else {
        TurfTheme.updateToggleButtons(TurfTheme.get());
    }

    window.TurfIcons = TurfIcons;
    window.TurfTheme = TurfTheme;
})();
