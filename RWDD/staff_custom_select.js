document.addEventListener('DOMContentLoaded', () => {
    const selects = Array.from(document.querySelectorAll('.custom-select'));

    function closeSelect(wrapper) {
        wrapper.dataset.open = 'false';
        const trigger = wrapper.querySelector('.custom-select-trigger');
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
    }

    function closeAll(except) {
        selects.forEach((wrapper) => {
            if (wrapper !== except) {
                closeSelect(wrapper);
            }
        });
    }

    selects.forEach((wrapper) => {
        const targetId = wrapper.dataset.target;
        const select = targetId ? document.getElementById(targetId) : null;
        if (!select) {
            return;
        }

        const trigger = wrapper.querySelector('.custom-select-trigger');
        const label = wrapper.querySelector('.custom-select-label');
        const options = Array.from(wrapper.querySelectorAll('.custom-select-option'));

        if (trigger) {
            trigger.addEventListener('click', () => {
                const isOpen = wrapper.dataset.open === 'true';
                closeAll(wrapper);
                wrapper.dataset.open = isOpen ? 'false' : 'true';
                trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });
        }

        options.forEach((option) => {
            option.addEventListener('click', () => {
                const value = option.dataset.value ?? '';
                select.value = value;

                if (label) {
                    label.textContent = option.textContent || 'Select';
                }

                options.forEach((item) => {
                    item.classList.remove('is-selected');
                    item.setAttribute('aria-selected', 'false');
                });
                option.classList.add('is-selected');
                option.setAttribute('aria-selected', 'true');

                closeSelect(wrapper);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    document.addEventListener('click', (event) => {
        selects.forEach((wrapper) => {
            if (!wrapper.contains(event.target)) {
                closeSelect(wrapper);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            selects.forEach((wrapper) => closeSelect(wrapper));
        }
    });
});
