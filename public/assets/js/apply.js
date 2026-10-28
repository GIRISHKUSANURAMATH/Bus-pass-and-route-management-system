/* Bus-pass application form: populate stops, live fee quote (server-calculated). */
(function () {
  'use strict';
  const form = document.getElementById('applyForm');
  if (!form) return;
  const routes = App.data('routeData') || [];
  const routeSel = document.getElementById('route_id');
  const boardSel = document.getElementById('boarding_stop_id');
  const destSel = document.getElementById('destination_stop_id');
  const durSel = document.getElementById('duration');
  const feeBox = document.getElementById('feeBox');

  function fillStops() {
    const r = routes.find((x) => String(x.id) === routeSel.value);
    [boardSel, destSel].forEach((sel) => {
      const keep = sel.value || sel.dataset.selected;
      sel.innerHTML = '';
      sel.add(new Option(r ? 'Choose a stop…' : 'Select route first', ''));
      (r ? r.stops : []).forEach((s) => sel.add(new Option(s.name + ' (' + s.km + ' km)', s.id)));
      if (keep && [...sel.options].some((o) => o.value === String(keep))) sel.value = String(keep);
      sel.dataset.selected = '';
    });
  }

  let seq = 0;
  async function quote() {
    if (!routeSel.value || !boardSel.value || !destSel.value) {
      feeBox.className = 'text-muted';
      feeBox.textContent = 'Choose route, stops and duration to see the fee.';
      return;
    }
    const my = ++seq;
    feeBox.textContent = 'Calculating…';
    const res = await App.api('api/fee-quote.php', {
      route_id: +routeSel.value, boarding_stop_id: +boardSel.value,
      destination_stop_id: +destSel.value, duration: durSel.value,
    });
    if (my !== seq) return;
    if (!res.ok) { feeBox.className = 'text-danger'; feeBox.textContent = res.error || 'Could not calculate fee.'; return; }
    feeBox.className = '';
    feeBox.innerHTML =
      '<div class="amount-pill">₹' + Number(res.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</div>' +
      '<div class="small text-muted">' + App.esc(res.duration_label) + ' pass · ' + App.esc(res.distance_km) + ' km between your stops</div>';
  }

  routeSel.addEventListener('change', () => { fillStops(); quote(); });
  [boardSel, destSel, durSel].forEach((s) => s.addEventListener('change', quote));
  form.addEventListener('submit', (ev) => {
    if (boardSel.value && boardSel.value === destSel.value) {
      ev.preventDefault();
      alert('Boarding and destination stops must be different.');
    }
  });
  fillStops();
  quote();
})();
