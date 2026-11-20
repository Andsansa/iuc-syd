<?php
/**
 * UI shells for Posts, Media, Pages and Comments under "IUC Syd".
 *
 * These pages only show the layout. Search, filters, buttons and rows are
 * static placeholders until the real functions are connected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definition of each shell page.
 */
function iuc_shell_pages() {
	return array(
		'iuc-syd-posts'    => array(
			'title'   => 'Inlägg',
			'menu'    => 'Inlägg',
			'intro'   => 'Nyheter och inlägg som publiceras i medlemsportalen.',
			'button'  => 'Nytt inlägg',
			'type'    => 'list',
			'search'  => 'Sök på rubrik eller författare…',
			'filters' => array(
				'Status'   => array( 'Alla', 'Publicerade', 'Utkast', 'Schemalagda' ),
				'Kategori' => array( 'Alla kategorier', 'Nyheter', 'Event', 'Kunskap' ),
			),
			'cols'    => array( 'Rubrik', 'Författare', 'Kategori', 'Datum', 'Status' ),
			'rows'    => array(
				array( 'Exempelrubrik: Ny kurs i automation', 'Redaktör', 'Nyheter', '12 nov 2026', 'Publicerad' ),
				array( 'Exempelrubrik: Nätverksträff i december', 'Redaktör', 'Event', '8 nov 2026', 'Utkast' ),
				array( 'Exempelrubrik: Så kommer du igång med AI', 'Redaktör', 'Kunskap', '2 nov 2026', 'Publicerad' ),
			),
		),
		'iuc-syd-media'    => array(
			'title'   => 'Media',
			'menu'    => 'Media',
			'intro'   => 'Bilder, dokument och filer som används i portalen.',
			'button'  => 'Ladda upp',
			'type'    => 'media',
			'search'  => 'Sök på filnamn…',
			'filters' => array(
				'Typ'   => array( 'Alla typer', 'Bilder', 'Dokument', 'Video' ),
				'Datum' => array( 'Alla datum', 'Senaste månaden', 'Senaste året' ),
			),
			'tiles'   => array( 'bild-1.jpg', 'bild-2.jpg', 'rapport.pdf', 'bild-3.jpg', 'presentation.pdf', 'logotyp.png', 'bild-4.jpg', 'faktablad.pdf' ),
		),
		'iuc-syd-pages'    => array(
			'title'   => 'Sidor',
			'menu'    => 'Sidor',
			'intro'   => 'Statiska sidor, till exempel Om oss, Kontakt och Villkor.',
			'button'  => 'Ny sida',
			'type'    => 'list',
			'search'  => 'Sök på sidtitel…',
			'filters' => array(
				'Status' => array( 'Alla', 'Publicerade', 'Utkast' ),
			),
			'cols'    => array( 'Sida', 'Författare', 'Senast ändrad', 'Synlighet', 'Status' ),
			'rows'    => array(
				array( 'Exempelsida: Om IUC Syd', 'Redaktör', '10 nov 2026', 'Alla', 'Publicerad' ),
				array( 'Exempelsida: Kontakt', 'Redaktör', '3 nov 2026', 'Alla', 'Publicerad' ),
				array( 'Exempelsida: Användarvillkor', 'Redaktör', '21 okt 2026', 'Inloggade', 'Utkast' ),
			),
		),
		'iuc-syd-comments' => array(
			'title'   => 'Kommentarer',
			'menu'    => 'Kommentarer',
			'intro'   => 'Alla kommentarer i communityt. Här kommer moderering att samlas.',
			'button'  => '',
			'type'    => 'list',
			'search'  => 'Sök i kommentarer, namn eller företag…',
			'filters' => array(
				'Status' => array( 'Alla', 'Väntar på granskning', 'Rapporterade', 'Godkända' ),
			),
			'cols'    => array( 'Kommentar', 'Författare', 'Företag', 'Datum', 'Status' ),
			'rows'    => array(
				array( 'Exempelkommentar: Vi har testat visionstyrd plockning och kan dela erfarenheter.', 'Exempelperson', 'Exempelföretag AB', '14 nov 2026', 'Väntar' ),
				array( 'Exempelkommentar: Tack för tipset, vi anmälde oss i går!', 'Exempelperson', 'Exempelföretag AB', '13 nov 2026', 'Godkänd' ),
				array( 'Exempelkommentar: Rapporterad av en annan medlem.', 'Exempelperson', 'Exempelföretag AB', '11 nov 2026', 'Rapporterad' ),
			),
		),
	);
}

