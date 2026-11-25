(() => {
    async function init() {
        const template = document.getElementById('musiccurator-support-template');
        if (!template) return;
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
                notice.hidden = true;
            } catch {
                error.hidden = false;
            } finally {
                buttons.forEach(item => { item.disabled = false; });
            }
        });
        document.body.appendChild(notice);
        notice.hidden = false;
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
