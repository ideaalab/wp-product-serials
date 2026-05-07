jQuery(document).ready(function($){

    // Title Validation on Publish
    $('#publish, #save-post').on('click', function(e){
        var title = $('#title').val();
        if ( typeof title !== 'undefined' && title.trim() === '' ) {
            if ( $('#title').is(':visible') ) {
                alert( 'Error: The Title field is required.' );
                $('#title').focus();
                e.preventDefault();
                $(this).removeClass('disabled');
                $('.spinner').removeClass('is-active');
                return false;
            }
        }

        // Required Fields Validation for Custom CPTs

        if ( $('body.post-type-ial_email_campaign').length > 0 ) {
            var targetType = $('#ial_target_type').val();
            if ( ! targetType ) {
                alert( 'Error: Target Audience is required.' );
                e.preventDefault(); return false;
            }
            if ( targetType === 'product' && ! $('#ial_target_product').val() ) {
                alert( 'Error: Please select a Product.' );
                e.preventDefault(); return false;
            }
            if ( targetType === 'production' && ! $('#ial_target_production').val() ) {
                alert( 'Error: Please select a Production.' );
                e.preventDefault(); return false;
            }
            if ( ! $('#ial_email_subject').val() ) {
                alert( 'Error: Email Subject is required.' );
                e.preventDefault(); return false;
            }
        }

    });

});
