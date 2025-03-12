<?php
/**
 * Plugin Name: Gemini Alt Text Generator
 * Description: Generates alt text using the Gemini API for images uploaded to WordPress, and adds a button to the media edit screen.
 * Version: 1.2.6
 * Author: Nathan Johnson
 *
 * @package Gemini_Alt_Text_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Adds settings to media settings page.
 */
function gemini_alt_text_media_settings() {
    add_settings_section(
        'gemini_alt_text_section',
        'Gemini Alt Text Settings',
        'gemini_alt_text_section_callback',
        'media'
    );

    add_settings_field(
        'gemini_alt_text_media_api_key',
        'Gemini API Key',
        'gemini_alt_text_api_key_field_callback',
        'media',
        'gemini_alt_text_section'
    );

    register_setting( 'media', 'gemini_alt_text_media_api_key' );
}
add_action( 'admin_init', 'gemini_alt_text_media_settings' );

/**
 * Section callback.
 */
function gemini_alt_text_section_callback() {
    echo '<p>Enter your Gemini API key to enable alt text generation.</p>';
}

/**
 * API key field callback.
 */
function gemini_alt_text_api_key_field_callback() {
    $api_key = get_option( 'gemini_alt_text_media_api_key', '' );
    ?>
    <input type="text" name="gemini_alt_text_media_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
    <?php
}

/**
 * Retrieves Gemini API key from options.
 *
 * @return string API key.
 */
function gemini_get_api_key() {
    return get_option( 'gemini_alt_text_media_api_key', '' );
}

/**
 * Generates alt text using Gemini API.
 *
 * @param int $attachment_id Attachment ID.
 */
function gemini_generate_alt_text($attachment_id) {
	$api_key = gemini_get_api_key();

	if ( empty( $api_key ) ) {
		error_log( 'Gemini API key not configured.' );
		return;
	}

	$image_url = wp_get_attachment_url( $attachment_id );

	if ( ! $image_url ) {
		error_log( 'Failed to get image URL for attachment ID: ' . $attachment_id );
		return;
	}

	$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

	if ( empty( $alt_text ) ) {
		try {
			$response = wp_remote_post(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $api_key,
				[
					'headers' => [
						'Content-Type' => 'application/json',
					],
					'body' => json_encode([
						'contents' => [
							[
								'parts' => [
									[
										'text' => 'Describe the following image for use as alt text. Return just the text to be used in the alt attribute.',
									],
									[
										'inlineData' => [
											'mimeType' => 'image/jpeg',
											'data' => base64_encode( file_get_contents( $image_url, false, stream_context_create(
												array(
													'ssl' => array(
														'verify_peer'      => false,
														'verify_peer_name' => false,
													),
												)
											) ) ),
										],
									],
								],
							],
						],
					]),
				]
			);

			if ( is_wp_error( $response ) ) {
				error_log( 'Gemini API request failed: ' . $response->get_error_message() );
				return;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				$new_alt_text = sanitize_text_field($data['candidates'][0]['content']['parts'][0]['text']);
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $new_alt_text );
			} else {
				error_log( 'Gemini API response format unexpected.' );
			}
		} catch ( Exception $e ) {
			error_log( 'Gemini API error: ' . $e->getMessage() );
		}
	}
}

/**
 * Processes attachment using Action Scheduler.
 *
 * @param int $attachment_id Attachment ID.
 */
function gemini_process_attachment( $attachment_id ) {	
	if ( class_exists( 'ActionScheduler' ) ) {
		as_enqueue_async_action( 'gemini_generate_alt_text_action', array( $attachment_id ), 'gemini' );
	} else {
		wp_async_task( 'gemini_generate_alt_text', array( $attachment_id ) );
	}
}
add_action( 'add_attachment', 'gemini_process_attachment' );

/**
 * Action Scheduler callback.
 *
 * @param int $attachment_id Attachment ID.
 */
function gemini_generate_alt_text_action( $attachment_id ) {
	gemini_generate_alt_text( $attachment_id );
}
add_action( 'gemini_generate_alt_text_action', 'gemini_generate_alt_text_action', 10, 1 );

