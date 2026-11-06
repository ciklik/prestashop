/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

// Panneau « Transformer en abonnement » de la fiche produit : aperçu, transformation et
// réalignement du stock par AJAX, puis rechargement complet de la page (les déclinaisons
// chargées par la fiche sont périmées après une transformation).
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

  function post(action, done) {
    $panel.find('button').prop('disabled', true);
    $.ajax({
      url: $panel.data('url'),
      method: 'POST',
      dataType: 'json',
      data: {ajax: 1, action: action, id_product: $panel.data('idProduct'), frequencies: frequencies()}
    }).done(function (response) {
      done(response);
    }).fail(function (xhr) {
      var error = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : $panel.data('error');
      show('danger', [error]);
    }).always(function () {
      $panel.find('button').prop('disabled', false);
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
    } else if (window.confirm($panel.data(action === 'transform' ? 'confirmTransform' : 'confirmRealign'))) {
      post(action, function (response) {
        show('success', [response.message]);
        setTimeout(function () { window.location.reload(); }, 1500);
      });
    }
  });
});