function iuc_shell_menu() {
	foreach ( iuc_shell_pages() as $slug => $page ) {
		add_submenu_page(
			IUC_ADMIN_PAGE,
			'IUC Syd – ' . $page['title'],
			$page['menu'],
			'manage_options',
			$slug,
			'iuc_shell_render'
		);
	}
}
add_action( 'admin_menu', 'iuc_shell_menu', 17 );

function iuc_shell_render() {
	$slug  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$pages = iuc_shell_pages();

	if ( ! isset( $pages[ $slug ] ) ) {
		return;
	}

	$page = $pages[ $slug ];
	?>
	<div class="wrap iuc-admin">
		<header class="iuc-header iuc-header-split">
			<div>
				<h1><?php echo esc_html( $page['title'] ); ?></h1>
				<p><?php echo esc_html( $page['intro'] ); ?></p>
			</div>
			<?php if ( $page['button'] ) : ?>
				<div class="iuc-actions">
					<button type="button" class="iuc-btn iuc-btn-primary" disabled aria-disabled="true"><?php echo esc_html( $page['button'] ); ?></button>
				</div>
			<?php endif; ?>
		</header>

		<div class="iuc-shell-note" role="note">
			<strong>Förhandsvisning av layouten.</strong>
			Funktionerna på den här sidan är inte kopplade ännu. Raderna nedan är exempel.
		</div>

		<form class="iuc-card iuc-filters" role="search" aria-label="Sök och filtrera <?php echo esc_attr( mb_strtolower( $page['title'] ) ); ?>" onsubmit="return false;">
			<div class="iuc-field iuc-field-grow">
				<label for="iuc-shell-s">Sök</label>
				<input id="iuc-shell-s" type="search" placeholder="<?php echo esc_attr( $page['search'] ); ?>" disabled>
			</div>
			<?php foreach ( $page['filters'] as $label => $options ) : ?>
				<?php $id = 'iuc-shell-' . sanitize_key( $label ); ?>
				<div class="iuc-field">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
					<select id="<?php echo esc_attr( $id ); ?>" disabled>
						<?php foreach ( $options as $option ) : ?>
							<option><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endforeach; ?>
			<div class="iuc-actions iuc-filter-actions">
				<button type="button" class="iuc-btn iuc-btn-primary" disabled aria-disabled="true">Filtrera</button>
			</div>
		</form>

		<?php if ( 'media' === $page['type'] ) : ?>
			<div class="iuc-media-grid" role="list">
				<?php foreach ( $page['tiles'] as $name ) : ?>
					<div class="iuc-media-tile iuc-card" role="listitem">
						<div class="iuc-media-thumb" aria-hidden="true"><?php echo esc_html( strtoupper( pathinfo( $name, PATHINFO_EXTENSION ) ) ); ?></div>
						<span><?php echo esc_html( $name ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<?php $cols = count( $page['cols'] ); ?>
			<div class="iuc-list iuc-shell-list" role="list" style="--iuc-cols: minmax(240px, 2.4fr) repeat(<?php echo esc_attr( (string) ( $cols - 1 ) ); ?>, minmax(0, 1fr));">
				<div class="iuc-list-head" aria-hidden="true">
					<?php foreach ( $page['cols'] as $col ) : ?>
						<span><?php echo esc_html( $col ); ?></span>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $page['rows'] as $row ) : ?>
					<div class="iuc-list-item" role="listitem">
						<?php foreach ( $row as $i => $cell ) : ?>
							<?php if ( 0 === $i ) : ?>
								<span class="iuc-list-name"><strong><?php echo esc_html( $cell ); ?></strong></span>
							<?php elseif ( $cols - 1 === $i ) : ?>
								<span data-label="<?php echo esc_attr( $page['cols'][ $i ] ); ?>"><span class="iuc-badge <?php echo in_array( $cell, array( 'Utkast', 'Väntar' ), true ) ? 'iuc-badge-amber' : ( 'Rapporterad' === $cell ? 'iuc-badge-red' : '' ); ?>"><?php echo esc_html( $cell ); ?></span></span>
							<?php else : ?>
								<span data-label="<?php echo esc_attr( $page['cols'][ $i ] ); ?>"><?php echo esc_html( $cell ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
