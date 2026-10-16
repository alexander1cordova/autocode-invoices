/* AutoCode Invoices — no dependencies. Live totals + touch signature pad. */
(function () {
  'use strict';

  function money(n) {
    return '$' + n.toFixed(2);
  }

  /* ---------- Live totals for line tables ---------- */
  document.querySelectorAll('[data-lines]').forEach(function (tbl) {
    var targetSel = tbl.getAttribute('data-total-target');
    var totalEl = targetSel ? document.querySelector(targetSel) : null;
    var subEl = document.querySelector(tbl.getAttribute('data-subtotal-target'));
    var taxEl = document.querySelector(tbl.getAttribute('data-tax-target'));
    var taxRate = parseFloat(tbl.getAttribute('data-tax-rate')) || 0;

    function recalc() {
      var sub = 0;
      tbl.querySelectorAll('tr[data-line]').forEach(function (tr) {
        var qEl = tr.querySelector('[data-qty]');
        var pEl = tr.querySelector('[data-price]');
        var q, p;
        if (qEl && pEl) {
          // editable row: read from inputs
          q = parseFloat(qEl.value);
          p = parseFloat(pEl.value);
        } else {
          // read-only row: read from data attributes
          q = parseFloat(tr.getAttribute('data-qty-val'));
          p = parseFloat(tr.getAttribute('data-price-val'));
        }
        q = isNaN(q) ? 0 : q;
        p = isNaN(p) ? 0 : p;
        var row = q * p;
        sub += row;
        var cell = tr.querySelector('[data-row-total]');
        if (cell) cell.textContent = money(row);
      });
      var tax = sub * taxRate / 100;
      if (subEl) subEl.textContent = money(sub);
      if (taxEl) taxEl.textContent = money(tax);
      if (totalEl) totalEl.textContent = money(sub + tax);
    }

    tbl.addEventListener('input', recalc);
    recalc();
  });

  /* ---------- Signature pad (mouse + touch via Pointer Events) ---------- */
  var canvas = document.getElementById('sigpad');
  if (canvas) {
    initSigPad(canvas);
  }

  function initSigPad(canvas) {
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var hasStrokes = false;
    var last = null;

    function styleCanvas(dpr) {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.lineWidth = 2.5;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#111111';
    }

    function resize() {
      var rect = canvas.getBoundingClientRect();
      var dpr = window.devicePixelRatio || 1;
      var w = Math.max(1, Math.round(rect.width * dpr));
      var h = Math.max(1, Math.round(rect.height * dpr));
      if (w === canvas.width && h === canvas.height) return; // unchanged: don't wipe
      // Changing canvas.width clears the bitmap — preserve any strokes first.
      var saved = hasStrokes ? canvas.toDataURL('image/png') : null;
      canvas.width = w;
      canvas.height = h;
      styleCanvas(dpr);
      if (saved) {
        var img = new Image();
        img.onload = function () {
          ctx.save();
          ctx.setTransform(1, 0, 0, 1, 0, 0);
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
          ctx.restore();
        };
        img.src = saved;
      }
    }
    resize();
    var resizeT = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeT);
      resizeT = setTimeout(resize, 200); // debounce: mobile fires resize on keyboard/scroll
    });

    function pos(e) {
      var rect = canvas.getBoundingClientRect();
      return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    canvas.addEventListener('pointerdown', function (e) {
      drawing = true;
      last = pos(e);
      try { canvas.setPointerCapture(e.pointerId); } catch (err) {}
      e.preventDefault();
    });

    canvas.addEventListener('pointermove', function (e) {
      if (!drawing) return;
      var p = pos(e);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      last = p;
      hasStrokes = true;
      e.preventDefault();
    });

    function stop() { drawing = false; }
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    canvas.addEventListener('pointerleave', stop);

    var clearBtn = document.getElementById('sigclear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.restore();
        hasStrokes = false;
      });
    }

    var form = canvas.closest('form');
    if (form) {
      form.addEventListener('submit', function (e) {
        if (!hasStrokes) {
          e.preventDefault();
          alert('Please ask the customer to sign before continuing.');
          return;
        }
        var hidden = document.getElementById('sigdata');
        if (hidden) hidden.value = canvas.toDataURL('image/png');
      });
    }
  }
})();

/* ---------- Copy-to-clipboard buttons (event delegation, [data-copy]) ---------- */
document.addEventListener('click', function (ev) {
  var btn = ev.target.closest ? ev.target.closest('[data-copy]') : null;
  if (!btn || btn.dataset.copyDone) return;
  var input = document.getElementById(btn.getAttribute('data-copy'));
  if (!input) return;
  btn.dataset.copyDone = '1';
  function done() {
    var orig = btn.textContent;
    btn.textContent = 'Copied ✓';
    setTimeout(function () { btn.textContent = orig; delete btn.dataset.copyDone; }, 2000);
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(input.value).then(done, function () {
      input.select();
      try { document.execCommand('copy'); } catch (e) {}
      done();
    });
  } else {
    input.select();
    try { document.execCommand('copy'); } catch (e) {}
    done();
  }
});
