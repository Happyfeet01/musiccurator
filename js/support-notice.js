(() => {
    async function init() {
        const template = document.getElementById('musiccurator-support-template');
        if (!template) return;
        // Claim the template before the first asynchronous step.
        template.remove();
        const url = template.dataset.url;
        const headers = { requesttoken: template.dataset.token };
        try {
            const response = await fetch(url, { headers, cache: 'no-store' });
            if (!response.ok || !(await response.json()).visible) return;
        } catch {
            return;
        }
        const notice = template.content.firstElementChild.cloneNode(true);
        const buttons = notice.querySelectorAll('button');
        const error = notice.querySelector('[role="status"]');
        notice.addEventListener('click', async event => {
            const button = event.target.closest('button[data-duration]');
            if (!button) return;
            buttons.forEach(item => { item.disabled = true; });
            error.hidden = true;
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers,
                    body: new URLSearchParams({ duration: button.dataset.duration }),
                });
                if (!response.ok || response.redirected) throw new Error('Dismissal failed');
                notice.remove();
            } catch {
                error.hidden = false;
            } finally {
                buttons.forEach(item => { item.disabled = false; });
            }
        });
        document.body.appendChild(notice);
        // Keep an unstyled notice out of the Nextcloud header if its stylesheet failed to load.
        if (getComputedStyle(notice).position !== 'fixed') {
            notice.remove();
            return;
        }
        notice.hidden = false;
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
