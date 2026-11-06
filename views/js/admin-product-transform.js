/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

// Panneau « Transformer en abonnement » de la fiche produit : aperçu, transformation par étapes
// (une requête par lot, barre de progression, reprise ou annulation après une interruption) et
// réalignement du stock, puis rechargement complet de la page (les déclinaisons chargées par la
// fiche sont périmées après une transformation).
$(function () {
  var $panel = $('#ciklik-transform');
  if (!$panel.length || $panel.data('ciklikReady')) {
    return;
  }
  $panel.data('ciklikReady', true);

  var $result = $panel.find('.ciklik-transform-result');

  // Remplace chaque %d dans l'ordre
  function format(label) {
    var values = Array.prototype.slice.call(arguments, 1);
    return String($panel.data(label)).replace(/%d/g, function () {
      return values.length ? values.shift() : '';
    });
  }

  function frequencies() {
    return $panel.find('.ciklik-transform-frequency:checked').map(function () {
      return $(this).val();
    }).get();
  }

  function show(type, lines) {
    var $box = $('<div>').addClass('alert alert-' + type);
    $.each(lines, function (i, line) {
      $box.append($('<p>').text(line));
    });
    $result.empty().append($box);
  }

  function setProgress(done, total) {
    $panel.find('.ciklik-transform-progress').show()
      .find('.progress-bar').css('width', (total ? Math.floor(done * 100 / total) : 0) + '%');
  }

  // Fin d'une chaîne d'appels : la page se recharge, les boutons restent inactifs
  function finished(message) {
    show('success', [message]);
    setTimeout(function () { window.location.reload(); }, 1500);

    return true;
  }

  // Après un échec en cours de chaîne : la transformation (ou son annulation) est gardée en l'état.
  // Plus rien en cours (autre onglet qui a fini) : la page est rechargée.
  function interrupted(buttons) {
    return function (code) {
      if (code === 'no_job') {
        window.location.reload();
        return;
      }
      $result.find('.alert').append($('<p>').text(format('labelInterrupted')));
      $panel.find(buttons).show();
    };
  }

  // Étapes à la suite jusqu'à la fin ; un échec laisse « Reprendre » et « Annuler »
  function runSteps() {
    post('step', function (response) {
      var progress = response.progress;
      setProgress(progress.done, progress.total);
      if (progress.finished) {
        return finished(response.message);
      }
      show('info', [format('labelProgress', progress.done, progress.total)]);
      runSteps();

      return true;
    }, interrupted('[data-ciklik-action="resume"], [data-ciklik-action="cancel"]'));
  }

  // Annulation par lots ; un échec ne propose que de relancer l'annulation
  function runCancel() {
    post('cancel', function (response) {
      if (response.cancel.finished) {
        return finished(response.message);
      }
      show('info', [format('labelCancelling', response.cancel.remaining)]);
      runCancel();

      return true;
    }, interrupted('[data-ciklik-action="cancel"]'));
  }

  // Requête AJAX ; les boutons restent inactifs tant que done() rend true (chaîne en cours)
  function post(action, done, onFail) {
    var $buttons = $panel.find('button').prop('disabled', true);
    $.ajax({
      url: $panel.data('url'),
      method: 'POST',
      dataType: 'json',
      data: {ajax: 1, action: action, id_product: $panel.data('idProduct'), frequencies: frequencies()}
    }).done(function (response) {
      if (done(response) !== true) {
        $buttons.prop('disabled', false);
      }
    }).fail(function (xhr) {
      var json = xhr.responseJSON || {};
      $buttons.prop('disabled', false);
      show('danger', [json.error || $panel.data('error')]);
      if (onFail) {
        onFail(json.code);
      }
    });
  }

  function showPreview(preview) {
    var lines = [
      preview.simple ? format('labelSimple', preview.quantity_before) : format('labelExisting', preview.oneoff),
      format('labelTotal', preview.total, preview.oneoff, preview.created),
      format('labelStock', preview.quantity_before, preview.quantity_after),
      format('labelPrice')
    ];
    if (preview.simple) {
      lines.push(format('labelCart'));
    }
    if (preview.non_innodb.length) {
      lines.push(format('labelEngine') + ' ' + preview.non_innodb.join(', '));
    }
    show('info', lines);
    $panel.find('[data-ciklik-action="transform"]').show();
  }

  function showRealign(realign) {
    var lines = [format('labelRealign', realign.changes.length, realign.unchanged)];
    $.each(realign.details, function (i, change) {
      lines.push(change.name + ' : ' + change.from + ' → ' + change.to);
    });
    if (realign.orphan_names.length) {
      lines.push(format('labelOrphans') + ' ' + realign.orphan_names.join(' ; '));
    }
    show('info', lines);
    $panel.find('[data-ciklik-action="realign_stock"]').toggle(realign.changes.length > 0);
  }

  // Une sélection modifiée invalide l'aperçu affiché
  $panel.on('change', '.ciklik-transform-frequency', function () {
    $result.empty();
    $panel.find('[data-ciklik-action="transform"]').hide();
  });

  $panel.on('click', '[data-ciklik-action]', function (event) {
    event.preventDefault();
    var action = $(this).data('ciklikAction');

    if (action === 'preview') {
      post(action, function (response) { showPreview(response.preview); });
    } else if (action === 'realign_preview') {
      post(action, function (response) { showRealign(response.realign); });
    } else if (action === 'resume') {
      $panel.find('[data-ciklik-action="resume"], [data-ciklik-action="cancel"]').hide();
      runSteps();
    } else if (action === 'transform' && window.confirm($panel.data('confirmTransform'))) {
      var $transform = $(this).hide();
      // Un démarrage refusé n'a rien écrit : on peut corriger et relancer. Une transformation déjà
      // lancée (autre onglet, double clic) : la page rechargée propose « Reprendre » ou « Annuler ».
      post('start', function (response) {
        setProgress(response.progress.done, response.progress.total);
        runSteps();

        return true;
      }, function (code) {
        if (code === 'job_running' || code === 'locked') {
          window.location.reload();
          return;
        }
        $transform.show();
      });
    } else if (action === 'cancel' && window.confirm($panel.data('confirmCancel'))) {
      $panel.find('[data-ciklik-action="resume"], [data-ciklik-action="cancel"]').hide();
      runCancel();
    } else if (action === 'sync') {
      post(action, function (response) { return finished(response.message); });
    } else if (action === 'realign_stock' && window.confirm($panel.data('confirmRealign'))) {
      post(action, function (response) { return finished(response.message); });
    }
  });
});
