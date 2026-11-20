<?php
/**
 * Private requests visible only to IUC Syd administrators.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the administrator-only content type.
 */
function iuc_register_private_requests() {
	register_post_type(
		'iuc_private_request',
		array(
			'labels' => array(
				'name'          => 'Privata förfrågningar',
				'singular_name' => 'Privat förfrågan',
				'menu_name'     => 'IUC-förfrågningar',
				'all_items'     => 'Alla förfrågningar',
				'view_item'     => 'Visa förfrågan',
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'menu_icon'           => 'dashicons-lock',
			'supports'            => array( 'title', 'editor', 'author' ),
			'map_meta_cap'        => false,
			'capabilities'        => array(
				'edit_post'              => 'manage_options',
				'read_post'              => 'manage_options',
				'delete_post'            => 'manage_options',
				'edit_posts'             => 'manage_options',
				'edit_others_posts'      => 'manage_options',
				'delete_posts'           => 'manage_options',
				'publish_posts'          => 'manage_options',
				'read_private_posts'     => 'manage_options',
				'delete_private_posts'   => 'manage_options',
				'delete_published_posts' => 'manage_options',
				'edit_private_posts'     => 'manage_options',
				'edit_published_posts'   => 'manage_options',
			),
		)
	);
}
add_action( 'init', 'iuc_register_private_requests' );

/**
 * Get the member's BuddyPress company field when available.
 */
function iuc_get_request_company( $user_id ) {
	if ( function_exists( 'xprofile_get_field_data' ) ) {
		$company = xprofile_get_field_data( 'Företag', $user_id );

		if ( $company ) {
			return $company;
		}
	}

	return get_user_meta( $user_id, 'iuc_company', true );
}

/**
 * Display the private-request form.
 *
 * Usage: [iuc_private_request_form]
 */
function iuc_private_request_form_shortcode() {
	if ( ! is_user_logged_in() ) {
		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( home_url( '/' ) ),
			esc_html__( 'Logga in för att kontakta IUC Syd.', 'iuc-portal' )
		);
	}

	$state = isset( $_GET['request'] )
		? sanitize_key( wp_unslash( $_GET['request'] ) )
		: '';

	ob_start();
	?>

	<section class="iuc-private-request" aria-labelledby="iuc-private-request-title">
		<h2 id="iuc-private-request-title">
			Privat kontakt med IUC Syd
		</h2>

		<p class="iuc-visibility-notice">
			<strong>Endast IUC Syd kan se den här förfrågan.</strong>
			Den publiceras inte i communityt och visas inte för andra medlemsföretag.
		</p>

		<?php if ( 'sent' === $state ) : ?>
			<p class="iuc-notice iuc-notice-success" role="status">
				Tack! Din förfrågan har skickats till IUC Syd.
			</p>
		<?php elseif ( $state ) : ?>
			<p class="iuc-notice iuc-notice-error" role="alert">
				Förfrågan kunde inte skickas. Kontrollera uppgifterna och försök igen.
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="iuc_private_request">

			<?php
			wp_nonce_field(
				'iuc_private_request',
				'iuc_private_request_nonce'
			);
			?>

			<p>
				<label for="iuc-request-subject">
					Rubrik
				</label>

				<input
					id="iuc-request-subject"
					name="request_subject"
					type="text"
					required
					maxlength="200"
				>
			</p>

			<p>
				<label for="iuc-request-type">
					Vad gäller förfrågan?
				</label>

				<select id="iuc-request-type" name="request_type" required>
					<option value="">Välj typ</option>
					<option value="need">Behov eller utmaning</option>
					<option value="question">Fråga till IUC Syd</option>
					<option value="contact">Kontaktperson eller användare</option>
					<option value="other">Annat</option>
				</select>
			</p>

			<p>
				<label for="iuc-request-message">
					Beskriv ditt behov eller din fråga
				</label>

				<textarea
					id="iuc-request-message"
					name="request_message"
					required
					minlength="10"
					maxlength="10000"
					rows="8"
				></textarea>
			</p>

			<button type="submit">
				Skicka endast till IUC Syd
			</button>
		</form>
	</section>

	<?php
	return ob_get_clean();
}
add_shortcode(
	'iuc_private_request_form',
	'iuc_private_request_form_shortcode'
);

/**
 * Validates and save the submitted request.
 */
function iuc_handle_private_request() {
	$redirect_url = wp_get_referer()
		? wp_get_referer()
		: home_url( '/' );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect(
			add_query_arg( 'request', 'login', home_url( '/' ) )
		);
		exit;
	}

	$nonce = isset( $_POST['iuc_private_request_nonce'] )
		? sanitize_text_field(
			wp_unslash( $_POST['iuc_private_request_nonce'] )
		)
		: '';

	if ( ! wp_verify_nonce( $nonce, 'iuc_private_request' ) ) {
		wp_safe_redirect(
			add_query_arg( 'request', 'security', $redirect_url )
		);
		exit;
	}

	$subject = isset( $_POST['request_subject'] )
		? sanitize_text_field( wp_unslash( $_POST['request_subject'] ) )
		: '';

	$type = isset( $_POST['request_type'] )
		? sanitize_key( wp_unslash( $_POST['request_type'] ) )
		: '';

	$message = isset( $_POST['request_message'] )
		? sanitize_textarea_field( wp_unslash( $_POST['request_message'] ) )
		: '';

	$allowed_types = array( 'need', 'question', 'contact', 'other' );

	if (
		strlen( $subject ) < 3 ||
		strlen( $message ) < 10 ||
		! in_array( $type, $allowed_types, true )
	) {
		wp_safe_redirect(
			add_query_arg( 'request', 'invalid', $redirect_url )
		);
		exit;
	}

	$user_id    = get_current_user_id();
	$company_id = function_exists( 'iuc_company_id_for_user' ) ? iuc_company_id_for_user( $user_id ) : 0;
	$company    = $company_id ? get_the_title( $company_id ) : iuc_get_request_company( $user_id );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'iuc_private_request',
			'post_status'  => 'private',
			'post_author'  => $user_id,
			'post_title'   => $subject,
			'post_content' => $message,
			'meta_input'   => array(
				'iuc_request_type'    => $type,
				'iuc_request_company' => sanitize_text_field( $company ),
				'iuc_request_company_id' => $company_id,
				'iuc_request_status'  => 'new',
			),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wp_safe_redirect(
			add_query_arg( 'request', 'failed', $redirect_url )
		);
		exit;
	}

	$user = wp_get_current_user();

	wp_mail(
		get_option( 'admin_email' ),
		'Ny privat IUC-förfrågan: ' . $subject,
		sprintf(
			"En ny privat förfrågan har skickats.\n\nFrån: %s\nE-post: %s\nFöretag: %s\n\nGranska i WordPress-administrationen.",
			$user->display_name,
			$user->user_email,
			$company
		)
	);

	wp_safe_redirect(
		add_query_arg( 'request', 'sent', $redirect_url )
	);
	exit;
}
add_action(
	'admin_post_iuc_private_request',
	'iuc_handle_private_request'
);