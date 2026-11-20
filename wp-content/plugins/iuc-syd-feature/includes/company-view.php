<?php
/**
 * Companies: list with search and filters, company card, and editing.
 *
 * One admin page, two views:
 *   admin.php?page=iuc-syd-companies                 -> list
 *   admin.php?page=iuc-syd-companies&company_id=123  -> company card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IUC_COMPANIES_PAGE = 'iuc-syd-companies';
const IUC_LIST_PER_PAGE  = 20;

/* ---------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */

function iuc_company_card_url( $company_id ) {
	return add_query_arg(
		array(
			'page'       => IUC_COMPANIES_PAGE,
			'company_id' => (int) $company_id,
		),
		admin_url( 'admin.php' )
	);
}

function iuc_company_list_url( array $args = array() ) {
	return add_query_arg(
		array_merge( array( 'page' => IUC_COMPANIES_PAGE ), $args ),
		admin_url( 'admin.php' )
	);
}

/**
 * Users that belong to a company (linked by ID, or by company name for older users).
 */
function iuc_company_users( $company_id ) {
	return get_users(
		array(
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'   => 'iuc_company_id',
					'value' => (string) (int) $company_id,
				),
				array(
					'key'   => 'iuc_company',
					'value' => get_the_title( $company_id ),
				),
			),
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		)
	);
}

function iuc_company_main_user( $company_id, array $users ) {
	$main_id = (int) get_post_meta( $company_id, 'iuc_main_user_id', true );

	foreach ( $users as $user ) {
		if ( (int) $user->ID === $main_id ) {
			return $user;
		}
	}

	return null;
}

function iuc_company_meta( $company_id, $key ) {
	return (string) get_post_meta( $company_id, $key, true );
}

function iuc_company_status_badge( $post ) {
	return 'publish' === $post->post_status
		? array( 'Godkänd', 'iuc-badge' )
		: array( 'Väntar på godkännande', 'iuc-badge iuc-badge-amber' );
}

function iuc_user_status_badge( $user_id ) {
	$status = get_user_meta( $user_id, IUC_APPROVAL_META, true );

	if ( 'pending' === $status ) {
		return array( 'Väntar på godkännande', 'iuc-badge iuc-badge-amber' );
	}

	if ( 'blocked' === $status ) {
		return array( 'Blockerad', 'iuc-badge iuc-badge-red' );
	}

	return array( 'Godkänd', 'iuc-badge' );
}

/* ---------------------------------------------------------------------
 * Menu
 * ------------------------------------------------------------------ */

function iuc_company_view_menu() {
	add_submenu_page(
		IUC_ADMIN_PAGE,
		'Företag',
		'Företag',
		'manage_options',
		IUC_COMPANIES_PAGE,
		'iuc_company_view_render'
	);
}
add_action( 'admin_menu', 'iuc_company_view_menu', 15 );

function iuc_company_view_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$company_id = isset( $_GET['company_id'] ) ? absint( $_GET['company_id'] ) : 0;
	$company    = $company_id ? get_post( $company_id ) : null;

	if ( $company && 'iuc_company' === $company->post_type && in_array( $company->post_status, array( 'publish', 'pending', 'draft', 'private' ), true ) ) {
		iuc_company_render_card( $company );
		return;
	}

	iuc_company_render_list();
}

/* ---------------------------------------------------------------------
 * List with search and filters
 * ------------------------------------------------------------------ */

function iuc_company_list_haystack( $post ) {
	$needs = get_post_meta( $post->ID, 'iuc_needs', true );
	$text  = array(
		$post->post_title,
		iuc_company_meta( $post->ID, 'iuc_org_nr' ),
		iuc_company_meta( $post->ID, 'iuc_kommun' ),
		iuc_company_meta( $post->ID, 'iuc_city' ),
		iuc_company_meta( $post->ID, 'iuc_industry' ),
		iuc_company_meta( $post->ID, 'iuc_contact_name' ),
		iuc_company_meta( $post->ID, 'iuc_contact_email' ),
	);

	if ( is_array( $needs ) ) {
		foreach ( $needs as $need ) {
			$text[] = ( $need['text'] ?? '' ) . ' ' . ( $need['amne'] ?? '' );
		}
	}

	return mb_strtolower( implode( ' ', $text ) );
}

