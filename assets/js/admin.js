jQuery(document).ready(function($){
  $('.wc4agc-sync-btn').on('click', function(){
    var sync = $(this).data('sync');
    var $card = $('#wc4agc-card-' + sync);
    var $overlay = $('#wc4agc-overlay-' + sync);
    var $btn = $(this);
    var $result = $('#wc4agc-result-' + sync);
    $result.html('');
    $card.addClass('syncing');
    $overlay.show();
    $btn.prop('disabled', true);
    $.post(wc4agc_ajax.ajax_url, {
      action: 'wc4agc_sync_' + sync,
      nonce: wc4agc_ajax.nonce
    }, function(response){
      $card.removeClass('syncing');
      $overlay.hide();
      $btn.prop('disabled', false);
      if(response.success && response.data){
        var html = '<div class="notice notice-success wc4agc-notice"><strong>Sincronización completada</strong><br/>';
        $.each(response.data, function(k,v){
          if(k === 'error_msgs' && $.isArray(v) && v.length > 0){
            html += '<br/><strong>Errores:</strong><ul style="margin:0 0 0 18px;">';
            $.each(v, function(i,err){ html += '<li style="font-size:0.97em;">'+err+'</li>'; });
            html += '</ul>';
          } else if(k !== 'error_msgs') {
            html += k.charAt(0).toUpperCase() + k.slice(1) + ': <strong>' + (typeof v === 'boolean' ? (v ? 'Sí' : 'No') : v) + '</strong><br/>';
          }
        });
        html += '</div>';
        $result.html(html);
      } else {
        $result.html('<div class="notice notice-error wc4agc-notice">Error en la sincronización.</div>');
      }
    }).fail(function(){
      $card.removeClass('syncing');
      $overlay.hide();
      $btn.prop('disabled', false);
      $result.html('<div class="notice notice-error wc4agc-notice">Error de red o timeout en la sincronización.</div>');
    });
  });
}); 