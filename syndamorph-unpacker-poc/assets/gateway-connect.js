(() => {
    'use strict';
    const form = document.getElementById('smup-gateway-connect');
    if (!form) return;
    const status = form.querySelector('[role="status"]');
    const submit = form.querySelector('[type="submit"]');
    const cancel = form.querySelector('[type="reset"]');
    cancel.hidden = false;
    form.addEventListener('reset', () => { status.textContent = ''; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submit.disabled) return;
        const request = new FormData(form);
        request.set('action', 'smup_gateway_connect');
        submit.disabled = cancel.disabled = true;
        status.textContent = 'Checking the Gateway connection…';
        try {
            const response = await fetch(form.dataset.ajaxUrl, {
                method: 'POST', body: request, credentials: 'same-origin', redirect: 'error',
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                status.textContent = result.data?.message || 'Check the Gateway URL and client key, then try again.';
                return;
            }
            form.reset();
            status.textContent = 'Connected. Loading leased packages…';
            window.location.reload();
        } catch {
            status.textContent = 'The connection check failed. Check your network and try again.';
        } finally {
            request.delete('gateway_key');
            submit.disabled = cancel.disabled = false;
        }
    });
})();
