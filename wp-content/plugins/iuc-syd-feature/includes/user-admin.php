<?php
/**
 * Users: one list under IUC Syd where administrators handle members,
 * change their company and approve or block them - without going into
 * BuddyPress or the WordPress user screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IUC_USERS_PAGE = 'iuc-syd-users';

/* ---------------------------------------------------------------------
 * Menu: everything lives under "IUC Syd"
 * ------------------------------------------------------------------ */

function iuc_user_admin_menu() {
	add_submenu_page( IUC_ADMIN_PAGE, 'Användare', 'Användare', 'manage_options', IUC_USERS_PAGE, 'iuc_user_admin_render' );
}
add_action( 'admin_menu', 'iuc_user_admin_menu', 16 );

function iuc_user_admin_url( array $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => IUC_USERS_PAGE ), $args ), admin_url( 'admin.php' ) );
}

/* ---------------------------------------------------------------------
 * Change a member's company (also updates the BuddyPress field)
 * ------------------------------------------------------------------ */

function iuc_admin_set_user_company( $user_id, $company_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user || user_can( $user, 'manage_options' ) ) {
		return false;
	}

	$old_id = (int) get_user_meta( $user_id, 'iuc_company_id', true );

	if ( $company_id && 'iuc_company' !== get_post_type( $company_id ) ) {
		return false;
	}

	if ( $old_id && $old_id !== (int) $company_id && (int) get_post_meta( $old_id, 'iuc_main_user_id', true ) === (int) $user_id ) {
		delete_post_meta( $old_id, 'iuc_main_user_id' );
	}

	$field_id = function_exists( 'iuc_bp_company_field_id' ) ? iuc_bp_company_field_id() : 0;

	if ( ! $company_id ) {
		delete_user_meta( $user_id, 'iuc_company_id' );
		delete_user_meta( $user_id, 'iuc_company' );

		if ( $field_id && function_exists( 'xprofile_delete_field_data' ) ) {
			xprofile_delete_field_data( $field_id, $user_id );
		}

		return true;
	}

	$title = get_the_title( $company_id );

	update_user_meta( $user_id, 'iuc_company_id', (int) $company_id );
	update_user_meta( $user_id, 'iuc_company', $title );

	if ( $field_id && 'publish' === get_post_status( $company_id ) && function_exists( 'xprofile_set_field_data' ) ) {
		xprofile_set_field_data( $field_id, $user_id, $title );
	}

	return true;
}

function iuc_user_admin_handle_company() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	$user_id = isset( $_POST['iuc_user_id'] ) ? absint( $_POST['iuc_user_id'] ) : 0;

	check_admin_referer( 'iuc_user_company_' . $user_id, 'iuc_nonce' );

	$company_id = isset( $_POST['iuc_company_id'] ) ? absint( $_POST['iuc_company_id'] ) : 0;
	$ok         = $user_id && iuc_admin_set_user_company( $user_id, $company_id );

	$return = isset( $_POST['iuc_return_company'] ) ? absint( $_POST['iuc_return_company'] ) : 0;
	$notice = $ok ? 'user_company_saved' : 'error';

	if ( $return && 'iuc_company' === get_post_type( $return ) ) {
		wp_safe_redirect( add_query_arg( 'iuc_notice', $notice, iuc_company_card_url( $return ) ) );
		exit;
	}

	$args = array( 'iuc_notice' => $notice );

	foreach ( array( 's', 'status', 'company', 'paged' ) as $key ) {
		if ( ! empty( $_POST[ 'iuc_keep_' . $key ] ) ) {
			$args[ $key ] = sanitize_text_field( wp_unslash( $_POST[ 'iuc_keep_' . $key ] ) );
		}
	}

	wp_safe_redirect( iuc_user_admin_url( $args ) );
	exit;
}
add_action( 'admin_post_iuc_user_company', 'iuc_user_admin_handle_company' );

/* ---------------------------------------------------------------------
 * Page
 * ------------------------------------------------------------------ */

