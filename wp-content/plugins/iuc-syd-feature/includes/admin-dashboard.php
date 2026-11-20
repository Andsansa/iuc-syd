<?php
/**
 * IUC Syd admin start page (Översikt).
 *
 * Cards: overview counts, approve users, approve companies, moderate comments.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IUC_APPROVAL_META   = 'iuc_approval_status'; // pending | approved | blocked
const IUC_MODERATION_META = 'iuc_moderation';      // kept | hidden
const IUC_REPORTED_META   = 'iuc_reported';        // 1 when a member reported the comment
const IUC_ADMIN_PAGE      = 'iuc-syd-admin';

/* ---------------------------------------------------------------------
 * Data model
 * ------------------------------------------------------------------ */

/**
 * Companies. Created in wp-admin; "pending" companies wait for approval.
 */
function iuc_admin_register_company_type() {
	register_post_type(
		'iuc_company',
		array(
			'labels'          => array(
				'name'          => 'Företag',
				'singular_name' => 'Företag',
				'add_new'       => 'Nytt företag',
				'add_new_item'  => 'Lägg till företag',
				'edit_item'     => 'Redigera företag',
				'all_items'     => 'Företag',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'supports'        => array( 'title', 'author' ),
			'capability_type' => 'post',
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'    => true,
		)
	);
}
add_action( 'init', 'iuc_admin_register_company_type' );

/**
 * New accounts start as "pending" unless an administrator creates them.
 */
function iuc_admin_default_user_status( $user_id ) {
	if ( is_admin() && current_user_can( 'create_users' ) ) {
		update_user_meta( $user_id, IUC_APPROVAL_META, 'approved' );
		return;
	}

	update_user_meta( $user_id, IUC_APPROVAL_META, 'pending' );
}
add_action( 'user_register', 'iuc_admin_default_user_status' );

/**
 * Pending and blocked members cannot log in. Administrators are never blocked.
 */
function iuc_admin_block_unapproved_login( $user ) {
	if ( is_wp_error( $user ) || user_can( $user, 'manage_options' ) ) {
		return $user;
	}

	$status = get_user_meta( $user->ID, IUC_APPROVAL_META, true );

	if ( 'pending' === $status ) {
		return new WP_Error(
			'iuc_pending',
			'Ditt konto väntar på godkännande från IUC Syd.'
		);
	}

	if ( 'blocked' === $status ) {
		return new WP_Error(
			'iuc_blocked',
			'Ditt konto har blockerats. Kontakta IUC Syd.'
		);
	}

	return $user;
}
add_filter( 'wp_authenticate_user', 'iuc_admin_block_unapproved_login' );

/* ---------------------------------------------------------------------
 * Queries
 * ------------------------------------------------------------------ */

function iuc_admin_users_with_status( $status, $limit = 10 ) {
	return get_users(
		array(
			'meta_key'   => IUC_APPROVAL_META,
			'meta_value' => $status,
			'number'     => $limit,
			'orderby'    => 'registered',
			'order'      => 'DESC',
		)
	);
}

function iuc_admin_count_users_with_status( $status ) {
	$query = new WP_User_Query(
		array(
			'meta_key'    => IUC_APPROVAL_META,
			'meta_value'  => $status,
			'number'      => 1,
			'fields'      => 'ID',
			'count_total' => true,
		)
	);

	return (int) $query->get_total();
}

function iuc_admin_count_companies( $status ) {
	$counts = wp_count_posts( 'iuc_company' );

	return isset( $counts->$status ) ? (int) $counts->$status : 0;
}

function iuc_admin_pending_companies( $limit = 10 ) {
	return get_posts(
		array(
			'post_type'      => 'iuc_company',
			'post_status'    => 'pending',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}

/**
 * Comments awaiting review: held comments and reported comments.
 */
function iuc_admin_comment_queries( array $extra = array() ) {
	$held = array_merge(
		array(
			'status'     => 'hold',
			'meta_query' => array(
				array(
					'key'     => IUC_MODERATION_META,
					'compare' => 'NOT EXISTS',
				),
			),
		),
		$extra
	);

	$reported = array_merge(
		array(
			'status'     => 'approve',
			'meta_query' => array(
				array(
					'key'   => IUC_REPORTED_META,
					'value' => '1',
				),
			),
		),
		$extra
	);

	return array( $held, $reported );
}

function iuc_admin_count_comments_to_review() {
	$total = 0;

	foreach ( iuc_admin_comment_queries( array( 'count' => true ) ) as $args ) {
		$total += (int) get_comments( $args );
	}

	return $total;
}

function iuc_admin_comments_to_review( $limit = 10 ) {
	$comments = array();

	foreach ( iuc_admin_comment_queries( array( 'number' => $limit ) ) as $args ) {
		$comments = array_merge( $comments, get_comments( $args ) );
	}

	usort(
		$comments,
		static function ( $a, $b ) {
			return strcmp( $b->comment_date_gmt, $a->comment_date_gmt );
		}
	);

	return array_slice( $comments, 0, $limit );
}

function iuc_admin_count_new_requests() {
	$query = new WP_Query(
		array(
			'post_type'      => 'iuc_private_request',
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => 'iuc_request_status',
			'meta_value'     => 'new',
		)
	);

	return (int) $query->found_posts;
}

/* ---------------------------------------------------------------------
 * Admin menu and assets
 * ------------------------------------------------------------------ */

function iuc_admin_menu() {
	add_menu_page(
		'IUC Syd – Översikt',
		'IUC Syd',
		'manage_options',
		IUC_ADMIN_PAGE,
		'iuc_admin_render_page',
		'dashicons-groups',
		2
	);

	// Rename the first submenu item (otherwise it repeats "IUC Syd").
	add_submenu_page(
		IUC_ADMIN_PAGE,
		'IUC Syd – Översikt',
		'Översikt',
		'manage_options',
		IUC_ADMIN_PAGE,
		'iuc_admin_render_page'
	);
}
add_action( 'admin_menu', 'iuc_admin_menu' );

function iuc_admin_assets( $hook ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( 0 !== strpos( $page, 'iuc-syd-' ) ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'iuc_company' !== $screen->post_type ) {
			return;
		}
	}

	$path = IUC_PORTAL_FEATURES_PATH . 'assets/admin.css';

	wp_enqueue_style(
		'iuc-syd-admin',
		IUC_PORTAL_FEATURES_URL . 'assets/admin.css',
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0'
	);
}
add_action( 'admin_enqueue_scripts', 'iuc_admin_assets' );

/* ---------------------------------------------------------------------
 * Actions (approve / block / moderate)
 * ------------------------------------------------------------------ */

function iuc_admin_handle_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'iuc_admin_action', 'iuc_admin_nonce' );

	$kind = isset( $_POST['iuc_kind'] ) ? sanitize_key( wp_unslash( $_POST['iuc_kind'] ) ) : '';
	$do   = isset( $_POST['iuc_do'] ) ? sanitize_key( wp_unslash( $_POST['iuc_do'] ) ) : '';
	$id   = isset( $_POST['iuc_id'] ) ? absint( $_POST['iuc_id'] ) : 0;

	$return_company = isset( $_POST['iuc_return_company'] ) ? absint( $_POST['iuc_return_company'] ) : 0;
	$notice         = 'error';

	if ( $id ) {
		if ( 'user' === $kind && 'main' === $do && $return_company ) {
			$notice = iuc_admin_set_main_contact( $return_company, $id );
		} elseif ( 'user' === $kind ) {
			$notice = iuc_admin_do_user( $id, $do );
		} elseif ( 'company' === $kind ) {
			$notice = iuc_admin_do_company( $id, $do );
		} elseif ( 'comment' === $kind ) {
			$notice = iuc_admin_do_comment( $id, $do );
		}
	}

	$args = array(
		'page'       => IUC_ADMIN_PAGE,
		'iuc_notice' => $notice,
	);

	$company_post = $return_company ? get_post( $return_company ) : null;

	if ( $company_post && 'iuc_company' === $company_post->post_type ) {
		$args['page']       = 'iuc-syd-companies';
		$args['company_id'] = $return_company;
	}

	if ( isset( $_POST['iuc_return_page'] ) && 'iuc-syd-users' === sanitize_key( wp_unslash( $_POST['iuc_return_page'] ) ) ) {
		$args['page'] = 'iuc-syd-users';
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_iuc_admin_action', 'iuc_admin_handle_action' );

/**
 * Make a user the main contact of a company (the user must belong to it).
 */
function iuc_admin_set_main_contact( $company_id, $user_id ) {
	$ids = wp_list_pluck( iuc_company_users( $company_id ), 'ID' );

	if ( ! in_array( (int) $user_id, array_map( 'intval', $ids ), true ) ) {
		return 'error';
	}

	update_post_meta( $company_id, 'iuc_main_user_id', (int) $user_id );

	return 'company_saved';
}

function iuc_admin_do_user( $user_id, $do ) {
	$user = get_userdata( $user_id );

	if ( ! $user || user_can( $user, 'manage_options' ) ) {
		return 'error';
	}

	if ( 'approve' === $do ) {
		update_user_meta( $user_id, IUC_APPROVAL_META, 'approved' );

		wp_mail(
			$user->user_email,
			'Ditt konto hos IUC Syd är godkänt',
			"Hej " . $user->display_name . ",\n\nDitt konto i IUC Syds medlemsportal har godkänts. Du kan nu logga in.\n\n" . home_url( '/' )
		);

		return 'user_approved';
	}

	if ( 'block' === $do ) {
		update_user_meta( $user_id, IUC_APPROVAL_META, 'blocked' );

		return 'user_blocked';
	}

	return 'error';
}

function iuc_admin_do_company( $post_id, $do ) {
	$post = get_post( $post_id );

	if ( ! $post || 'iuc_company' !== $post->post_type ) {
		return 'error';
	}

	if ( 'approve' === $do ) {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return 'company_approved';
	}

	if ( 'reject' === $do ) {
		wp_trash_post( $post_id );

		return 'company_rejected';
	}

	return 'error';
}

function iuc_admin_do_comment( $comment_id, $do ) {
	if ( ! get_comment( $comment_id ) ) {
		return 'error';
	}

	if ( 'keep' === $do ) {
		wp_set_comment_status( $comment_id, 'approve' );
		update_comment_meta( $comment_id, IUC_MODERATION_META, 'kept' );
		delete_comment_meta( $comment_id, IUC_REPORTED_META );

		return 'comment_kept';
	}

	if ( 'hide' === $do ) {
		wp_set_comment_status( $comment_id, 'hold' );
		update_comment_meta( $comment_id, IUC_MODERATION_META, 'hidden' );
		delete_comment_meta( $comment_id, IUC_REPORTED_META );

		return 'comment_hidden';
	}

	if ( 'delete' === $do ) {
		wp_trash_comment( $comment_id );

		return 'comment_deleted';
	}

	return 'error';
}

/* ---------------------------------------------------------------------
 * Rendering
 * ------------------------------------------------------------------ */

function iuc_admin_notice_text( $key ) {
	$messages = array(
		'user_approved'    => 'Användaren är godkänd och har fått ett välkomstmejl.',
		'user_blocked'     => 'Användaren är blockerad.',
		'company_approved' => 'Företaget är godkänt.',
		'company_rejected' => 'Företaget har avvisats och flyttats till papperskorgen.',
		'comment_kept'     => 'Kommentaren är godkänd och behålls.',
		'comment_hidden'   => 'Kommentaren är avpublicerad.',
		'comment_deleted'  => 'Kommentaren är borttagen.',
		'request_status'   => 'Förfrågans status är uppdaterad.',
		'request_deleted'  => 'Förfrågan är borttagen.',
		'user_company_saved' => 'Användarens företag är uppdaterat.',
		'company_added'    => 'Företaget är tillagt.',
		'company_saved'    => 'Företaget är sparat.',
		'company_invalid'  => 'Företaget kunde inte sparas.',
		'company_duplicate' => 'Företaget finns redan.',
		'import_done'      => 'Importen är klar.',
		'import_failed'    => 'Importen misslyckades.',
		'mock_seeded'     => 'Testdata är inlagd.',
		'mock_removed'    => 'Testdata är borttagen.',
		'error'            => 'Åtgärden kunde inte genomföras.',
	);

	return isset( $messages[ $key ] ) ? $messages[ $key ] : '';
}

/**
 * One form with one or more submit buttons.
 *
 * @param string $kind    user | company | comment
 * @param int    $id      Object ID.
 * @param array  $buttons Each: array( do, label, css class ).
	 * @param int    $return_company Company card to return to afterwards (0 = overview).
 */
function iuc_admin_action_form( $kind, $id, array $buttons, $return_company = 0, $return_page = '' ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="iuc-actions">
		<input type="hidden" name="action" value="iuc_admin_action">
		<input type="hidden" name="iuc_kind" value="<?php echo esc_attr( $kind ); ?>">
		<input type="hidden" name="iuc_id" value="<?php echo esc_attr( (string) $id ); ?>">
		<?php if ( $return_company ) : ?>
			<input type="hidden" name="iuc_return_company" value="<?php echo esc_attr( (string) $return_company ); ?>">
		<?php endif; ?>
		<?php if ( $return_page ) : ?>
			<input type="hidden" name="iuc_return_page" value="<?php echo esc_attr( $return_page ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'iuc_admin_action', 'iuc_admin_nonce' ); ?>
		<?php foreach ( $buttons as $button ) : ?>
			<button type="submit" name="iuc_do" value="<?php echo esc_attr( $button[0] ); ?>" class="iuc-btn <?php echo esc_attr( $button[2] ); ?>">
				<?php echo esc_html( $button[1] ); ?>
			</button>
		<?php endforeach; ?>
	</form>
	<?php
}

function iuc_admin_initials( $name ) {
	$parts    = preg_split( '/\s+/', trim( $name ) );
	$initials = '';

	foreach ( array_slice( $parts, 0, 2 ) as $part ) {
		$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
	}

	return $initials ? $initials : '?';
}

function iuc_admin_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$pending_users     = iuc_admin_users_with_status( 'pending' );
	$pending_companies = iuc_admin_pending_companies();
	$review_comments   = iuc_admin_comments_to_review();

	$count_users     = iuc_admin_count_users_with_status( 'pending' );
	$count_companies = iuc_admin_count_companies( 'pending' );
	$count_comments  = iuc_admin_count_comments_to_review();
	$count_requests  = iuc_admin_count_new_requests();
	$count_approved  = iuc_admin_count_companies( 'publish' );

	$notice_key = isset( $_GET['iuc_notice'] ) ? sanitize_key( wp_unslash( $_GET['iuc_notice'] ) ) : '';
	$notice     = iuc_admin_notice_text( $notice_key );

	$stats = array(
		array( 'Användare att godkänna', $count_users ),
		array( 'Företag att godkänna', $count_companies ),
		array( 'Kommentarer att granska', $count_comments ),
		array( 'Obesvarade privata förfrågningar', $count_requests ),
		array( 'Godkända företag', $count_approved ),
	);
	?>
	<div class="wrap iuc-admin">
		<header class="iuc-header">
			<h1>Översikt</h1>
			<p>Det som väntar på IUC Syd just nu</p>
		</header>

		<?php if ( $notice ) : ?>
			<div class="iuc-flash <?php echo 'error' === $notice_key ? 'iuc-flash-error' : ''; ?>" role="status">
				<?php echo esc_html( $notice ); ?>
			</div>
		<?php endif; ?>

		<div class="iuc-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<div class="iuc-card iuc-stat">
					<span class="iuc-stat-label"><?php echo esc_html( $stat[0] ); ?></span>
					<span class="iuc-stat-value"><?php echo esc_html( (string) $stat[1] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="iuc-grid">

			<section class="iuc-card" aria-labelledby="iuc-users-title">
				<div class="iuc-card-head">
					<h2 id="iuc-users-title">Användare att godkänna</h2>
					<span>Nya konton har ingen åtkomst förrän IUC Syd godkänner</span>
				</div>

				<?php if ( ! $pending_users ) : ?>
					<p class="iuc-empty">Inga användare väntar på godkännande.</p>
				<?php endif; ?>

				<?php foreach ( $pending_users as $user ) : ?>
					<div class="iuc-row">
						<div class="iuc-person">
							<span class="iuc-avatar" aria-hidden="true"><?php echo esc_html( iuc_admin_initials( $user->display_name ) ); ?></span>
							<div>
								<strong><?php echo esc_html( $user->display_name ); ?></strong>
								<span class="iuc-meta">
									<?php
									$company = function_exists( 'iuc_get_request_company' )
										? iuc_get_request_company( $user->ID )
										: '';
									echo esc_html( $company ? $company : 'Företag saknas' );
									?>
									· registrerad <?php echo esc_html( date_i18n( 'j M Y', strtotime( $user->user_registered . ' UTC' ) ) ); ?>
								</span>
							</div>
						</div>
						<?php
						iuc_admin_action_form(
							'user',
							$user->ID,
							array(
								array( 'approve', 'Godkänn', 'iuc-btn-primary' ),
								array( 'block', 'Blockera', 'iuc-btn-ghost' ),
							)
						);
						?>
					</div>
				<?php endforeach; ?>

				<?php if ( $count_users > count( $pending_users ) ) : ?>
					<p class="iuc-meta">Visar <?php echo esc_html( (string) count( $pending_users ) ); ?> av <?php echo esc_html( (string) $count_users ); ?>.</p>
				<?php endif; ?>
			</section>

			<section class="iuc-card" aria-labelledby="iuc-companies-title">
				<div class="iuc-card-head">
					<h2 id="iuc-companies-title">Företag att godkänna</h2>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=iuc-syd-company-new' ) ); ?>" class="iuc-btn iuc-btn-primary">Nytt företag</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=iuc-syd-companies' ) ); ?>" class="iuc-btn iuc-btn-ghost">Alla företag</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=iuc-syd-company-import' ) ); ?>" class="iuc-btn iuc-btn-ghost">Importera</a>
				</div>

				<?php if ( ! $pending_companies ) : ?>
					<p class="iuc-empty">Inga företag väntar på godkännande.</p>
				<?php endif; ?>

				<?php foreach ( $pending_companies as $company_post ) : ?>
					<div class="iuc-row">
						<div class="iuc-person">
							<span class="iuc-avatar" aria-hidden="true"><?php echo esc_html( iuc_admin_initials( $company_post->post_title ) ); ?></span>
							<div>
								<strong><a href="<?php echo esc_url( iuc_company_card_url( $company_post->ID ) ); ?>"><?php echo esc_html( get_the_title( $company_post ) ); ?></a></strong>
								<span class="iuc-meta">Skapad <?php echo esc_html( date_i18n( 'j M Y', strtotime( $company_post->post_date_gmt . ' UTC' ) ) ); ?></span>
							</div>
						</div>
						<?php
						iuc_admin_action_form(
							'company',
							$company_post->ID,
							array(
								array( 'approve', 'Godkänn', 'iuc-btn-primary' ),
								array( 'reject', 'Avvisa', 'iuc-btn-ghost' ),
							)
						);
						?>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="iuc-card iuc-card-wide" aria-labelledby="iuc-mod-title">
				<div class="iuc-card-head">
					<h2 id="iuc-mod-title">Moderering av community</h2>
					<span>Kommentarer som anmälts eller väntar på granskning</span>
				</div>

				<?php if ( ! $review_comments ) : ?>
					<p class="iuc-empty">Inget att moderera just nu.</p>
				<?php endif; ?>

				<?php foreach ( $review_comments as $comment ) : ?>
					<?php $reported = (bool) get_comment_meta( $comment->comment_ID, IUC_REPORTED_META, true ); ?>
					<div class="iuc-mod">
						<div class="iuc-mod-top">
							<span class="iuc-badge <?php echo $reported ? 'iuc-badge-red' : 'iuc-badge-amber'; ?>">
								<?php echo $reported ? 'Anmäld av medlem' : 'Väntar på granskning'; ?>
							</span>
							<span class="iuc-meta">
								Av <?php echo esc_html( $comment->comment_author ); ?>
								<?php if ( $comment->comment_post_ID ) : ?>
									· <?php echo esc_html( get_the_title( (int) $comment->comment_post_ID ) ); ?>
								<?php endif; ?>
							</span>
						</div>
						<p><?php echo esc_html( wp_trim_words( $comment->comment_content, 40 ) ); ?></p>
						<?php
						iuc_admin_action_form(
							'comment',
							(int) $comment->comment_ID,
							array(
								array( 'keep', $reported ? 'Behåll' : 'Godkänn', 'iuc-btn-primary' ),
								array( 'hide', 'Avpublicera', 'iuc-btn-ghost' ),
								array( 'delete', 'Ta bort', 'iuc-btn-danger' ),
							)
						);
						?>
					</div>
				<?php endforeach; ?>
			</section>

		</div>

		<?php do_action( 'iuc_admin_after_dashboard' ); ?>
	</div>
	<?php
}