/**
 * Fallback async task.
 *
 * @param string $action Action name.
 * @param array  $data   Data array.
 */
function wp_async_task( $action, $data = array() ) {
    $url = add_query_arg(
        array(
            'action'       => 'wp_async_task',
            'async-action' => $action,
            'async-data'   => base64_encode( serialize( $data ) ),
        ),
        site_url( 'wp-admin/admin-ajax.php' )
    );
    error_log( 'wp_async_task URL: ' . $url );

    $args = array(
        'timeout'   => 0,
        'blocking'  => false,
        'sslverify' => false, // Add this line
    );

    $response = wp_remote_get( $url, $args );
    error_log( 'wp_async_task Response: ' . print_r( $response, true ) );
}

/**
 * AJAX task handler.
 */
function wp_async_task_handler() {
	if ( ! isset( $_GET['async-action'] ) || ! isset( $_GET['async-data'] ) ) {
		wp_die();
	}
	$action = sanitize_text_field( wp_unslash( $_GET['async-action'] ) );
	$data   = unserialize( base64_decode( wp_unslash( $_GET['async-data'] ) ) );
	if ( function_exists( $action ) ) {
		call_user_func_array( $action, $data );
	}
	wp_die();
}
add_action( 'wp_ajax_wp_async_task', 'wp_async_task_handler' );
add_action( 'wp_ajax_nopriv_wp_async_task', 'wp_async_task_handler' );

/**
 * Adds button to media edit screen.
 *
 * @param array   $form_fields Form fields.
 * @param WP_Post $post        Post object.
 * @return array Modified form fields.
 */
function gemini_alt_text_add_button( $form_fields, $post ) {
	$screen = get_current_screen();

	if ( $screen && $screen->base === 'post' && $post && get_post_mime_type( $post->ID ) &&
		strpos( get_post_mime_type( $post->ID ), 'image/' ) === 0 ) {
		echo '<button type="button" id="gemini-generate-alt-button" class="button">Generate Alt Text (Gemini)</button>';
	}
	return $form_fields;
}
add_filter( 'attachment_fields_to_edit', 'gemini_alt_text_add_button', 10, 2 );

/**
 * Enqueues JavaScript.
 */
