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
		__( 'Gemini Alt Text Settings', 'gemini-alt-text-generator' ),
		'gemini_alt_text_section_callback',
		'media'
	);

	add_settings_field(
		'gemini_alt_text_media_api_key',
		__( 'Gemini API Key', 'gemini-alt-text-generator' ),
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
	echo '<p>' . esc_html__( 'Enter your Gemini API key to enable alt text generation.', 'gemini-alt-text-generator' ) . '</p>';
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
function gemini_generate_alt_text( $attachment_id ) {
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
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'contents' => array(
								array(
									'parts' => array(
										array(
											'text' => 'Describe the following image for use as alt text. Return just the text to be used in the alt attribute.',
										),
										array(
											'inlineData' => array(
												'mimeType' => 'image/jpeg',
												'data'     => base64_encode(
													file_get_contents(
														$image_url,
														false,
														stream_context_create(
															array(
																'ssl' => array(
																	'verify_peer'      => false,
																	'verify_peer_name' => false,
																),
															)
														)
													)
												),
											),
										),
									),
								),
							),
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				error_log( 'Gemini API request failed: ' . $response->get_error_message() );
				return;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				$new_alt_text = sanitize_text_field( $data['candidates'][0]['content']['parts'][0]['text'] );
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

	if ( $screen && 'post' === $screen->base && $post && get_post_mime_type( $post->ID ) &&
		0 === strpos( get_post_mime_type( $post->ID ), 'image/' ) ) {
		echo '<button type="button" id="gemini-generate-alt-button" class="button">' . esc_html__( 'Generate Alt Text (Gemini)', 'gemini-alt-text-generator' ) . '</button>';
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
		0 === strpos( get_post_mime_type( $post->ID ), 'image/' ) ) {
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
 * Add button to media modal.
 */
function gemini_alt_text_add_button_upload_screen() {
	?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			function processAttachmentDetails() {
				var attachmentId = getAttachmentIdFromUrl();

				console.log('Attachment ID:', attachmentId); // Debugging: Log attachment ID

				if (attachmentId && $('.attachment-details').length) {
					var altTextField = $('#attachment-details-two-column-alt-text');
					var settingsDiv = $('.attachment-details .settings');

					if (altTextField.val() === '') {
						if (!settingsDiv.find('#gemini-generate-alt-button').length) {
							settingsDiv.append('<button type="button" id="gemini-generate-alt-button" class="button">Generate Alt Text (Gemini)</button>');
							$('#gemini-generate-alt-button').on('click', function() {
								if (attachmentId && !isNaN(attachmentId) && typeof wp.media !== 'undefined' && typeof wp.media.attachment === 'function') {
									console.log('AJAX request initiated for attachment ID:', attachmentId); // Debugging: Log AJAX initiation

									var data = {
										'action': 'gemini_save_alt_text', // AJAX action
										'post_id': attachmentId
									};

									console.log('AJAX data:', data); // Debugging: Log AJAX data

									$.post(ajaxurl, data, function(response) {
										console.log('AJAX response:', response); // Debugging: Log AJAX response

										if (response.success) {
											var attachment = wp.media.attachment(attachmentId);
											if (attachment && typeof attachment.loadDetails === 'function') {
												attachment.loadDetails(); // Refresh modal
											}
											// location.reload(); // Refresh the page
										} else {
											console.error('Error saving alt text:', response.data);
										}
									});
								}
							});
						}
					} else {
						settingsDiv.find('#gemini-generate-alt-button').remove();
					}
				}
			}

			function getAttachmentIdFromUrl() {
				var urlParams = new URLSearchParams(window.location.search);
				return urlParams.get('item');
			}

			function checkAndProcess() {
				if (getAttachmentIdFromUrl() && $('.attachment-details').length) {
					processAttachmentDetails();
				}
			}

			// Initial check on document ready
			checkAndProcess();

			// Check when modal content changes
			var modalObserver = new MutationObserver(function(mutations) {
				checkAndProcess();
			});
			modalObserver.observe($('.media-frame-content')[0], { childList: true, subtree: true });

			// Check when URL changes
			var urlObserver = new MutationObserver(function(mutations) {
				checkAndProcess();
			});
			urlObserver.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });

			// Check when modal is opened
			$(document).on('click', '.edit-attachment', function() {
				setTimeout(checkAndProcess, 100);
			});
		});
	</script>
	<?php
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
		'posts_per_page' => -1,
		'meta_query'     => array(
			'relation' => 'OR',
			array(
				'key'     => '_wp_attachment_image_alt',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_wp_attachment_image_alt',
				'compare' => '=',
				'value'   => '',
			)
		),
	);

	$query = new WP_Query($args);

	if ($query->have_posts()) {
		while ($query->have_posts()) {
			$query->the_post();
			$attachment_id = get_the_ID();
			gemini_generate_alt_text($attachment_id);
		}
		wp_reset_postdata();
	} else {
		echo "No images without alt text found.";
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
		__( 'Generate Missing Alt Text', 'gemini-alt-text-generator' ),
		'gemini_generate_alt_text_section_callback',
		'media'
	);

	add_settings_field(
		'gemini_generate_alt_text_button_field',
		__( 'Generate', 'gemini-alt-text-generator' ),
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
	echo '<p>' . esc_html__( 'Click the button below to generate alt text for all images in your media library that are missing it.', 'gemini-alt-text-generator' ) . '</p>';
}

/**
 * Button field callback.
 */
function gemini_generate_alt_text_button_field_callback() {
	?>
	<button type="button" id="gemini-generate-alt-text-button" class="button button-primary"><?php esc_html_e( 'Generate Alt Text', 'gemini-alt-text-generator' ); ?></button>
	<div id="gemini-alt-text-progress" style="margin-top: 10px;"></div>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#gemini-generate-alt-text-button').on('click', function() {
				$('#gemini-alt-text-progress').html('<span class="spinner is-active"></span> <?php esc_html_e( 'Generating alt text...', 'gemini-alt-text-generator' ); ?>');
				$.post(ajaxurl, {
					action: 'gemini_generate_alt_text_ajax'
				}, function(response) {
					if (response.success) {
						$('#gemini-alt-text-progress').html('<p><?php esc_html_e( 'Alt text generation has been started. Please check the Action Scheduler for progress.', 'gemini-alt-text-generator' ); ?></p>');
					} else {
						$('#gemini-alt-text-progress').html('<p><?php esc_html_e( 'Error:', 'gemini-alt-text-generator' ); ?> ' + response.data + '</p>');
					}
				}).fail(function(response) {
					console.log(response)
					$('#gemini-alt-text-progress').html('<p><?php esc_html_e( 'An error occurred.', 'gemini-alt-text-generator' ); ?></p>');
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

function gemini_save_alt_text_handler() {
	$post_id = intval( $_POST['post_id'] );
	if ( $post_id ) {
		$alt_text = gemini_generate_alt_text( $post_id );
		wp_send_json_success( array( 'alt_text' => $alt_text ) );
	} else {
		wp_send_json_error( 'Invalid post ID' );
	}
}
add_action( 'wp_ajax_gemini_save_alt_text', 'gemini_save_alt_text_handler' );
add_action( 'wp_ajax_nopriv_gemini_save_alt_text', 'gemini_save_alt_text_handler' );
