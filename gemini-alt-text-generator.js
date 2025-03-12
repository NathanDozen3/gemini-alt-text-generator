jQuery(document).ready(function($) {
    $('#gemini-generate-alt-button').click(function() {
        var button = $(this);
        button.prop('disabled', true).text('Generating...');

        $.ajax({
            url: gemini_alt_text_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'gemini_alt_text_generate',
                post_id: gemini_alt_text_ajax.post_id
            },
            success: function(response) {
                if (response.success) {
                    // Update the alt text field
                    var altTextField = $('input[name="image_alt"]');
                    if (altTextField.length) {
                        altTextField.val(response.data.alt_text);
                    }
                }
                // Refresh the alt text field (you might need to adjust the selector)
                var altTextField = $('input[name="image_alt"]');
                if (altTextField.length) {
                  $.ajax({
                    url: gemini_alt_text_ajax.ajax_url,
                    type: 'POST',
                    data: {
                      action: 'get-attachment',
                      id: gemini_alt_text_ajax.post_id,
                      fetch: 'edit'
                    },
                    success: function(attachmentData) {
                      var newAlt = $(attachmentData).find('input[name="image_alt"]').val();
                      if(newAlt){
                        altTextField.val(newAlt);
                      }
                      button.prop('disabled', false).text('Generate Alt Text (Gemini)');
                    },
                    error: function(error){
                      console.log("Error refreshing alt text field", error);
                      button.prop('disabled', false).text('Generate Alt Text (Gemini)');
                    }
                  });
                } else{
                  button.prop('disabled', false).text('Generate Alt Text (Gemini)');
                }
            },
            error: function(error) {
                console.log('Error generating alt text:', error);
                button.prop('disabled', false).text('Generate Alt Text (Gemini)');
            }
        });
    });
});