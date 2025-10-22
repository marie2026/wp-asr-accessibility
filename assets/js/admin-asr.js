jQuery(function($){
  $('#asr-test-endpoint').on('click', function(e){
    e.preventDefault();
    var $btn = $(this);
    $('#asr-test-result').text('Test en cours...');
    $.post(ASRAdmin.ajaxUrl, {
      action: 'asr_test_endpoint',
      _ajax_nonce: ASRAdmin.testEndpointNonce
    }).done(function(res){
      if(res.success){
        $('#asr-test-result').text('OK, HTTP code: ' + (res.data.code || 'unknown'));
      } else {
        $('#asr-test-result').text('Erreur: ' + (res.data || res));
      }
    }).fail(function(xhr){
      $('#asr-test-result').text('Erreur réseau: ' + xhr.status);
    });
  });

  // Delete job
  $(document).on('click', '.asr-delete-job', function(e){
    e.preventDefault();
    if (!confirm('Supprimer ce job et le fichier audio ?')) return;
    var id = $(this).data('id');
    $.post(ASRAdmin.ajaxUrl, { action: 'asr_delete_job', id: id }, function(res){
      if (res.success) location.reload();
      else alert('Erreur: ' + (res.data || 'unknown'));
    });
  });

  // Rerun job
  $(document).on('click', '.asr-rerun-job', function(e){
    e.preventDefault();
    var id = $(this).data('id');
    $.post(ASRAdmin.ajaxUrl, { action: 'asr_rerun_job', id: id }, function(res){
      if (res.success) location.reload();
      else alert('Erreur: ' + (res.data || 'unknown'));
    });
  });
});