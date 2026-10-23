/**
 * Přepínání barevného motivu (auto / světlý / tmavý).
 *
 * Načítá se synchronně v <head>, aby se motiv nastavil ještě před
 * vykreslením stránky. Volba se ukládá do localStorage.
 * Ostatní skripty mohou poslouchat událost „weather:themechange“.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'oubrix-theme';
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    /** Uložená volba uživatele: 'auto' | 'light' | 'dark'. */
    function stored() {
        try {
            var value = localStorage.getItem(STORAGE_KEY);
            return value === 'light' || value === 'dark' ? value : 'auto';
        } catch (e) {
            return 'auto';
        }
    }

    /** Skutečně použitý motiv po vyhodnocení volby „auto“. */
    function resolve(choice) {
        if (choice === 'auto') {
            return media && media.matches ? 'dark' : 'light';
        }
        return choice;
    }

    /** Nastaví motiv na <html> a zvýrazní aktivní tlačítko. */
    function apply(choice) {
        var theme = resolve(choice);
        document.documentElement.setAttribute('data-bs-theme', theme);
        var buttons = document.querySelectorAll('[data-theme-value]');
        for (var i = 0; i < buttons.length; i++) {
            var active = buttons[i].getAttribute('data-theme-value') === choice;
            buttons[i].classList.toggle('active', active);
            buttons[i].setAttribute('aria-pressed', active ? 'true' : 'false');
        }
        document.dispatchEvent(new CustomEvent('weather:themechange', { detail: { theme: theme } }));
    }

    apply(stored());

    document.addEventListener('DOMContentLoaded', function () {
        apply(stored());
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-theme-value]');
            if (!button) {
                return;
            }
            var choice = button.getAttribute('data-theme-value');
            try {
                localStorage.setItem(STORAGE_KEY, choice);
            } catch (e) { /* soukromý režim – volba platí jen do obnovení */ }
            apply(choice);
        });
    });

    if (media && media.addEventListener) {
        media.addEventListener('change', function () {
            if (stored() === 'auto') {
                apply('auto');
            }
        });
    }
})();
