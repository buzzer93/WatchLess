const progress = document.querySelector('[data-status-url]');
if (progress) {
    let failures = 0;
    const poll = async () => {
        if (document.hidden) { window.setTimeout(poll, 5000); return; }
        try {
            const response = await fetch(progress.dataset.statusUrl, {headers: {'Accept': 'application/json'}, cache: 'no-store', signal: AbortSignal.timeout(10000)});
            if (!response.ok) throw new Error('Statut indisponible');
            const state = await response.json();
            if (state.status !== progress.dataset.status) { window.location.reload(); return; }
            failures = 0;
            progress.querySelector('[data-poll-message]').textContent = 'Le statut se met à jour automatiquement.';
            if (state.terminal) return;
        } catch {
            failures++;
            progress.querySelector('[data-poll-message]').textContent = 'Connexion interrompue. Nouvelle tentative automatique…';
        }
        window.setTimeout(poll, Math.min(30000, 4000 * (failures + 1)));
    };
    window.setTimeout(poll, 4000);
}
