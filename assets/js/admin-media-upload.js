jQuery(document).ready(function($){
    var frame;
    var currentBtn;

    // Select Image
    $(document).on('click', '.ial-select-image-btn', function(e){
        e.preventDefault();
        currentBtn = $(this);

        // If the media frame already exists, reopen it.
        if ( frame ) {
            frame.open();
            return;
        }

        // Create a new media frame
        frame = wp.media({
            title: 'Select Product Image',
            button: {
                text: 'Use this image'
            },
            multiple: false  // Set to true to allow multiple files to be selected
        });

        // When an image is selected in the media frame...
        frame.on( 'select', function() {
            var attachment = frame.state().get('selection').first().toJSON();

            // Set ID to hidden input
            currentBtn.siblings('.ial-image-id').val(attachment.id);

            // Update Preview
            var preview = currentBtn.siblings('.ial-image-preview');
            var imgUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

            preview.html('<img src="' + imgUrl + '" style="max-width:100px; border:1px solid #ddd; padding:2px; margin-top:5px;">');

            // Show Remove button, hide select button text if needed or just keep both
            currentBtn.siblings('.ial-remove-image-btn').show();
        });

        // Finally, open the modal on click
        frame.open();
    });

    // Remove Image
    $(document).on('click', '.ial-remove-image-btn', function(e){
        e.preventDefault();
        var btn = $(this);
        btn.siblings('.ial-image-id').val('');
        btn.siblings('.ial-image-preview').html('');
        btn.hide();
    });

});