function iuc_user_admin_companies() {
	return get_posts(
		array(
			'post_type'      => 'iuc_company',
			'post_status'    => array( 'publish', 'pending' ),
			'posts_per_page' => 1000,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
}

function iuc_user_admin_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	$q       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$company = isset( $_GET['company'] ) ? sanitize_text_field( wp_unslash( $_GET['company'] ) ) : '';
	$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

	$companies = iuc_user_admin_companies();
	$users     = get_users(
		array(
			'role__not_in' => array( 'administrator' ),
			'orderby'      => 'display_name',
			'order'        => 'ASC',
			'number'       => 2000,
		)
	);

	$needle   = mb_strtolower( $q );
	$filtered = array();

	foreach ( $users as $user ) {
		$state = (string) get_user_meta( $user->ID, IUC_APPROVAL_META, true );
		$state = $state ? $state : 'approved';
		$cid   = iuc_company_id_for_user( $user->ID );

		if ( in_array( $status, array( 'pending', 'approved', 'blocked' ), true ) && $state !== $status ) {
			continue;
		}

		if ( 'none' === $company && $cid ) {
			continue;
		}

		if ( '' !== $company && 'none' !== $company && (int) $company !== $cid ) {
			continue;
		}

		$hay = mb_strtolower( $user->display_name . ' ' . $user->user_email . ' ' . $user->user_login . ' ' . get_user_meta( $user->ID, 'iuc_job_title', true ) . ' ' . ( $cid ? get_the_title( $cid ) : '' ) );

		if ( '' !== $needle && false === mb_strpos( $hay, $needle ) ) {
			continue;
		}

		$filtered[] = array( $user, $cid );
	}

	$total = count( $filtered );
	$pages = max( 1, (int) ceil( $total / IUC_LIST_PER_PAGE ) );
	$paged = min( $paged, $pages );
	$slice = array_slice( $filtered, ( $paged - 1 ) * IUC_LIST_PER_PAGE, IUC_LIST_PER_PAGE );

	$filter_args = array_filter(
		array(
			's'       => $q,
			'status'  => $status,
			'company' => $company,
		),
		'strlen'
	);
	?>
	<div class="wrap iuc-admin">
		<header class="iuc-header">
			<h1>Användare</h1>
			<p>Alla medlemmar. Här godkänner du, blockerar och flyttar användare mellan företag – företagsvalet i profilen uppdateras automatiskt.</p>
		</header>

		<?php iuc_company_render_notice(); ?>

		<form method="get" class="iuc-card iuc-filters" role="search" aria-label="Sök och filtrera användare">
			<input type="hidden" name="page" value="<?php echo esc_attr( IUC_USERS_PAGE ); ?>">

			<div class="iuc-field iuc-field-grow">
				<label for="iuc-u-s">Sök</label>
				<input id="iuc-u-s" type="search" name="s" value="<?php echo esc_attr( $q ); ?>" placeholder="Namn, e-post, titel eller företag…">
			</div>

			<div class="iuc-field">
				<label for="iuc-u-status">Status</label>
				<select id="iuc-u-status" name="status">
					<option value="">Alla</option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>>Väntar på godkännande</option>
					<option value="approved" <?php selected( $status, 'approved' ); ?>>Godkända</option>
					<option value="blocked" <?php selected( $status, 'blocked' ); ?>>Blockerade</option>
				</select>
			</div>

			<div class="iuc-field">
				<label for="iuc-u-company">Företag</label>
				<select id="iuc-u-company" name="company">
					<option value="">Alla företag</option>
					<option value="none" <?php selected( $company, 'none' ); ?>>Utan företag</option>
					<?php foreach ( $companies as $c ) : ?>
						<option value="<?php echo esc_attr( (string) $c->ID ); ?>" <?php selected( $company, (string) $c->ID ); ?>><?php echo esc_html( $c->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="iuc-actions iuc-filter-actions">
				<button type="submit" class="iuc-btn iuc-btn-primary">Filtrera</button>
				<?php if ( $filter_args ) : ?>
					<a class="iuc-btn iuc-btn-ghost" href="<?php echo esc_url( iuc_user_admin_url() ); ?>">Rensa</a>
				<?php endif; ?>
			</div>
		</form>

		<p class="iuc-count" role="status"><?php echo esc_html( 1 === $total ? '1 användare' : $total . ' användare' ); ?></p>

		<?php if ( ! $slice ) : ?>
			<div class="iuc-card iuc-empty-card">
				<strong>Inga användare matchar</strong>
				<span>Prova en annan sökning eller ett annat filter.</span>
			</div>
		<?php else : ?>
			<div class="iuc-list iuc-users-list" role="list">
				<div class="iuc-list-head" aria-hidden="true">
					<span>Användare</span><span>Titel</span><span>Företag</span><span>Status</span><span>Åtgärd</span>
				</div>
				<?php
				foreach ( $slice as $row ) :
					list( $user, $cid ) = $row;
					$badge = iuc_user_status_badge( $user->ID );
					$state = (string) get_user_meta( $user->ID, IUC_APPROVAL_META, true );
					$state = $state ? $state : 'approved';
					?>
					<div class="iuc-list-item iuc-user-row" role="listitem">
						<span class="iuc-list-name">
							<span class="iuc-avatar" aria-hidden="true"><?php echo esc_html( iuc_admin_initials( $user->display_name ) ); ?></span>
							<span>
								<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
								<small><?php echo esc_html( $user->user_email ); ?></small>
							</span>
						</span>
						<span data-label="Titel"><?php echo esc_html( get_user_meta( $user->ID, 'iuc_job_title', true ) ?: '–' ); ?></span>
						<span data-label="Företag">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="iuc-inline-form">
								<input type="hidden" name="action" value="iuc_user_company">
								<input type="hidden" name="iuc_user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>">
								<?php foreach ( array( 's' => $q, 'status' => $status, 'company' => $company, 'paged' => $paged > 1 ? $paged : '' ) as $k => $v ) : ?>
									<?php if ( '' !== (string) $v ) : ?>
										<input type="hidden" name="iuc_keep_<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
									<?php endif; ?>
								<?php endforeach; ?>
								<?php wp_nonce_field( 'iuc_user_company_' . $user->ID, 'iuc_nonce' ); ?>
								<label class="screen-reader-text" for="iuc-co-<?php echo esc_attr( (string) $user->ID ); ?>">Företag för <?php echo esc_attr( $user->display_name ); ?></label>
								<select id="iuc-co-<?php echo esc_attr( (string) $user->ID ); ?>" name="iuc_company_id">
									<option value="0">Inget företag</option>
									<?php foreach ( $companies as $c ) : ?>
										<option value="<?php echo esc_attr( (string) $c->ID ); ?>" <?php selected( $cid, $c->ID ); ?>><?php echo esc_html( $c->post_title . ( 'publish' === $c->post_status ? '' : ' (väntar)' ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="iuc-btn iuc-btn-ghost">Spara</button>
							</form>
						</span>
						<span data-label="Status"><span class="<?php echo esc_attr( $badge[1] ); ?>"><?php echo esc_html( $badge[0] ); ?></span></span>
						<span data-label="Åtgärd">
							<?php
							if ( 'approved' === $state ) {
								iuc_admin_action_form( 'user', $user->ID, array( array( 'block', 'Blockera', 'iuc-btn-danger' ) ), 0, IUC_USERS_PAGE );
							} elseif ( 'blocked' === $state ) {
								iuc_admin_action_form( 'user', $user->ID, array( array( 'approve', 'Aktivera', 'iuc-btn-primary' ) ), 0, IUC_USERS_PAGE );
							} else {
								iuc_admin_action_form(
									'user',
									$user->ID,
									array(
										array( 'approve', 'Godkänn', 'iuc-btn-primary' ),
										array( 'block', 'Blockera', 'iuc-btn-danger' ),
									),
									0,
									IUC_USERS_PAGE
								);
							}
							?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $pages > 1 ) : ?>
				<nav class="iuc-pager" aria-label="Sidor">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
						<a class="iuc-btn <?php echo $i === $paged ? 'iuc-btn-primary' : 'iuc-btn-ghost'; ?>" href="<?php echo esc_url( iuc_user_admin_url( array_merge( $filter_args, array( 'paged' => $i ) ) ) ); ?>" <?php echo $i === $paged ? 'aria-current="page"' : ''; ?>><?php echo esc_html( (string) $i ); ?></a>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------------------------------------------------------------------
 * BuddyPress admin: point administrators to the IUC Syd screens
 * ------------------------------------------------------------------ */

function iuc_user_admin_bp_notice() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( 'bp-profile-edit' !== $page ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>Företag, godkännande och blockering hanteras under <a href="%s">IUC Syd → Användare</a>.</p></div>',
		esc_url( iuc_user_admin_url() )
	);
}
add_action( 'admin_notices', 'iuc_user_admin_bp_notice' );