function gemini_alt_text_enqueue_scripts() {
	global $post;
	if ( $post && get_post_mime_type( $post->ID ) &&
		strpos( get_post_mime_type( $post->ID ), 'image/' ) === 0 ) {
		wp_enqueue_script( 'gemini-alt-text-script', plugin_dir_url( __FILE__ ) . 'gemini-alt-text-generator.js', array( 'jquery' ), '1.0', true );
		wp_localize_script(
			'gemini-alt-text-script',
			'gemini_alt_text_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'post_id'  => $post->ID,
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', 'gemini_alt_text_enqueue_scripts' );

/**
 * AJAX handler to generate alt text.
 */
function gemini_alt_text_generate_ajax() {
	$post_id = intval( $_POST['post_id'] );
	gemini_generate_alt_text( $post_id );
	wp_die();
}
add_action( 'wp_ajax_gemini_alt_text_generate', 'gemini_alt_text_generate_ajax' );

/**
 * Adds button to media edit screen on upload.php.
 */
function gemini_alt_text_add_button_upload_screen() {
	if ( isset( $_GET['item'] ) ) {
		$attachment_id = intval( $_GET['item'] );
		$attachment = get_post( $attachment_id );
		if ( $attachment && get_post_mime_type( $attachment_id ) &&
			strpos( get_post_mime_type( $attachment_id ), 'image/' ) === 0 ) {
			?>
			<script type="text/javascript">
				jQuery(document).ready(function($) {
					var observer = new MutationObserver(function(mutations) {
						if ($('.attachment-details').length) {
							var attachmentId = getAttachmentIdFromUrl();
							var attachmentMime = $('.attachment-details').find('.thumbnail img').attr('src');
							var altTextField = $('#attachment-details-two-column-alt-text');
							var settingsDiv = $('.attachment-details .settings');

							if (attachmentMime) {
								if (altTextField.val() === '') {
									if (!settingsDiv.find('#gemini-generate-alt-button').length) {
										
										settingsDiv.prepend('<span class="setting" data-setting="generate-alt-button"><label for="attachment-details-generate-alt-button" class="name">Gemini</label><button type="button" id="gemini-generate-alt-button" class="button">Generate Alt Text</button></span>');

										$('#gemini-generate-alt-button').on('click', function() {
											var data = {
												'action': 'gemini_alt_text_generate',
												'post_id': <?php echo $attachment_id; ?>
											};

											$.post(ajaxurl, data, function(response) {
												location.reload();
											});
										});
									}
								} else {
									settingsDiv.find('#gemini-generate-alt-button').remove();
								}
							}
							observer.disconnect();
						}
					});

					observer.observe(document.body, { childList: true, subtree: true });

					// Function to extract attachment ID from URL
					function getAttachmentIdFromUrl() {
						var urlParams = new URLSearchParams(window.location.search);
						return urlParams.get('item');
					}
				});
			</script>
			<?php
		}
	}
}
add_action( 'admin_footer-upload.php', 'gemini_alt_text_add_button_upload_screen' );

/**
 * Generates alt text for images without it.
 */
function gemini_generate_missing_alt_text() {
    $args = array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1, // Get all images
    );

    $images = get_posts( $args );

    foreach ( $images as $image ) {
        $alt_text = get_post_meta( $image->ID, '_wp_attachment_image_alt', true );
        if ( empty( $alt_text ) ) {
            gemini_process_attachment( $image->ID ); // Use gemini_process_attachment()
        }
    }
}

/**
 * Process a single image alt text generation.
 *
 * @param int $image_id The ID of the image to process.
 */
function gemini_process_single_image_alt_text( $image_id ) {
    gemini_generate_alt_text( $image_id );
}
add_action( 'gemini_process_single_image_alt_text', 'gemini_process_single_image_alt_text', 10, 1 );

/**
 * Adds a button to trigger alt text generation to Media settings.
 */
function gemini_add_alt_text_generation_button_to_media_settings() {
    add_settings_section(
        'gemini_generate_alt_text_section',
        'Generate Missing Alt Text',
        'gemini_generate_alt_text_section_callback',
        'media'
    );

    add_settings_field(
        'gemini_generate_alt_text_button_field',
        'Generate',
        'gemini_generate_alt_text_button_field_callback',
        'media',
        'gemini_generate_alt_text_section'
    );
}
add_action( 'admin_init', 'gemini_add_alt_text_generation_button_to_media_settings' );

/**
 * Section callback.
 */
function gemini_generate_alt_text_section_callback() {
    echo '<p>Click the button below to generate alt text for all images in your media library that are missing it.</p>';
}

/**
 * Button field callback.
 */
function gemini_generate_alt_text_button_field_callback() {
    ?>
    <button type="button" id="gemini-generate-alt-text-button" class="button button-primary">Generate Alt Text</button>
    <div id="gemini-alt-text-progress" style="margin-top: 10px;"></div>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#gemini-generate-alt-text-button').on('click', function() {
                $('#gemini-alt-text-progress').html('<span class="spinner is-active"></span> Generating alt text...');
                $.post(ajaxurl, {
                    action: 'gemini_generate_alt_text_ajax'
                }, function(response) {
                    if (response.success) {
                        $('#gemini-alt-text-progress').html('<p>Alt text generation has been started. Please check the Action Scheduler for progress.</p>');
                    } else {
                        $('#gemini-alt-text-progress').html('<p>Error: ' + response.data + '</p>');
                    }
                }).fail(function() {
                    $('#gemini-alt-text-progress').html('<p>An error occurred.</p>');
                });
            });
        });
    </script>
    <?php
}

/**
 * AJAX handler to trigger alt text generation.
 */
function gemini_generate_alt_text_ajax_handler() {
    gemini_generate_missing_alt_text();
    wp_send_json_success();
}
add_action( 'wp_ajax_gemini_generate_alt_text_ajax', 'gemini_generate_alt_text_ajax_handler' );
add_action( 'wp_ajax_nopriv_gemini_generate_alt_text_ajax', 'gemini_generate_alt_text_ajax_handler' );
