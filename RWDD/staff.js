(function () {
    'use strict';

    function setupScrollRestore(key) {
        if (!key) {
            return function () {};
        }

        function storeScrollPosition() {
            sessionStorage.setItem(key, String(window.scrollY));
        }

        window.addEventListener('load', function () {
            var saved = sessionStorage.getItem(key);
            if (saved !== null) {
                window.scrollTo(0, parseInt(saved, 10));
                sessionStorage.removeItem(key);
            }
        });

        return storeScrollPosition;
    }

    function showPageMessage(type, text, containerSelector) {
        var selector = containerSelector || '.page-header-section .header-content';
        var container = document.querySelector(selector);
        if (!container) {
            return;
        }
        var message = container.querySelector('.page-message');
        if (!message) {
            message = document.createElement('div');
            message.className = 'page-message';
            container.appendChild(message);
        }
        message.classList.remove('success', 'error');
        if (type) {
            message.classList.add(type);
        }
        message.textContent = text || '';
    }

    window.staffUtils = {
        setupScrollRestore: setupScrollRestore,
        showPageMessage: showPageMessage
    };
})();
