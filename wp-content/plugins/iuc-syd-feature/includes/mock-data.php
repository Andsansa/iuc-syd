<?php
/**
 * Test data for the IUC Syd admin page.
 *
 * Loads data/mock-data.json (companies, users, comments) into WordPress and
 * removes it again. Only available outside production (Local reports "local").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IUC_MOCK_META = 'iuc_mock';

function iuc_mock_available() {
	return 'production' !== wp_get_environment_type();
}

function iuc_mock_load() {
	$path = IUC_PORTAL_FEATURES_PATH . 'data/mock-data.json';

	if ( ! file_exists( $path ) ) {
		return null;
	}

	$data = json_decode( (string) file_get_contents( $path ), true );

	return is_array( $data ) ? $data : null;
}

function iuc_mock_company_id_by_key( $key ) {
	$posts = get_posts(
		array(
			'post_type'      => 'iuc_company',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => 'iuc_mock_key',
			'meta_value'     => $key,
		)
	);

	return $posts ? (int) $posts[0] : 0;
}

/**
 * Create or update companies, users and comments from the JSON file.
 *
 * Running it again refreshes details but keeps the approval status you have
 * changed while testing. Comments are recreated every time.
 */
function iuc_mock_seed() {
	$data = iuc_mock_load();

	if ( ! $data ) {
		return false;
	}

	$company_ids = array();

	foreach ( $data['companies'] as $company ) {
		$post_id = iuc_mock_company_id_by_key( $company['key'] );

		if ( ! $post_id ) {
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'iuc_company',
					'post_status' => $company['status'],
					'post_title'  => $company['name'],
				)
			);

			if ( ! $post_id || is_wp_error( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, 'iuc_mock_key', $company['key'] );
			update_post_meta( $post_id, IUC_MOCK_META, 1 );
		}

		$company_ids[ $company['key'] ] = (int) $post_id;

		$meta = array(
			'iuc_org_nr'      => $company['org_nr'],
			'iuc_kommun'      => $company['kommun'],
			'iuc_city'        => $company['city'],
			'iuc_industry'    => $company['industry'],
			'iuc_address'     => $company['address'],
			'iuc_postcode'    => $company['postcode'],
			'iuc_contact_phone' => $company['phone'],
			'iuc_website'     => $company['website'],
			'iuc_employees'   => (string) $company['employees'],
			'iuc_description' => $company['description'],
			'iuc_notes'       => $company['notes'],
		);

		foreach ( $meta as $key => $value ) {
			if ( '' === (string) $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		update_post_meta( $post_id, 'iuc_needs', $company['needs'] );
		update_post_meta( $post_id, 'iuc_participation', $company['participation'] );
	}

	$user_ids = array();

	foreach ( $data['users'] as $user ) {
		$company_id = isset( $company_ids[ $user['company'] ] ) ? $company_ids[ $user['company'] ] : 0;
		$existing   = get_user_by( 'login', $user['login'] );

		if ( $existing ) {
			$user_id = (int) $existing->ID;
		} else {
			$user_id = wp_insert_user(
				array(
					'user_login'      => $user['login'],
					'user_pass'       => $data['password'],
					'user_email'      => $user['email'],
					'display_name'    => $user['name'],
					'first_name'      => strtok( $user['name'], ' ' ),
					'role'            => 'subscriber',
					'user_registered' => gmdate(
						'Y-m-d H:i:s',
						time() - (int) $user['registered_days_ago'] * DAY_IN_SECONDS
					),
				)
			);

			if ( is_wp_error( $user_id ) ) {
				continue;
			}

			// Only on creation: the user_register hook has already set a default,
			// and later runs must not undo approvals made while testing.
			update_user_meta( $user_id, IUC_APPROVAL_META, $user['status'] );
			update_user_meta( $user_id, IUC_MOCK_META, 1 );
		}

		$user_ids[ $user['login'] ] = (int) $user_id;

		update_user_meta( $user_id, 'iuc_company_id', $company_id );
		update_user_meta( $user_id, 'iuc_company', $company_id ? get_the_title( $company_id ) : '' );
		update_user_meta( $user_id, 'iuc_job_title', $user['title'] );
		update_user_meta( $user_id, 'iuc_consent', ! empty( $user['consent'] ) ? 1 : 0 );
		update_user_meta( $user_id, 'iuc_phone', $user['phone'] );
	}

	// Main contact: link the user and copy name, e-mail and phone to the company.
	foreach ( $data['companies'] as $company ) {
		if ( empty( $company_ids[ $company['key'] ] ) || empty( $user_ids[ $company['main'] ] ) ) {
			continue;
		}

		$post_id = $company_ids[ $company['key'] ];
		$main    = get_userdata( $user_ids[ $company['main'] ] );

		update_post_meta( $post_id, 'iuc_main_user_id', (int) $main->ID );
		update_post_meta( $post_id, 'iuc_contact_name', $main->display_name );
		update_post_meta( $post_id, 'iuc_contact_email', $main->user_email );
	}

	iuc_mock_seed_comments( $data['comments'], $user_ids );
	iuc_mock_seed_requests( isset( $data['requests'] ) ? $data['requests'] : array(), $user_ids );

	return true;
}

function iuc_mock_seed_comments( array $items, array $user_ids ) {
	foreach ( get_comments( array( 'meta_key' => IUC_MOCK_META, 'status' => 'all', 'fields' => 'ids' ) ) as $comment_id ) {
		wp_delete_comment( $comment_id, true );
	}

	// Comments need a post to hang on.
	$posts = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	if ( $posts ) {
		$post_id = (int) $posts[0];
	} else {
		$post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Community: testinlägg',
				'post_content' => 'Testinlägg för moderering.',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $post_id, IUC_MOCK_META, 1 );
	}

	if ( ! $post_id ) {
		return;
	}

	foreach ( $items as $item ) {
		$author_id = isset( $user_ids[ $item['author'] ] ) ? $user_ids[ $item['author'] ] : 0;
		$author    = $author_id ? get_userdata( $author_id ) : false;

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => $item['text'],
				'user_id'              => $author_id,
				'comment_author'       => $author ? $author->display_name : $item['author'],
				'comment_author_email' => $author ? $author->user_email : '',
				'comment_approved'     => in_array( $item['status'], array( 'reported', 'published' ), true ) ? 1 : 0,
			)
		);

		if ( ! $comment_id ) {
			continue;
		}

		update_comment_meta( $comment_id, IUC_MOCK_META, 1 );

		if ( 'reported' === $item['status'] ) {
			update_comment_meta( $comment_id, IUC_REPORTED_META, 1 );
		}
	}
}

