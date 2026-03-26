(function () {
  function initAgentNotesField() {
    // Inject notes field after first AI button or into ticket actions
    var $firstBtn = $('a.ai-generate-reply').first();
    if (!$firstBtn.length) return;

    // Only inject once
    if ($('#ai-agent-notes').length) return;

    var $wrapper = $('<div class="ai-agent-notes-wrapper">' +
      '<label for="ai-agent-notes">Ergänzende Anweisungen für die KI:</label>' +
      '<textarea id="ai-agent-notes" placeholder="z.B. \'Betone die Kulanzregelung\' oder \'Nennen Sie den Wartungstermin am 15.04.\'"></textarea>' +
      '</div>');

    // Insert before the button's parent (likely a list item)
    $firstBtn.closest('li').before($wrapper);
  }

  function getStatusBox() {
    var $box = $('#ai-response-status');
    if ($box.length) return $box;

    $box = $(
      '<div id="ai-response-status" class="ai-response-status" aria-live="polite">' +
        '<span class="ai-response-status-spinner" aria-hidden="true"></span>' +
        '<div class="ai-response-status-text">' +
          '<strong>KI erstellt Antwort</strong>' +
          '<span>Bitte kurz warten, der Entwurf wird gerade erzeugt.</span>' +
        '</div>' +
      '</div>'
    );

    $('body').append($box);
    return $box;
  }

  function setReplyText(text) {
    var $ta = $('#response');
    if (!$ta.length) return false;

    // Ensure the Post Reply tab is active so editor is initialized
    var $postBtn = $('a.post-response.action-button').first();
    if ($postBtn.length && !$postBtn.hasClass('active')) {
      try { $postBtn.trigger('click'); } catch (e) { }
    }

    // Prefer Redactor source.setCode when richtext is enabled
    try {
      if (typeof $ta.redactor === 'function' && $ta.hasClass('richtext')) {
        var current = $ta.redactor('source.getCode') || '';
        var newText = current ? (current + "\n\n" + text) : text;
        $ta.redactor('source.setCode', newText);
        return true;
      }
    } catch (e) { }

    // Fallback to plain textarea append
    var current = $ta.val() || '';
    $ta.val(current ? (current + "\n\n" + text) : text).trigger('change');
    return true;
  }

  function setLoading($a, loading) {
    var $status = getStatusBox();

    if (loading) {
      if ($a.data('loading')) return;

      $a.data('loading', true);
      $a.data('original-html', $a.html());
      $a.addClass('ai-loading');
      $a.attr('aria-busy', 'true');
      $a.html('<i class="icon-refresh icon-spin"></i> KI Antwort wird erstellt ...');
      $status.addClass('is-visible');
    } else {
      $a.removeData('loading');
      $a.removeClass('ai-loading');
      $a.removeAttr('aria-busy');
      if ($a.data('original-html')) {
        $a.html($a.data('original-html'));
        $a.removeData('original-html');
      }
      $status.removeClass('is-visible');
    }
  }

  $(document).on('click', 'a.ai-generate-reply', function (e) {
    e.preventDefault();
    var $a = $(this);
    var tid = $a.data('ticket-id');
    if (!tid || $a.data('loading')) return false;

    setLoading($a, true);
    var url = (window.AIResponseGen && window.AIResponseGen.ajaxEndpoint) || 'ajax.php/ai/response';

    // Get agent notes if present
    var $notes = $('#ai-agent-notes');
    var notes = $notes.length ? $notes.val().trim() : '';

    var ajaxData = { 
      ticket_id: tid, 
      instance_id: $a.data('instance-id') || ''
    };
    if (notes) {
      ajaxData.agent_notes = notes;
    }

    $.ajax({
      url: url,
      method: 'POST',
      data: ajaxData,
      dataType: 'json'
    }).done(function (resp) {
      if (resp && resp.ok) {
        if (!setReplyText(resp.text || '')) {
          alert('AI response generated, but reply box not found.');
        }
      } else {
        alert((resp && resp.error) ? resp.error : 'Failed to generate response');
      }
    }).fail(function (xhr) {
      var msg = 'Request failed';
      try {
        var r = JSON.parse(xhr.responseText);
        if (r && r.error) msg = r.error;
      } catch (e) { }
      alert(msg);
    }).always(function () {
      setLoading($a, false);
    });

    return false;
  });

  // Initialize agent notes field on page load and when DOM changes
  $(document).ready(function () {
    initAgentNotesField();
  });
  $(document).on('ajaxsuccessor', 'body', function () {
    initAgentNotesField();
  });
})();
