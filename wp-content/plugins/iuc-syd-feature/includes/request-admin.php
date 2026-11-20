<?php
/**
 * Private requests: list with search/filters and a request card,
 * in the same layout as Companies and Users.
 *
 *   admin.php?page=iuc-syd-requests                 -> list
 *   admin.php?page=iuc-syd-requests&request_id=123  -> request card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IUC_REQUESTS_PAGE = 'iuc-syd-requests';

function iuc_request_url( $request_id = 0, array $args = array() ) {
	$base = array( 'page' => IUC_REQUESTS_PAGE );

	if ( $request_id ) {
		$base['request_id'] = (int) $request_id;
	}

	return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
}

function iuc_request_menu() {
	add_submenu_page( IUC_ADMIN_PAGE, 'Privata förfrågningar', 'Förfrågningar', 'manage_options', IUC_REQUESTS_PAGE, 'iuc_request_render' );
}
add_action( 'admin_menu', 'iuc_request_menu', 16 );

function iuc_request_status( $post_id ) {
	$status = (string) get_post_meta( $post_id, 'iuc_request_status', true );

	return array_key_exists( $status, iuc_request_status_labels() ) ? $status : 'new';
}

function iuc_request_badge( $status ) {
	$labels = iuc_request_status_labels();
	$class  = 'new' === $status ? 'iuc-badge iuc-badge-amber' : ( 'in_progress' === $status ? 'iuc-badge iuc-badge-blue' : 'iuc-badge' );

	return array( isset( $labels[ $status ] ) ? $labels[ $status ] : 'Ny', $class );
}

function iuc_request_company_name( $post_id ) {
	$company_id = (int) get_post_meta( $post_id, 'iuc_request_company_id', true );

	if ( $company_id && 'iuc_company' === get_post_type( $company_id ) ) {
		return get_the_title( $company_id );
	}

	return (string) get_post_meta( $post_id, 'iuc_request_company', true );
}

/* ---------------------------------------------------------------------
 * Actions
 * ------------------------------------------------------------------ */

function iuc_request_handle_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	$id = isset( $_POST['iuc_request_id'] ) ? absint( $_POST['iuc_request_id'] ) : 0;

	check_admin_referer( 'iuc_request_action_' . $id, 'iuc_nonce' );

	$do     = isset( $_POST['iuc_do'] ) ? sanitize_key( wp_unslash( $_POST['iuc_do'] ) ) : '';
	$notice = 'error';
	$url    = iuc_request_url();

	if ( $id && 'iuc_private_request' === get_post_type( $id ) ) {
		if ( array_key_exists( $do, iuc_request_status_labels() ) ) {
			update_post_meta( $id, 'iuc_request_status', $do );
			$notice = 'request_status';
			$url    = iuc_request_url( $id );
		} elseif ( 'delete' === $do ) {
			wp_trash_post( $id );
			$notice = 'request_deleted';
		}
	}

	wp_safe_redirect( add_query_arg( 'iuc_notice', $notice, $url ) );
	exit;
}
add_action( 'admin_post_iuc_request_action', 'iuc_request_handle_action' );

function iuc_request_action_form( $id, array $buttons ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="iuc-actions">
		<input type="hidden" name="action" value="iuc_request_action">
		<input type="hidden" name="iuc_request_id" value="<?php echo esc_attr( (string) $id ); ?>">
		<?php wp_nonce_field( 'iuc_request_action_' . $id, 'iuc_nonce' ); ?>
		<?php foreach ( $buttons as $button ) : ?>
			<button type="submit" name="iuc_do" value="<?php echo esc_attr( $button[0] ); ?>" class="iuc-btn <?php echo esc_attr( $button[2] ); ?>" <?php echo 'delete' === $button[0] ? 'onclick="return confirm(\'Ta bort förfrågan?\');"' : ''; ?>>
				<?php echo esc_html( $button[1] ); ?>
			</button>
		<?php endforeach; ?>
	</form>
	<?php
}

/* ---------------------------------------------------------------------
 * Pages
 * ------------------------------------------------------------------ */

function iuc_request_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	$id   = isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;

	if ( $post && 'iuc_private_request' === $post->post_type && 'trash' !== $post->post_status ) {
		iuc_request_render_card( $post );
		return;
	}

	iuc_request_render_list();
}

