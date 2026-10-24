/* Mazyoud Image Align — admin UI */
(function ($) {
  'use strict';
  if (typeof MazIA === 'undefined') return;

  var EX = MazIA.excludeValue;

  function typeOptions(state) {
    var sel = state && state.excluded ? EX : ((state && state.type) || 'default'), h = '';
    $.each(MazIA.types, function (k, t) {
      h += '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' + esc(t.label) + ' (' + t.fill + '%)</option>';
    });
    return h + '<option value="' + EX + '"' + (sel === EX ? ' selected' : '') + '>✕ Not a hanger photo</option>';
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function bust(src) { return src ? src.split('?')[0] + '?v=' + Date.now() : src; }
  function post(action, data) { return $.post(MazIA.ajax, $.extend({ action: 'maz_ia_' + action, nonce: MazIA.nonce }, data)); }

  /* Refresh every visible thumbnail of an attachment after a change */
  function refreshThumbs(id) {
    $('img[data-attachment-id="' + id + '"], .attachment[data-id="' + id + '"] img, #product_images_container li[data-attachment_id="' + id + '"] img, .attachment-details .details-image')
      .each(function () { this.src = bust(this.src); if (this.srcset) this.srcset = ''; });
    if (String($('#_thumbnail_id').val()) === String(id)) $('#set-post-thumbnail img').each(function () { this.src = bust(this.src); this.srcset = ''; });
    if (window.wp && wp.media && wp.media.attachment) { try { wp.media.attachment(id).fetch(); } catch (e) {} }
  }

  /* Apply the server state to every control group of that attachment */
  function applyState($wrap, state, flagHtml) {
    $wrap.find('.maz-ia-type').val(state.excluded ? EX : state.type);
    var $b = $wrap.find('.maz-ia-btn, .maz-ia-overlay');
    $b.toggleClass('maz-ia-done', !!state.done && !state.excluded);
    $b.each(function () { var compact = $(this).hasClass('button-small') || $(this).hasClass('maz-ia-overlay'); $(this).text(state.excluded ? (compact ? '✕' : 'Excluded') : (state.done ? (compact ? '✓ redo' : '✓ Aligned — redo') : 'Align')); });
    $wrap.find('.maz-ia-revert').prop('hidden', !state.backup);
    if (flagHtml !== undefined) $wrap.find('.maz-ia-flags').html(flagHtml || '');
    if (MazIA.states && typeof MazIA.states === 'object') MazIA.states[state.id] = state;
  }

  function setBusy($wrap, busy, text) {
    $wrap.find('button').prop('disabled', busy);
    if (busy) $wrap.find('.maz-ia-btn, .maz-ia-overlay').each(function () { $(this).data('mazLabel', $(this).text()).text(text || 'Aligning…'); });
    $wrap.find('.maz-ia-status').text('').removeClass('maz-ia-err');
  }
  function restore($wrap) { $wrap.find('.maz-ia-btn, .maz-ia-overlay').each(function () { if ($(this).data('mazLabel')) $(this).text($(this).data('mazLabel')); }); $wrap.find('button').prop('disabled', false); }
  function fail($wrap, msg) { $wrap.find('.maz-ia-status').text('✗ ' + msg).addClass('maz-ia-err'); }

  /* ---- actions ---- */
  function align($wrap, id, type) {
    setBusy($wrap, true);
    post('align', { id: id, type: type }).done(function (res) {
      if (!res.success) { fail($wrap, res.data || 'error'); restore($wrap); return; }
      applyState($wrap, res.data.state, res.data.flag_text);
      if (!res.data.excluded) refreshThumbs(id);
    }).fail(function () { fail($wrap, 'request failed'); }).always(function () { $wrap.find('button').prop('disabled', false); });
  }

  function revert($wrap, id) {
    if (!window.confirm('Put the original photo back and regenerate the thumbnails?')) return;
    setBusy($wrap, true, 'Reverting…');
    post('revert', { id: id }).done(function (res) {
      if (!res.success) { fail($wrap, res.data || 'error'); restore($wrap); return; }
      applyState($wrap, res.data.state, ''); refreshThumbs(id);
    }).fail(function () { fail($wrap, 'request failed'); }).always(function () { $wrap.find('button').prop('disabled', false); });
  }

  /* ---- before / after preview modal ---- */
  function preview($wrap, id, type) {
    setBusy($wrap, true, 'Preview…');
    post('preview', { id: id, type: type }).done(function (res) {
      if (!res.success) { fail($wrap, res.data || 'error'); restore($wrap); return; }
      var d = res.data, label = (MazIA.types[d.type] || {}).label || d.type;
      var flags = d.flags.length ? '<ul class="maz-ia-pv-flags">' + d.flags.map(function (f) { return '<li>⚠ ' + esc(f) + '</li>'; }).join('') + '</ul>' : '<p class="maz-ia-pv-ok">No warnings.</p>';
      var $m = $('<div class="maz-ia-modal" role="dialog" aria-modal="true" aria-label="Align preview"><div class="maz-ia-modal-box">' +
        '<div class="maz-ia-modal-head"><strong>Align preview</strong> — ' + esc(label) + ' · ball: ' + esc(d.ball.confidence) + ' · scale ×' + d.scale + ' · ' + esc(d.engine) + ' ' + d.ms + ' ms<button type="button" class="maz-ia-modal-x" aria-label="Close">×</button></div>' +
        '<div class="maz-ia-modal-body"><figure><img src="' + esc(d.original) + '" alt=""><figcaption>Original ' + d.src_size[0] + '×' + d.src_size[1] + '</figcaption></figure>' +
        '<figure><img src="' + d.result + '" alt=""><figcaption>Result (reduced preview)</figcaption></figure></div>' + flags +
        '<div class="maz-ia-modal-foot"><button type="button" class="button maz-ia-modal-cancel">Cancel</button> <button type="button" class="button button-primary maz-ia-modal-apply">Apply</button></div></div></div>');
      $('body').append($m);
      $m.find('.maz-ia-modal-apply').one('click', function () { $m.remove(); align($wrap, id, type); });
      $m.on('click', '.maz-ia-modal-x, .maz-ia-modal-cancel', function () { $m.remove(); restore($wrap); });
      $(document).one('keydown.mazia', function (e) { if (e.key === 'Escape') $m.find('.maz-ia-modal-cancel').trigger('click'); });
    }).fail(function () { $wrap.find('button').prop('disabled', false); fail($wrap, 'request failed'); });
  }

  /* Main button: exclude → immediate; otherwise preview (unless disabled or shift-click) */
  function onAlignClick(e) {
    e.preventDefault(); e.stopPropagation();
    var $b = $(this), $wrap = $b.closest('.maz-ia-wrap, .maz-ia-ov'), id = $b.data('id') || $wrap.data('id');
    var type = $wrap.find('.maz-ia-type').val() || 'default';
    if (type === EX) { align($wrap, id, type); return; }
    if (MazIA.preview && !e.shiftKey) preview($wrap, id, type); else align($wrap, id, type);
  }
  $(document).on('click', '.maz-ia-btn, .maz-ia-overlay', onAlignClick);
  $(document).on('click', '.maz-ia-revert', function (e) { e.preventDefault(); e.stopPropagation(); var $w = $(this).closest('.maz-ia-wrap, .maz-ia-ov'); revert($w, $(this).data('id') || $w.data('id')); });

  /* Selecting "Not a hanger photo" in a dropdown and then re-selecting a type un-excludes on the next align — no extra button needed.
     But switching away from "excluded" should re-enable immediately so the image can be aligned again: */
  $(document).on('change', '.maz-ia-type', function () {
    var $w = $(this).closest('.maz-ia-wrap, .maz-ia-ov'), id = $w.data('id') || $w.find('[data-id]').first().data('id');
    var st = MazIA.states && MazIA.states[id];
    if (st && st.excluded && $(this).val() !== EX) post('exclude', { id: id, on: 0 }).done(function (res) { if (res.success) applyState($w, res.data.state); });
  });

  /* ---- WooCommerce product edit screen: overlays ---- */
  if (MazIA.isProduct) {
    function decorate() {
      $('#product_images_container li.image').each(function () {
        var $li = $(this), id = $li.data('attachment_id');
        if (!id || $li.find('.maz-ia-ov').length) return;
        var st = (MazIA.states && MazIA.states[id]) || { id: id, type: 'default' };
        var $ov = $('<div class="maz-ia-ov" data-id="' + id + '"><select class="maz-ia-type" aria-label="Garment type">' + typeOptions(st) + '</select>' +
          '<button type="button" class="maz-ia-overlay" data-id="' + id + '" title="Align">Align</button>' +
          '<button type="button" class="maz-ia-revert maz-ia-ov-revert" data-id="' + id + '" title="Revert to original">↺</button><span class="maz-ia-status"></span><span class="maz-ia-flags"></span></div>');
        $li.append($ov); applyState($ov, st, st.flags && st.flags.length ? '<span class="maz-ia-flag" title="' + esc(st.flags.map(function (f) { return MazIA.flagLabels[f] || f; }).join('\n')) + '">⚠</span>' : '');
        // touch devices: first tap on the tile reveals the controls instead of opening the modal
        $li.on('touchstart', function () { if (!$li.hasClass('maz-ia-show')) { $('#product_images_container li.image').removeClass('maz-ia-show'); $li.addClass('maz-ia-show'); } });
      });
      var featId = $('#_thumbnail_id').val(), $feat = $('#postimagediv .inside');
      if ($feat.find('#set-post-thumbnail img').length && featId && featId !== '-1' && !$feat.find('.maz-ia-wrap').length) {
        var st = (MazIA.states && MazIA.states[featId]) || { id: featId, type: 'default' };
        var $w = $('<p class="maz-ia-wrap" data-id="' + featId + '"><select class="maz-ia-type" aria-label="Garment type">' + typeOptions(st) + '</select> ' +
          '<button type="button" class="button maz-ia-btn" data-id="' + featId + '">Align</button> <button type="button" class="button button-small button-link maz-ia-revert" data-id="' + featId + '">Revert</button><span class="maz-ia-status"></span><span class="maz-ia-flags"></span></p>');
        $feat.append($w); applyState($w, st);
      } else if ((!featId || featId === '-1') && $feat.find('.maz-ia-wrap').length) $feat.find('.maz-ia-wrap').remove();
    }
    decorate();
    var target = document.getElementById('product_images_container');
    if (target) new MutationObserver(decorate).observe(target, { childList: true, subtree: true });
    var featNode = document.getElementById('postimagediv');
    if (featNode) new MutationObserver(decorate).observe(featNode, { childList: true, subtree: true });
    $(document).on('mousedown click', '.maz-ia-ov, .maz-ia-ov select', function (e) { e.stopPropagation(); });
  }

  /* ---- Media Library: background job progress ---- */
  if (MazIA.isUpload) {
    var $jobs = $('#maz-ia-jobs'), timer = null;
    function renderJobs(jobs) {
      if (!jobs || !jobs.length) { $jobs.empty(); return false; }
      var active = false, h = '';
      jobs.forEach(function (j) {
        var pct = j.total ? Math.round(100 * j.done / j.total) : 100, running = j.status === 'queued' || j.status === 'running';
        if (running) active = true;
        h += '<div class="notice notice-info maz-ia-job"><p><strong>Image Align — ' + esc(j.action_label) + (j.type_label ? ' as ' + esc(j.type_label) : '') + '</strong>: ' +
          j.done + ' / ' + j.total + ' (' + j.ok + ' ok, ' + j.fail + ' failed' + (j.flagged ? ', ' + j.flagged + ' flagged for review' : '') + ') — ' + esc(j.status) +
          (running ? ' <button type="button" class="button button-small maz-ia-job-cancel" data-job="' + esc(j.id) + '">Cancel</button>' : ' <button type="button" class="button button-small maz-ia-job-dismiss" data-job="' + esc(j.id) + '">Dismiss</button>') +
          '</p><div class="maz-ia-bar"><div style="width:' + pct + '%"></div></div>' +
          (j.errors && j.errors.length ? '<ul class="maz-ia-job-errors">' + j.errors.map(function (e) { return '<li>#' + e.id + ': ' + esc(e.msg) + '</li>'; }).join('') + '</ul>' : '') + '</div>';
      });
      $jobs.html(h);
      return active;
    }
    function poll() { post('job', { op: 'status' }).done(function (res) { if (res.success && renderJobs(res.data.jobs)) timer = setTimeout(poll, 4000); else if (res.success) { timer = null; if ($('.maz-ia-job').length) setTimeout(function () { window.location.reload(); }, 1500); } }); }
    if (renderJobs(MazIA.jobs)) timer = setTimeout(poll, 3000);
    $(document).on('click', '.maz-ia-job-cancel, .maz-ia-job-dismiss', function () {
      var op = $(this).hasClass('maz-ia-job-cancel') ? 'cancel' : 'dismiss';
      post('job', { op: op, job: $(this).data('job') }).done(function (res) { if (res.success) renderJobs(res.data.jobs); });
    });
  }
})(jQuery);
