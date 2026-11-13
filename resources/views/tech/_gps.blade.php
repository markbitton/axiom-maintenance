{{-- Clock buttons need the phone's location. Forms marked data-gps get lat/lng filled in before they submit.
     While on the clock and this page is open, the phone checks in every 15 minutes. --}}
<div id="gps-off" class="error" style="display:none;position:fixed;left:10px;right:10px;bottom:10px;z-index:1000;box-shadow:0 4px 14px rgba(0,0,0,.2)">
    <b>Location Services are off.</b> Turn on location for your browser (Settings › Privacy › Location Services) and allow this site, then try again. The clocks can't start without it.
</div>
<script>
(() => {
    const off = document.getElementById('gps-off');
    const locate = () => new Promise((resolve, reject) => {
        if (!navigator.geolocation) return reject(new Error('no gps'));
        navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });
    const put = (form, name, value) => {
        let i = form.querySelector(`input[name="${name}"]`);
        if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = name; form.appendChild(i); }
        i.value = value;
    };
    document.querySelectorAll('form[data-gps]').forEach(form => form.addEventListener('submit', async (e) => {
        if (form.dataset.located) return;
        e.preventDefault();
        if (!form.reportValidity()) return;
        const btn = form.querySelector('button'); const label = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Getting your location…'; }
        try {
            const p = await locate();
            put(form, 'lat', p.coords.latitude); put(form, 'lng', p.coords.longitude); put(form, 'accuracy', Math.round(p.coords.accuracy));
            off.style.display = 'none';
            form.dataset.located = '1';
            form.submit();
        } catch (err) {
            if (form.dataset.gps === 'optional') { form.dataset.located = '1'; form.submit(); return; }
            off.style.display = 'block'; 
            if (btn) { btn.disabled = false; btn.textContent = label; }
        }
    }));

    // 15-minute check-in while clocked in. Phones pause web pages in the background, so we also check in when the page comes back.
    const onClock = {{ !empty($shift) ? 'true' : 'false' }};
    if (!onClock) return;
    const EVERY = 15 * 60 * 1000;
    const key = 'axm-last-ping';
    const last = () => { try { return +localStorage.getItem(key) || 0; } catch { return 0; } };
    const ping = async () => {
        if (Date.now() - last() < EVERY - 30000) return;
        try {
            const p = await locate();
            const r = await fetch('{{ route('tech.ping') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) }) });
            if (r.ok) { try { localStorage.setItem(key, String(Date.now())); } catch {} }
            off.style.display = 'none';
        } catch { off.style.display = 'block'; }
    };
    ping();
    setInterval(ping, 60 * 1000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) ping(); });
})();
</script>