/**
 * Private requests from members. Recreated on every run.
 */
function iuc_mock_seed_requests( array $items, array $user_ids ) {
	$old = get_posts(
		array(
			'post_type'      => 'iuc_private_request',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => IUC_MOCK_META,
		)
	);

	foreach ( $old as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	foreach ( $items as $item ) {
		if ( empty( $user_ids[ $item['author'] ] ) ) {
			continue;
		}

		$user_id    = $user_ids[ $item['author'] ];
		$company_id = (int) get_user_meta( $user_id, 'iuc_company_id', true );
		$date       = gmdate( 'Y-m-d H:i:s', time() - (int) $item['days_ago'] * DAY_IN_SECONDS );

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'iuc_private_request',
				'post_status'   => 'private',
				'post_author'   => $user_id,
				'post_title'    => $item['subject'],
				'post_content'  => $item['message'],
				'post_date_gmt' => $date,
				'post_date'     => get_date_from_gmt( $date ),
				'meta_input'    => array(
					'iuc_request_type'       => $item['type'],
					'iuc_request_company'    => $company_id ? get_the_title( $company_id ) : '',
					'iuc_request_company_id' => $company_id,
					'iuc_request_status'     => $item['status'],
					IUC_MOCK_META            => 1,
				),
			)
		);
	}
}

/**
 * Remove everything iuc_mock_seed() created.
 */
function iuc_mock_remove() {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$comments = get_comments(
		array(
			'meta_key' => IUC_MOCK_META,
			'status'   => 'all',
			'fields'   => 'ids',
		)
	);

	foreach ( $comments as $comment_id ) {
		wp_delete_comment( $comment_id, true );
	}

	$posts = get_posts(
		array(
			'post_type'      => array( 'iuc_company', 'iuc_private_request', 'post' ),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => IUC_MOCK_META,
		)
	);

	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	$users = get_users(
		array(
			'meta_key'   => IUC_MOCK_META,
			'meta_value' => 1,
			'fields'     => 'ID',
		)
	);

	foreach ( $users as $user_id ) {
		wp_delete_user( (int) $user_id );
	}
}

function iuc_mock_handle_action() {
	if ( ! current_user_can( 'manage_options' ) || ! iuc_mock_available() ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'iuc_mock_action', 'iuc_mock_nonce' );

	$do     = isset( $_POST['iuc_do'] ) ? sanitize_key( wp_unslash( $_POST['iuc_do'] ) ) : '';
	$notice = 'error';

	if ( 'seed' === $do && iuc_mock_seed() ) {
		$notice = 'mock_seeded';
	} elseif ( 'remove' === $do ) {
		iuc_mock_remove();
		$notice = 'mock_removed';
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'       => IUC_ADMIN_PAGE,
				'iuc_notice' => $notice,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_iuc_mock_action', 'iuc_mock_handle_action' );

/**
 * Card at the bottom of the overview page.
 */
function iuc_mock_render_card() {
	if ( ! iuc_mock_available() ) {
		return;
	}

	$data = iuc_mock_load();
	?>
	<section class="iuc-card iuc-mock" aria-labelledby="iuc-mock-title">
		<div class="iuc-card-head">
			<h2 id="iuc-mock-title">Testdata</h2>
			<span>Visas bara i test- och utvecklingsmiljö</span>
		</div>
		<p class="iuc-meta">
			Läser in <?php echo $data ? esc_html( (string) count( $data['companies'] ) ) : '0'; ?> företag,
			<?php echo $data ? esc_html( (string) count( $data['users'] ) ) : '0'; ?> användare och
			<?php echo $data ? esc_html( (string) count( $data['comments'] ) ) : '0'; ?> kommentarer och
			<?php echo $data && isset( $data['requests'] ) ? esc_html( (string) count( $data['requests'] ) ) : '0'; ?> förfrågningar från <code>data/mock-data.json</code>.
			Körs den igen uppdateras uppgifterna, men godkännanden du gjort behålls.
			<?php if ( $data ) : ?>
				Testanvändarna loggar in med sitt användarnamn (till exempel <code>sara.holm</code>)
				och lösenordet <code><?php echo esc_html( $data['password'] ); ?></code>.
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="iuc-actions">
			<input type="hidden" name="action" value="iuc_mock_action">
			<?php wp_nonce_field( 'iuc_mock_action', 'iuc_mock_nonce' ); ?>
			<button type="submit" name="iuc_do" value="seed" class="iuc-btn iuc-btn-primary">Lägg in testdata</button>
			<button type="submit" name="iuc_do" value="remove" class="iuc-btn iuc-btn-danger">Ta bort testdata</button>
		</form>
	</section>
	<?php
}
add_action( 'iuc_admin_after_dashboard', 'iuc_mock_render_card' );