function iuc_company_render_list() {
	$q       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$kommun  = isset( $_GET['kommun'] ) ? sanitize_text_field( wp_unslash( $_GET['kommun'] ) ) : '';
	$bransch = isset( $_GET['bransch'] ) ? sanitize_text_field( wp_unslash( $_GET['bransch'] ) ) : '';
	$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

	$all = get_posts(
		array(
			'post_type'      => 'iuc_company',
			'post_status'    => array( 'publish', 'pending' ),
			'posts_per_page' => 1000,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$kommuner = array();
	$branscher = array();

	foreach ( $all as $post ) {
		$k = iuc_company_meta( $post->ID, 'iuc_kommun' );
		$b = iuc_company_meta( $post->ID, 'iuc_industry' );

		if ( '' !== $k ) {
			$kommuner[ $k ] = $k;
		}

		if ( '' !== $b ) {
			$branscher[ $b ] = $b;
		}
	}

	natcasesort( $kommuner );
	natcasesort( $branscher );

	$needle   = mb_strtolower( $q );
	$filtered = array_values(
		array_filter(
			$all,
			static function ( $post ) use ( $needle, $kommun, $bransch, $status ) {
				if ( in_array( $status, array( 'publish', 'pending' ), true ) && $post->post_status !== $status ) {
					return false;
				}

				if ( '' !== $kommun && iuc_company_meta( $post->ID, 'iuc_kommun' ) !== $kommun ) {
					return false;
				}

				if ( '' !== $bransch && iuc_company_meta( $post->ID, 'iuc_industry' ) !== $bransch ) {
					return false;
				}

				return '' === $needle || false !== mb_strpos( iuc_company_list_haystack( $post ), $needle );
			}
		)
	);

	$total = count( $filtered );
	$pages = max( 1, (int) ceil( $total / IUC_LIST_PER_PAGE ) );
	$paged = min( $paged, $pages );
	$slice = array_slice( $filtered, ( $paged - 1 ) * IUC_LIST_PER_PAGE, IUC_LIST_PER_PAGE );

	$filter_args = array_filter(
		array(
			's'       => $q,
			'kommun'  => $kommun,
			'bransch' => $bransch,
			'status'  => $status,
		),
		'strlen'
	);
	?>
	<div class="wrap iuc-admin">
		<header class="iuc-header iuc-header-split">
			<div>
				<h1>Företag</h1>
				<p>Medlemsföretag och organisationer. Klicka på ett företag för att öppna företagskortet.</p>
			</div>
			<div class="iuc-actions">
				<a class="iuc-btn iuc-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=iuc-syd-company-new' ) ); ?>">Nytt företag</a>
				<a class="iuc-btn iuc-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=iuc-syd-company-import' ) ); ?>">Importera</a>
			</div>
		</header>

		<?php iuc_company_render_notice(); ?>

		<form method="get" class="iuc-card iuc-filters" role="search" aria-label="Sök och filtrera företag">
			<input type="hidden" name="page" value="<?php echo esc_attr( IUC_COMPANIES_PAGE ); ?>">

			<div class="iuc-field iuc-field-grow">
				<label for="iuc-f-s">Sök</label>
				<input id="iuc-f-s" type="search" name="s" value="<?php echo esc_attr( $q ); ?>" placeholder="Företag, org.nr, kontakt, ort eller behov…">
			</div>

			<div class="iuc-field">
				<label for="iuc-f-kommun">Kommun</label>
				<select id="iuc-f-kommun" name="kommun">
					<option value="">Alla kommuner</option>
					<?php foreach ( $kommuner as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $kommun, $value ); ?>><?php echo esc_html( $value ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="iuc-field">
				<label for="iuc-f-bransch">Bransch</label>
				<select id="iuc-f-bransch" name="bransch">
					<option value="">Alla branscher</option>
					<?php foreach ( $branscher as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $bransch, $value ); ?>><?php echo esc_html( $value ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="iuc-field">
				<label for="iuc-f-status">Status</label>
				<select id="iuc-f-status" name="status">
					<option value="">Alla</option>
					<option value="publish" <?php selected( $status, 'publish' ); ?>>Godkända</option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>>Väntar på godkännande</option>
				</select>
			</div>

			<div class="iuc-actions iuc-filter-actions">
				<button type="submit" class="iuc-btn iuc-btn-primary">Filtrera</button>
				<?php if ( $filter_args ) : ?>
					<a class="iuc-btn iuc-btn-ghost" href="<?php echo esc_url( iuc_company_list_url() ); ?>">Rensa</a>
				<?php endif; ?>
			</div>
		</form>

		<p class="iuc-count" role="status">
			<?php echo esc_html( 1 === $total ? '1 företag' : $total . ' företag' ); ?>
		</p>

		<?php if ( ! $slice ) : ?>
			<div class="iuc-card iuc-empty-card">
				<strong>Inga företag matchar</strong>
				<span>Prova en annan sökning, kommun eller bransch.</span>
			</div>
		<?php else : ?>
			<div class="iuc-list" role="list">
				<div class="iuc-list-head" aria-hidden="true">
					<span>Företag</span><span>Kommun</span><span>Bransch</span><span>Huvudkontakt</span><span>Användare</span><span>Status</span>
				</div>
				<?php
				foreach ( $slice as $post ) :
					$users = iuc_company_users( $post->ID );
					$main  = iuc_company_main_user( $post->ID, $users );
					$badge = iuc_company_status_badge( $post );
					$contact = $main ? $main->display_name : iuc_company_meta( $post->ID, 'iuc_contact_name' );
					?>
					<a class="iuc-list-item" role="listitem" href="<?php echo esc_url( iuc_company_card_url( $post->ID ) ); ?>">
						<span class="iuc-list-name">
							<span class="iuc-avatar" aria-hidden="true"><?php echo esc_html( iuc_admin_initials( $post->post_title ) ); ?></span>
							<strong><?php echo esc_html( $post->post_title ); ?></strong>
						</span>
						<span data-label="Kommun"><?php echo esc_html( iuc_company_meta( $post->ID, 'iuc_kommun' ) ?: '–' ); ?></span>
						<span data-label="Bransch"><?php echo esc_html( iuc_company_meta( $post->ID, 'iuc_industry' ) ?: '–' ); ?></span>
						<span data-label="Huvudkontakt"><?php echo esc_html( $contact ?: '–' ); ?></span>
						<span data-label="Användare"><?php echo esc_html( (string) count( $users ) ); ?></span>
						<span data-label="Status"><span class="<?php echo esc_attr( $badge[1] ); ?>"><?php echo esc_html( $badge[0] ); ?></span></span>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( $pages > 1 ) : ?>
				<nav class="iuc-pager" aria-label="Sidor">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
						<a class="iuc-btn <?php echo $i === $paged ? 'iuc-btn-primary' : 'iuc-btn-ghost'; ?>" href="<?php echo esc_url( iuc_company_list_url( array_merge( $filter_args, array( 'paged' => $i ) ) ) ); ?>" <?php echo $i === $paged ? 'aria-current="page"' : ''; ?>><?php echo esc_html( (string) $i ); ?></a>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------------------------------------------------------------------
 * Company card
 * ------------------------------------------------------------------ */

function iuc_company_render_card( $company ) {
	$id      = $company->ID;
	$users   = iuc_company_users( $id );
	$main    = iuc_company_main_user( $id, $users );
	$badge   = iuc_company_status_badge( $company );
	$needs   = get_post_meta( $id, 'iuc_needs', true );
	$deltar  = get_post_meta( $id, 'iuc_participation', true );
	$needs   = is_array( $needs ) ? $needs : array();
	$deltar  = is_array( $deltar ) ? $deltar : array();
	$kommun  = iuc_company_meta( $id, 'iuc_kommun' );
	$bransch = iuc_company_meta( $id, 'iuc_industry' );
	$desc    = iuc_company_meta( $id, 'iuc_description' );

	$main_email = $main ? $main->user_email : iuc_company_meta( $id, 'iuc_contact_email' );

	$requests = function_exists( 'iuc_company_requests' ) ? iuc_company_requests( $id ) : array();
	$comments = $users
		? get_comments(
			array(
				'author__in' => wp_list_pluck( $users, 'ID' ),
				'number'     => 8,
				'status'     => 'all',
				'orderby'    => 'comment_date_gmt',
				'order'      => 'DESC',
			)
		)
		: array();

	$info = array(
		'Organisationsnummer' => iuc_company_meta( $id, 'iuc_org_nr' ),
		'Adress'              => trim( iuc_company_meta( $id, 'iuc_address' ) . ', ' . trim( iuc_company_meta( $id, 'iuc_postcode' ) . ' ' . iuc_company_meta( $id, 'iuc_city' ) ), ', ' ),
		'Telefon'             => iuc_company_meta( $id, 'iuc_contact_phone' ),
		'E-post'              => iuc_company_meta( $id, 'iuc_contact_email' ),
		'Webbplats'           => iuc_company_meta( $id, 'iuc_website' ),
		'Antal anställda'     => iuc_company_meta( $id, 'iuc_employees' ),
		'Intern anteckning'   => iuc_company_meta( $id, 'iuc_notes' ),
	);
	?>
	<div class="wrap iuc-admin">
		<p class="iuc-back"><a href="<?php echo esc_url( iuc_company_list_url() ); ?>">&larr; Alla företag</a></p>

		<?php iuc_company_render_notice(); ?>

		<section class="iuc-card iuc-company-head" aria-labelledby="iuc-company-title">
			<div class="iuc-company-title">
				<span class="iuc-avatar iuc-avatar-lg" aria-hidden="true"><?php echo esc_html( iuc_admin_initials( $company->post_title ) ); ?></span>
				<div>
					<h1 id="iuc-company-title"><?php echo esc_html( $company->post_title ); ?></h1>
					<span class="iuc-meta">
						<?php echo esc_html( implode( ' · ', array_filter( array( $kommun, $bransch ) ) ) ?: 'Kommun och bransch saknas' ); ?>
					</span>
				</div>
			</div>
			<div class="iuc-actions">
				<span class="<?php echo esc_attr( $badge[1] ); ?>"><?php echo esc_html( $badge[0] ); ?></span>
				<?php if ( $main_email ) : ?>
					<a class="iuc-btn iuc-btn-ghost" href="mailto:<?php echo esc_attr( $main_email ); ?>">Kontakta <?php echo esc_html( $main ? strtok( $main->display_name, ' ' ) : 'företaget' ); ?></a>
				<?php endif; ?>
				<a class="iuc-btn iuc-btn-primary" href="#iuc-edit">Redigera</a>
			</div>
			<?php if ( $desc ) : ?>
				<p class="iuc-desc"><?php echo esc_html( $desc ); ?></p>
			<?php endif; ?>
			<?php if ( 'publish' !== $company->post_status ) : ?>
				<div class="iuc-head-full">
					<?php
					iuc_admin_action_form(
						'company',
						$id,
						array(
							array( 'approve', 'Godkänn företaget', 'iuc-btn-primary' ),
							array( 'reject', 'Avvisa', 'iuc-btn-danger' ),
						),
						$id
					);
					?>
				</div>
			<?php endif; ?>
		</section>

		<div class="iuc-cols">
			<div class="iuc-col">
			<section class="iuc-card" aria-labelledby="iuc-info-title">
				<div class="iuc-card-head"><h2 id="iuc-info-title">Företagsuppgifter</h2></div>
				<dl class="iuc-dl">
					<?php foreach ( $info as $label => $value ) : ?>
						<div>
							<dt><?php echo esc_html( $label ); ?></dt>
							<dd>
								<?php
								if ( '' === $value ) {
									echo '–';
								} elseif ( 'Webbplats' === $label ) {
									echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
								} else {
									echo esc_html( $value );
								}
								?>
							</dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</section>

			<section class="iuc-card" aria-labelledby="iuc-needs-title">
				<div class="iuc-card-head"><h2 id="iuc-needs-title">Behov och utmaningar</h2></div>
				<?php if ( ! $needs ) : ?>
					<p class="iuc-empty">Inga behov registrerade.</p>
				<?php endif; ?>
				<?php foreach ( $needs as $need ) : ?>
					<div class="iuc-row">
						<div>
							<strong><?php echo esc_html( $need['text'] ?? '' ); ?></strong>
							<span class="iuc-meta">Syns för: <?php echo esc_html( $need['vis'] ?? 'Endast IUC Syd' ); ?></span>
						</div>
						<span class="iuc-badge"><?php echo esc_html( $need['amne'] ?? '' ); ?></span>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="iuc-card" aria-labelledby="iuc-part-title">
				<div class="iuc-card-head"><h2 id="iuc-part-title">Deltar i och anmäld till</h2></div>
				<?php if ( ! $deltar ) : ?>
					<p class="iuc-empty">Inga aktiviteter registrerade.</p>
				<?php endif; ?>
				<?php foreach ( $deltar as $item ) : ?>
					<div class="iuc-row">
						<div>
							<strong><?php echo esc_html( $item['name'] ?? '' ); ?></strong>
							<span class="iuc-meta"><?php echo esc_html( $item['sub'] ?? '' ); ?></span>
						</div>
						<span class="iuc-badge"><?php echo esc_html( $item['status'] ?? '' ); ?></span>
					</div>
				<?php endforeach; ?>
			</section>

			</div>
			<div class="iuc-col">
			<section class="iuc-card" aria-labelledby="iuc-users-title">
				<div class="iuc-card-head">
					<h2 id="iuc-users-title">Användare i företaget</h2>
					<span><?php echo esc_html( count( $users ) ); ?> st</span>
				</div>

				<?php if ( ! $users ) : ?>
					<p class="iuc-empty">Inga användare är kopplade till företaget än.</p>
				<?php endif; ?>

				<?php
				foreach ( $users as $user ) :
					$is_main  = $main && (int) $main->ID === (int) $user->ID;
					$ubadge   = iuc_user_status_badge( $user->ID );
					$consent  = (bool) get_user_meta( $user->ID, 'iuc_consent', true );
					$title    = (string) get_user_meta( $user->ID, 'iuc_job_title', true );
					$ustatus  = (string) get_user_meta( $user->ID, IUC_APPROVAL_META, true );
					?>
					<div class="iuc-row">
						<div class="iuc-person">
							<span class="iuc-avatar" aria-hidden="true"><?php echo esc_html( iuc_admin_initials( $user->display_name ) ); ?></span>
							<div>
								<strong><?php echo esc_html( $user->display_name ); ?></strong>
								<span class="iuc-meta"><?php echo esc_html( $title ?: 'Roll saknas' ); ?> · <a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></span>
								<span class="iuc-badges">
									<?php if ( $is_main ) : ?><span class="iuc-badge iuc-badge-dark">Huvudkontakt</span><?php endif; ?>
									<span class="<?php echo esc_attr( $ubadge[1] ); ?>"><?php echo esc_html( $ubadge[0] ); ?></span>
									<span class="iuc-badge <?php echo $consent ? '' : 'iuc-badge-amber'; ?>"><?php echo $consent ? 'Samtycke givet' : 'Samtycke saknas'; ?></span>
								</span>
							</div>
						</div>
						<?php
						$buttons = array();

						if ( 'pending' === $ustatus ) {
							$buttons[] = array( 'approve', 'Godkänn', 'iuc-btn-primary' );
							$buttons[] = array( 'block', 'Blockera', 'iuc-btn-ghost' );
						} elseif ( 'blocked' === $ustatus ) {
							$buttons[] = array( 'approve', 'Återaktivera', 'iuc-btn-ghost' );
						} elseif ( ! $is_main ) {
							$buttons[] = array( 'main', 'Gör till huvudkontakt', 'iuc-btn-ghost' );
						}

						if ( $buttons ) {
							iuc_admin_action_form( 'user', $user->ID, $buttons, $id );
						}
						?>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="iuc-card" aria-labelledby="iuc-req-title">
				<div class="iuc-card-head">
					<h2 id="iuc-req-title">Privata förfrågningar</h2>
					<span>Bara IUC Syd ser dessa</span>
				</div>
				<?php if ( ! $requests ) : ?>
					<p class="iuc-empty">Inga förfrågningar från företaget.</p>
				<?php endif; ?>
				<?php
				$type_labels   = function_exists( 'iuc_request_type_labels' ) ? iuc_request_type_labels() : array();
				$status_labels = function_exists( 'iuc_request_status_labels' ) ? iuc_request_status_labels() : array();

				foreach ( $requests as $request ) :
					$rtype   = (string) get_post_meta( $request->ID, 'iuc_request_type', true );
					$rstatus = (string) get_post_meta( $request->ID, 'iuc_request_status', true );
					$edit    = function_exists( 'iuc_request_url' ) ? iuc_request_url( $request->ID ) : '';
					?>
					<div class="iuc-row">
						<div>
							<strong>
								<?php if ( $edit ) : ?>
									<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $request->post_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $request->post_title ); ?>
								<?php endif; ?>
							</strong>
							<span class="iuc-meta">
								<?php echo esc_html( isset( $type_labels[ $rtype ] ) ? $type_labels[ $rtype ] : 'Förfrågan' ); ?>
								· <?php echo esc_html( get_the_author_meta( 'display_name', (int) $request->post_author ) ); ?>
								· <?php echo esc_html( date_i18n( 'j M Y', strtotime( $request->post_date_gmt . ' UTC' ) ) ); ?>
							</span>
						</div>
						<span class="iuc-badge <?php echo 'new' === $rstatus ? 'iuc-badge-amber' : ''; ?>"><?php echo esc_html( isset( $status_labels[ $rstatus ] ) ? $status_labels[ $rstatus ] : 'Ny' ); ?></span>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="iuc-card" aria-labelledby="iuc-comm-title">
				<div class="iuc-card-head">
					<h2 id="iuc-comm-title">Engagemang i communityt</h2>
					<span>Senaste kommentarer från företagets användare</span>
				</div>
				<?php if ( ! $comments ) : ?>
					<p class="iuc-empty">Ingen aktivitet i communityt än.</p>
				<?php endif; ?>
				<?php foreach ( $comments as $comment ) : ?>
					<div class="iuc-row">
						<div>
							<strong><?php echo esc_html( wp_trim_words( $comment->comment_content, 14 ) ); ?></strong>
							<span class="iuc-meta">Av <?php echo esc_html( $comment->comment_author ); ?> · <?php echo esc_html( date_i18n( 'j M Y', strtotime( $comment->comment_date_gmt . ' UTC' ) ) ); ?></span>
						</div>
						<span class="iuc-badge <?php echo '1' === (string) $comment->comment_approved ? '' : 'iuc-badge-amber'; ?>">
							<?php echo '1' === (string) $comment->comment_approved ? 'Publicerad' : 'Väntar på granskning'; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</section>

			</div>
		</div>

		<div class="iuc-grid">
			<?php iuc_company_render_edit_form( $company, $users, $main ); ?>
		</div>
	</div>
	<?php
}

/* ---------------------------------------------------------------------
 * Edit form + handler
 * ------------------------------------------------------------------ */

function iuc_company_render_edit_form( $company, array $users, $main ) {
	$id     = $company->ID;
	$values = array();

	foreach ( array_keys( iuc_company_fields() ) as $key ) {
		$values[ $key ] = iuc_company_meta( $id, $key );
	}
	?>
	<form id="iuc-edit" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="iuc-card iuc-card-wide iuc-form">
		<input type="hidden" name="action" value="iuc_company_update">
		<input type="hidden" name="company_id" value="<?php echo esc_attr( (string) $id ); ?>">
		<?php wp_nonce_field( 'iuc_company_update_' . $id, 'iuc_company_nonce' ); ?>

		<div class="iuc-card-head">
			<h2>Redigera företag</h2>
			<span>Ändringar sparas när du klickar på Spara</span>
		</div>

		<div class="iuc-form-grid">
			<div class="iuc-field iuc-field-wide">
				<label for="iuc-edit-name">Företagsnamn <span class="iuc-req">(obligatoriskt)</span></label>
				<input id="iuc-edit-name" type="text" name="company_name" required maxlength="200" value="<?php echo esc_attr( $company->post_title ); ?>">
			</div>

			<?php iuc_company_render_fields( $values ); ?>

			<div class="iuc-field">
				<label for="iuc-edit-status">Status</label>
				<select id="iuc-edit-status" name="company_status">
					<option value="publish" <?php selected( $company->post_status, 'publish' ); ?>>Godkänd (syns för medlemmar)</option>
					<option value="pending" <?php selected( 'publish' !== $company->post_status, true ); ?>>Väntar på godkännande</option>
				</select>
			</div>

			<div class="iuc-field">
				<label for="iuc-edit-main">Huvudkontakt</label>
				<select id="iuc-edit-main" name="main_user_id">
					<option value="0">Ingen vald</option>
					<?php foreach ( $users as $user ) : ?>
						<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $main && (int) $main->ID === (int) $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="iuc-actions">
			<button type="submit" class="iuc-btn iuc-btn-primary">Spara ändringar</button>
			<a class="iuc-btn iuc-btn-ghost" href="<?php echo esc_url( iuc_company_card_url( $id ) ); ?>">Avbryt</a>
		</div>
	</form>
	<?php
}

function iuc_company_handle_update() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;

	check_admin_referer( 'iuc_company_update_' . $company_id, 'iuc_company_nonce' );

	$company = $company_id ? get_post( $company_id ) : null;

	if ( ! $company || 'iuc_company' !== $company->post_type ) {
		wp_die( 'Företaget hittades inte.', '', array( 'response' => 404 ) );
	}

	$post  = wp_unslash( $_POST );
	$name  = isset( $post['company_name'] ) ? sanitize_text_field( $post['company_name'] ) : '';
	$org   = isset( $post['iuc_org_nr'] ) ? sanitize_text_field( $post['iuc_org_nr'] ) : '';
	$error = '';

	if ( '' === $name ) {
		$error = 'Företagsnamn saknas.';
	} else {
		$error = iuc_company_find_duplicate( $name, $org, $company_id );
	}

	$clean = array();

	foreach ( iuc_company_fields() as $key => $field ) {
		$clean[ $key ] = iuc_company_sanitize_field( $field['type'], isset( $post[ $key ] ) ? (string) $post[ $key ] : '' );
	}

	if ( ! $error && '' !== trim( (string) ( $post['iuc_contact_email'] ?? '' ) ) && '' === $clean['iuc_contact_email'] ) {
		$error = 'Ogiltig e-postadress.';
	}

	if ( ! $error && '' !== trim( (string) ( $post['iuc_website'] ?? '' ) ) && '' === $clean['iuc_website'] ) {
		$error = 'Ogiltig webbadress.';
	}

	if ( $error ) {
		set_transient( 'iuc_company_error_' . get_current_user_id(), $error, MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'iuc_notice', 'company_invalid', iuc_company_card_url( $company_id ) ) );
		exit;
	}

	$status = isset( $post['company_status'] ) && 'pending' === $post['company_status'] ? 'pending' : 'publish';

	wp_update_post(
		array(
			'ID'          => $company_id,
			'post_title'  => $name,
			'post_status' => $status,
		)
	);

	foreach ( $clean as $key => $value ) {
		if ( '' === $value ) {
			delete_post_meta( $company_id, $key );
		} else {
			update_post_meta( $company_id, $key, $value );
		}
	}

	$main_id  = isset( $post['main_user_id'] ) ? absint( $post['main_user_id'] ) : 0;
	$user_ids = array_map( 'intval', wp_list_pluck( iuc_company_users( $company_id ), 'ID' ) );

	if ( $main_id && in_array( $main_id, $user_ids, true ) ) {
		update_post_meta( $company_id, 'iuc_main_user_id', $main_id );
	} else {
		delete_post_meta( $company_id, 'iuc_main_user_id' );
	}

	wp_safe_redirect( add_query_arg( 'iuc_notice', 'company_saved', iuc_company_card_url( $company_id ) ) );
	exit;
}
add_action( 'admin_post_iuc_company_update', 'iuc_company_handle_update' );