function iuc_request_render_list() {
	$q       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
	$company = isset( $_GET['company'] ) ? absint( $_GET['company'] ) : 0;
	$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

	$all = get_posts(
		array(
			'post_type'      => 'iuc_private_request',
			'post_status'    => 'any',
			'posts_per_page' => 1000,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	$companies = array();
	$statuses  = iuc_request_status_labels();
	$types     = iuc_request_type_labels();
	$needle    = mb_strtolower( $q );
	$filtered  = array();

	foreach ( $all as $post ) {
		$cid = (int) get_post_meta( $post->ID, 'iuc_request_company_id', true );

		if ( $cid ) {
			$companies[ $cid ] = get_the_title( $cid );
		}

		if ( in_array( $status, array_keys( $statuses ), true ) && iuc_request_status( $post->ID ) !== $status ) {
			continue;
		}

		if ( '' !== $type && (string) get_post_meta( $post->ID, 'iuc_request_type', true ) !== $type ) {
			continue;
		}

		if ( $company && $cid !== $company ) {
			continue;
		}

		$hay = mb_strtolower( $post->post_title . ' ' . $post->post_content . ' ' . iuc_request_company_name( $post->ID ) . ' ' . get_the_author_meta( 'display_name', (int) $post->post_author ) );

		if ( '' !== $needle && false === mb_strpos( $hay, $needle ) ) {
			continue;
		}

		$filtered[] = $post;
	}

	natcasesort( $companies );

	$total = count( $filtered );
	$pages = max( 1, (int) ceil( $total / IUC_LIST_PER_PAGE ) );
	$paged = min( $paged, $pages );
	$slice = array_slice( $filtered, ( $paged - 1 ) * IUC_LIST_PER_PAGE, IUC_LIST_PER_PAGE );

	$filter_args = array_filter(
		array(
			's'       => $q,
			'status'  => $status,
			'type'    => $type,
			'company' => $company ? (string) $company : '',
		),
		'strlen'
	);
	?>
	<div class="wrap iuc-admin">
		<header class="iuc-header">
			<h1>Förfrågningar</h1>
			<p>Privata förfrågningar från medlemmar. Bara IUC Syd ser dem. Klicka på en förfrågan för att läsa och svara.</p>
		</header>

		<?php iuc_company_render_notice(); ?>

		<form method="get" class="iuc-card iuc-filters" role="search" aria-label="Sök och filtrera förfrågningar">
			<input type="hidden" name="page" value="<?php echo esc_attr( IUC_REQUESTS_PAGE ); ?>">

			<div class="iuc-field iuc-field-grow">
				<label for="iuc-r-s">Sök</label>
				<input id="iuc-r-s" type="search" name="s" value="<?php echo esc_attr( $q ); ?>" placeholder="Ämne, text, företag eller avsändare…">
			</div>

			<div class="iuc-field">
				<label for="iuc-r-status">Status</label>
				<select id="iuc-r-status" name="status">
					<option value="">Alla</option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="iuc-field">
				<label for="iuc-r-type">Typ</label>
				<select id="iuc-r-type" name="type">
					<option value="">Alla typer</option>
					<?php foreach ( $types as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="iuc-field">
				<label for="iuc-r-company">Företag</label>
				<select id="iuc-r-company" name="company">
					<option value="">Alla företag</option>
					<?php foreach ( $companies as $cid => $name ) : ?>
						<option value="<?php echo esc_attr( (string) $cid ); ?>" <?php selected( $company, $cid ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="iuc-actions iuc-filter-actions">
				<button type="submit" class="iuc-btn iuc-btn-primary">Filtrera</button>
				<?php if ( $filter_args ) : ?>
					<a class="iuc-btn iuc-btn-ghost" href="<?php echo esc_url( iuc_request_url() ); ?>">Rensa</a>
				<?php endif; ?>
			</div>
		</form>

		<p class="iuc-count" role="status"><?php echo esc_html( 1 === $total ? '1 förfrågan' : $total . ' förfrågningar' ); ?></p>

		<?php if ( ! $slice ) : ?>
			<div class="iuc-card iuc-empty-card">
				<strong>Inga förfrågningar</strong>
				<span>Förfrågningar som medlemmar skickar dyker upp här.</span>
			</div>
		<?php else : ?>
			<div class="iuc-list iuc-requests-list" role="list">
				<div class="iuc-list-head" aria-hidden="true">
					<span>Ämne</span><span>Företag</span><span>Typ</span><span>Datum</span><span>Status</span>
				</div>
				<?php
				foreach ( $slice as $post ) :
					$badge = iuc_request_badge( iuc_request_status( $post->ID ) );
					$rtype = (string) get_post_meta( $post->ID, 'iuc_request_type', true );
					?>
					<a class="iuc-list-item" role="listitem" href="<?php echo esc_url( iuc_request_url( $post->ID ) ); ?>">
						<span class="iuc-list-name">
							<span class="iuc-avatar" aria-hidden="true"><?php echo esc_html( iuc_admin_initials( get_the_author_meta( 'display_name', (int) $post->post_author ) ) ); ?></span>
							<span>
								<strong><?php echo esc_html( $post->post_title ); ?></strong><br>
								<small><?php echo esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) ); ?></small>
							</span>
						</span>
						<span data-label="Företag"><?php echo esc_html( iuc_request_company_name( $post->ID ) ?: '–' ); ?></span>
						<span data-label="Typ"><?php echo esc_html( isset( $types[ $rtype ] ) ? $types[ $rtype ] : '–' ); ?></span>
						<span data-label="Datum"><?php echo esc_html( date_i18n( 'j M Y', strtotime( $post->post_date_gmt . ' UTC' ) ) ); ?></span>
						<span data-label="Status"><span class="<?php echo esc_attr( $badge[1] ); ?>"><?php echo esc_html( $badge[0] ); ?></span></span>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( $pages > 1 ) : ?>
				<nav class="iuc-pager" aria-label="Sidor">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
						<a class="iuc-btn <?php echo $i === $paged ? 'iuc-btn-primary' : 'iuc-btn-ghost'; ?>" href="<?php echo esc_url( iuc_request_url( 0, array_merge( $filter_args, array( 'paged' => $i ) ) ) ); ?>" <?php echo $i === $paged ? 'aria-current="page"' : ''; ?>><?php echo esc_html( (string) $i ); ?></a>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

function iuc_request_render_card( $post ) {
	$id        = $post->ID;
	$status    = iuc_request_status( $id );
	$badge     = iuc_request_badge( $status );
	$types     = iuc_request_type_labels();
	$rtype     = (string) get_post_meta( $id, 'iuc_request_type', true );
	$author    = get_userdata( (int) $post->post_author );
	$company   = (int) get_post_meta( $id, 'iuc_request_company_id', true );
	$job_title = $author ? (string) get_user_meta( $author->ID, 'iuc_job_title', true ) : '';
	$phone     = $author ? (string) get_user_meta( $author->ID, 'iuc_phone', true ) : '';
	$when      = date_i18n( 'j M Y H:i', strtotime( $post->post_date_gmt . ' UTC' ) + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
	$subject   = rawurlencode( 'Svar: ' . $post->post_title );
	?>
	<div class="wrap iuc-admin">
		<p class="iuc-back"><a href="<?php echo esc_url( iuc_request_url() ); ?>">&larr; Alla förfrågningar</a></p>

		<?php iuc_company_render_notice(); ?>

		<section class="iuc-card iuc-company-head" aria-labelledby="iuc-request-title">
			<div class="iuc-company-title">
				<span class="iuc-avatar iuc-avatar-lg" aria-hidden="true"><?php echo esc_html( iuc_admin_initials( $author ? $author->display_name : '?' ) ); ?></span>
				<div>
					<h1 id="iuc-request-title"><?php echo esc_html( $post->post_title ); ?></h1>
					<span class="iuc-meta">
						<?php echo esc_html( isset( $types[ $rtype ] ) ? $types[ $rtype ] : 'Förfrågan' ); ?> · <?php echo esc_html( $when ); ?>
					</span>
				</div>
			</div>
			<div class="iuc-actions">
				<span class="<?php echo esc_attr( $badge[1] ); ?>"><?php echo esc_html( $badge[0] ); ?></span>
				<?php if ( $author ) : ?>
					<a class="iuc-btn iuc-btn-primary" href="mailto:<?php echo esc_attr( $author->user_email ); ?>?subject=<?php echo esc_attr( $subject ); ?>">Svara via e-post</a>
				<?php endif; ?>
			</div>
		</section>

		<div class="iuc-cols">
			<div class="iuc-col">
				<section class="iuc-card" aria-labelledby="iuc-msg-title">
					<div class="iuc-card-head"><h2 id="iuc-msg-title">Meddelande</h2></div>
					<div class="iuc-message"><?php echo wp_kses_post( wpautop( esc_html( $post->post_content ) ) ); ?></div>
				</section>
			</div>

			<div class="iuc-col">
				<section class="iuc-card" aria-labelledby="iuc-from-title">
					<div class="iuc-card-head"><h2 id="iuc-from-title">Från</h2></div>
					<dl class="iuc-dl">
						<div><dt>Namn</dt><dd><?php echo esc_html( $author ? $author->display_name : '–' ); ?></dd></div>
						<div><dt>Titel</dt><dd><?php echo esc_html( $job_title ?: '–' ); ?></dd></div>
						<div><dt>E-post</dt><dd><?php echo $author ? '<a href="mailto:' . esc_attr( $author->user_email ) . '">' . esc_html( $author->user_email ) . '</a>' : '–'; ?></dd></div>
						<div><dt>Telefon</dt><dd><?php echo esc_html( $phone ?: '–' ); ?></dd></div>
						<div><dt>Företag</dt><dd>
							<?php if ( $company && function_exists( 'iuc_company_card_url' ) ) : ?>
								<a href="<?php echo esc_url( iuc_company_card_url( $company ) ); ?>"><?php echo esc_html( get_the_title( $company ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( iuc_request_company_name( $id ) ?: '–' ); ?>
							<?php endif; ?>
						</dd></div>
					</dl>
				</section>

				<section class="iuc-card" aria-labelledby="iuc-status-title">
					<div class="iuc-card-head"><h2 id="iuc-status-title">Status</h2><span>Bara IUC Syd ser detta</span></div>
					<?php
					$buttons = array();

					foreach ( iuc_request_status_labels() as $key => $label ) {
						if ( $key !== $status ) {
							$buttons[] = array( $key, 'Markera som ' . mb_strtolower( $label ), 'new' === $key || 'in_progress' === $key ? 'iuc-btn-ghost' : 'iuc-btn-primary' );
						}
					}

					iuc_request_action_form( $id, $buttons );
					?>
					<div class="iuc-danger-zone">
						<?php iuc_request_action_form( $id, array( array( 'delete', 'Ta bort förfrågan', 'iuc-btn-danger' ) ) ); ?>
					</div>
				</section>
			</div>
		</div>
	</div>
	<?php
}
