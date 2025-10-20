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
});