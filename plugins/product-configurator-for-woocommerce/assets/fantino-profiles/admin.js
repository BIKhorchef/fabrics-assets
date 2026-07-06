/* Fantino Configurator Profiles — admin meta box */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var app = document.querySelector('.fantino-pc-app');
        if (!app) return;

        app.addEventListener('click', function (e) {
            // Tab switching
            var tab = e.target.closest && e.target.closest('[data-fantino-tab]');
            if (tab && app.contains(tab)) {
                e.preventDefault();
                activateTab(tab.getAttribute('data-fantino-tab'));
                return;
            }

            // Add profile
            var addBtn = e.target.closest && e.target.closest('[data-fantino-action="add-profile"]');
            if (addBtn) {
                e.preventDefault();
                addProfile();
                return;
            }

            // Delete profile
            var delBtn = e.target.closest && e.target.closest('[data-fantino-action="delete-profile"]');
            if (delBtn) {
                e.preventDefault();
                var panel = delBtn.closest('.fantino-pc-panel');
                if (!panel) return;
                var slug = panel.getAttribute('data-fantino-panel');
                if (!window.confirm('Delete profile "' + slug + '"?\nThis is staged — it will be saved when you click Update.')) return;
                deleteProfile(slug, panel);
                return;
            }

            // Copy frontend button HTML
            var copyBtn = e.target.closest && e.target.closest('[data-fantino-action="copy-button-html"]');
            if (copyBtn) {
                e.preventDefault();
                copyButtonHtml(copyBtn);
                return;
            }

            // Copy Elementor CSS classes string
            var copyClsBtn = e.target.closest && e.target.closest('[data-fantino-action="copy-elementor-classes"]');
            if (copyClsBtn) {
                e.preventDefault();
                copyText(copyClsBtn, copyClsBtn.getAttribute('data-fantino-classes') || '');
                return;
            }
        });

        // Live update of the "Frontend Button HTML" snippet whenever the
        // profile label / button label is edited.
        app.addEventListener('input', function (e) {
            if (!e.target || !e.target.matches) return;
            if (!e.target.matches('input[name$="[label]"], input[name$="[button_label]"]')) return;
            var panel = e.target.closest('.fantino-pc-panel');
            if (panel) updateButtonHtml(panel);
        });

        function activateTab(slug) {
            var tabs = app.querySelectorAll('.fantino-pc-tab');
            tabs.forEach(function (t) {
                t.classList.toggle('is-active', t.getAttribute('data-fantino-tab') === slug);
            });
            var panels = app.querySelectorAll('.fantino-pc-panel:not(.is-template)');
            panels.forEach(function (p) {
                p.classList.toggle('is-active', p.getAttribute('data-fantino-panel') === slug);
            });
        }

        function addProfile() {
            var label = window.prompt('New profile label (e.g. Premium):', '');
            if (!label) return;
            label = String(label).trim();
            if (!label) return;

            var slug = window.prompt('Profile slug (lowercase letters / numbers / dashes):', slugify(label));
            if (slug === null) return;
            slug = slugify(slug);
            if (!slug) {
                window.alert('Invalid slug.');
                return;
            }

            if (app.querySelector('[data-fantino-tab="' + cssEsc(slug) + '"]')) {
                window.alert('A profile with that slug already exists.');
                return;
            }

            var template = document.getElementById('fantino-pc-template');
            if (!template) return;

            // Clone the template HTML and rewrite the slug placeholder.
            var html = template.innerHTML.replace(/__SLUG__/g, slug);

            // Add the tab.
            var tabsContainer = app.querySelector('.fantino-pc-tabs');
            var tabBtn = document.createElement('button');
            tabBtn.type = 'button';
            tabBtn.className = 'fantino-pc-tab';
            tabBtn.setAttribute('role', 'tab');
            tabBtn.setAttribute('data-fantino-tab', slug);
            tabBtn.textContent = label;
            tabsContainer.appendChild(tabBtn);

            // Add the panel.
            var holder = document.createElement('div');
            holder.innerHTML = html.trim();
            var panel = holder.firstElementChild;
            if (!panel) return;
            panel.classList.remove('is-template');

            // Pre-fill the label field with the chosen label.
            var labelInput = panel.querySelector('input[name$="[label]"]');
            if (labelInput) labelInput.value = label;

            // Insert after the last existing panel (before the <template>).
            var lastPanel = lastNonTemplatePanel();
            if (lastPanel && lastPanel.parentNode) {
                lastPanel.parentNode.insertBefore(panel, lastPanel.nextSibling);
            } else {
                // No panels yet — insert after the tabs container.
                tabsContainer.parentNode.insertBefore(panel, tabsContainer.nextSibling);
            }

            // Hide empty state if present.
            var empty = app.querySelector('.fantino-pc-empty');
            if (empty) empty.style.display = 'none';

            // Seed the Frontend Button HTML textarea with the new label.
            updateButtonHtml(panel);

            activateTab(slug);
        }

        function updateButtonHtml(panel) {
            var ta = panel.querySelector('[data-fantino-button-html]');
            if (!ta) return;
            var slug = panel.getAttribute('data-fantino-panel') || '';
            var pid  = ta.getAttribute('data-fantino-product-id') || '';
            var labelInput  = panel.querySelector('input[name$="[label]"]');
            var buttonInput = panel.querySelector('input[name$="[button_label]"]');
            var label = (buttonInput && buttonInput.value.trim())
                || (labelInput && labelInput.value.trim())
                || slug;
            ta.value = '<a href="#" class="fantino-pc-profile-trigger"'
                + ' data-pc-profile="' + escapeAttr(slug) + '"'
                + ' data-product_id="' + escapeAttr(pid) + '">'
                + escapeHtml(label)
                + '</a>';
        }

        function copyButtonHtml(copyBtn) {
            var panel = copyBtn.closest('.fantino-pc-panel');
            if (!panel) return;
            var ta = panel.querySelector('[data-fantino-button-html]');
            if (!ta) return;
            ta.focus();
            ta.select();
            var status = copyBtn.parentNode && copyBtn.parentNode.querySelector('.fantino-pc-copy-status');
            copyText(copyBtn, ta.value, status);
        }

        function copyText(triggerEl, text, statusEl) {
            var status = statusEl || (triggerEl.parentNode && triggerEl.parentNode.querySelector('.fantino-pc-copy-status'));
            var done = function (ok) {
                if (!status) return;
                status.textContent = ok ? 'Copied!' : 'Press Ctrl+C to copy';
                setTimeout(function () { status.textContent = ''; }, 2000);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () { done(true); }, function () {
                    try { done(document.execCommand('copy')); } catch (e) { done(false); }
                });
                return;
            }
            try { done(document.execCommand('copy')); } catch (e) { done(false); }
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function escapeAttr(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function deleteProfile(slug, panel) {
            var tab = app.querySelector('[data-fantino-tab="' + cssEsc(slug) + '"]');
            if (tab) tab.remove();
            panel.remove();
            var firstTab = app.querySelector('.fantino-pc-tab');
            if (firstTab) {
                activateTab(firstTab.getAttribute('data-fantino-tab'));
            } else {
                var empty = app.querySelector('.fantino-pc-empty');
                if (empty) empty.style.display = '';
            }
        }

        function lastNonTemplatePanel() {
            var panels = app.querySelectorAll('.fantino-pc-panel:not(.is-template)');
            return panels.length ? panels[panels.length - 1] : null;
        }

        function slugify(s) {
            return String(s == null ? '' : s)
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        // Minimal CSS.escape polyfill for safe attribute selectors.
        function cssEsc(value) {
            if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
            return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
        }
    });
})();
