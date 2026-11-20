<?php
/**
 * Companies: add form, edit fields, and CSV / Excel (.xlsx) import.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IUC_COMPANY_NEW_PAGE    = 'iuc-syd-company-new';
const IUC_COMPANY_IMPORT_PAGE = 'iuc-syd-company-import';
const IUC_IMPORT_MAX_ROWS     = 1000;
const IUC_IMPORT_MAX_BYTES    = 2097152; // 2 MB.

/* ---------------------------------------------------------------------
 * Field definitions (single source of truth for form, edit and import)
 * ------------------------------------------------------------------ */

function iuc_company_fields() {
	return array(
		'iuc_org_nr'        => array( 'label' => 'Organisationsnummer', 'type' => 'text', 'placeholder' => '556677-8899' ),
		'iuc_kommun'        => array( 'label' => 'Kommun', 'type' => 'text', 'placeholder' => 'Lund' ),
		'iuc_contact_name'  => array( 'label' => 'Huvudkontakt (namn)', 'type' => 'text', 'placeholder' => 'Anna Lindqvist' ),
		'iuc_contact_email' => array( 'label' => 'Huvudkontakt (e-post)', 'type' => 'email', 'placeholder' => 'anna@foretag.se' ),
		'iuc_contact_phone' => array( 'label' => 'Telefon', 'type' => 'tel', 'placeholder' => '040-123 45 67' ),
		'iuc_address'       => array( 'label' => 'Adress', 'type' => 'text', 'placeholder' => 'Industrigatan 1' ),
		'iuc_postcode'      => array( 'label' => 'Postnummer', 'type' => 'text', 'placeholder' => '211 20' ),
		'iuc_city'          => array( 'label' => 'Ort', 'type' => 'text', 'placeholder' => 'Malmö' ),
		'iuc_website'       => array( 'label' => 'Webbplats', 'type' => 'url', 'placeholder' => 'https://www.foretag.se' ),
		'iuc_industry'      => array( 'label' => 'Bransch', 'type' => 'text', 'placeholder' => 'Verkstadsindustri' ),
		'iuc_employees'     => array( 'label' => 'Antal anställda', 'type' => 'number', 'placeholder' => '25' ),
		'iuc_description'   => array( 'label' => 'Beskrivning', 'type' => 'textarea', 'placeholder' => 'Kort om vad företaget gör' ),
		'iuc_notes'         => array( 'label' => 'Intern anteckning', 'type' => 'textarea', 'placeholder' => 'Syns bara för IUC Syd' ),
	);
}

function iuc_company_sanitize_field( $type, $value ) {
	$value = is_string( $value ) ? $value : '';

	switch ( $type ) {
		case 'email':
			return sanitize_email( $value );
		case 'url':
			return $value ? esc_url_raw( $value ) : '';
		case 'number':
			return '' === trim( $value ) ? '' : (string) absint( $value );
		case 'textarea':
			return sanitize_textarea_field( $value );
		default:
			return sanitize_text_field( $value );
	}
}

/**
 * Create a company. Returns post ID or WP_Error (iuc_invalid | iuc_duplicate).
 *
 * @param array  $data   name + field values keyed by meta key.
 * @param string $status publish | pending.
 */
/**
 * Returns an error message when another company has the same org number or name.
 *
 * @param int $exclude_id Company being edited (ignored in the check).
 */
function iuc_company_find_duplicate( $name, $org_nr, $exclude_id = 0 ) {
	global $wpdb;

	$statuses = array( 'publish', 'pending', 'draft', 'private' );

	if ( '' !== $org_nr ) {
		$exists = get_posts(
			array(
				'post_type'      => 'iuc_company',
				'post_status'    => $statuses,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post__not_in'   => array( (int) $exclude_id ),
				'meta_key'       => 'iuc_org_nr',
				'meta_value'     => $org_nr,
			)
		);

		if ( $exists ) {
			return 'Organisationsnumret finns redan.';
		}
	}

	$same_name = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'iuc_company' AND post_status IN ('publish','pending','draft','private') AND post_title = %s AND ID <> %d LIMIT 1",
			$name,
			(int) $exclude_id
		)
	);

	return $same_name ? 'Ett företag med samma namn finns redan.' : '';
}

function iuc_company_create( array $data, $status = 'publish' ) {
	$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

	if ( '' === $name ) {
		return new WP_Error( 'iuc_invalid', 'Företagsnamn saknas.' );
	}

	$clean = array();

	foreach ( iuc_company_fields() as $key => $field ) {
		$clean[ $key ] = isset( $data[ $key ] )
			? iuc_company_sanitize_field( $field['type'], $data[ $key ] )
			: '';
	}

	if ( '' !== trim( (string) ( $data['iuc_contact_email'] ?? '' ) ) && '' === $clean['iuc_contact_email'] ) {
		return new WP_Error( 'iuc_invalid', 'Ogiltig e-postadress.' );
	}

	if ( '' !== trim( (string) ( $data['iuc_website'] ?? '' ) ) && '' === $clean['iuc_website'] ) {
		return new WP_Error( 'iuc_invalid', 'Ogiltig webbadress.' );
	}

	$duplicate = iuc_company_find_duplicate( $name, $clean['iuc_org_nr'] );

	if ( $duplicate ) {
		return new WP_Error( 'iuc_duplicate', $duplicate );
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'iuc_company',
			'post_status' => 'pending' === $status ? 'pending' : 'publish',
			'post_title'  => $name,
			'post_author' => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	foreach ( $clean as $key => $value ) {
		if ( '' !== $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	return (int) $post_id;
}

/* ---------------------------------------------------------------------
 * Menu pages
 * ------------------------------------------------------------------ */

function iuc_company_menu() {
	add_submenu_page(
		IUC_ADMIN_PAGE,
		'Lägg till företag',
		'Lägg till företag',
		'manage_options',
		IUC_COMPANY_NEW_PAGE,
		'iuc_company_render_new_page'
	);

	add_submenu_page(
		IUC_ADMIN_PAGE,
		'Importera företag',
		'Importera företag',
		'manage_options',
		IUC_COMPANY_IMPORT_PAGE,
		'iuc_company_render_import_page'
	);
}
add_action( 'admin_menu', 'iuc_company_menu', 20 );

function iuc_company_render_fields( array $values ) {
	foreach ( iuc_company_fields() as $key => $field ) :
		$id    = 'iuc-' . $key;
		$value = isset( $values[ $key ] ) ? $values[ $key ] : '';
		?>
		<div class="iuc-field <?php echo 'textarea' === $field['type'] ? 'iuc-field-wide' : ''; ?>">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<?php if ( 'textarea' === $field['type'] ) : ?>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="3" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
			<?php else : ?>
				<input id="<?php echo esc_attr( $id ); ?>" type="<?php echo esc_attr( $field['type'] ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" <?php echo 'number' === $field['type'] ? 'min="0"' : ''; ?>>
			<?php endif; ?>
		</div>
		<?php
	endforeach;
}

function iuc_company_render_notice() {
	$key  = isset( $_GET['iuc_notice'] ) ? sanitize_key( wp_unslash( $_GET['iuc_notice'] ) ) : '';
	$text = iuc_admin_notice_text( $key );

	if ( ! $text ) {
		return;
	}

	$is_error = in_array( $key, array( 'error', 'company_invalid', 'company_duplicate', 'import_failed' ), true );
	?>
	<div class="iuc-flash <?php echo $is_error ? 'iuc-flash-error' : ''; ?>" role="<?php echo $is_error ? 'alert' : 'status'; ?>">
		<?php echo esc_html( $text ); ?>
		<?php
		$detail = get_transient( 'iuc_company_error_' . get_current_user_id() );

		if ( $is_error && $detail ) {
			echo ' ' . esc_html( $detail );
			delete_transient( 'iuc_company_error_' . get_current_user_id() );
		}
		?>
	</div>
	<?php
}

function iuc_company_render_new_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$saved  = get_transient( 'iuc_company_form_' . get_current_user_id() );
	$values = is_array( $saved ) ? $saved : array();
	delete_transient( 'iuc_company_form_' . get_current_user_id() );
	?>
	<div class="wrap iuc-admin">
		<header class="iuc-header">
			<h1>Lägg till företag</h1>
			<p>Lägg in ett medlemsföretag manuellt. Många företag på en gång? <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . IUC_COMPANY_IMPORT_PAGE ) ); ?>">Importera från fil</a>.</p>
		</header>

		<?php iuc_company_render_notice(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="iuc-card iuc-form">
			<input type="hidden" name="action" value="iuc_company_add">
			<?php wp_nonce_field( 'iuc_company_add', 'iuc_company_nonce' ); ?>

			<div class="iuc-field iuc-field-wide">
				<label for="iuc-company-name">Företagsnamn <span class="iuc-req">(obligatoriskt)</span></label>
				<input id="iuc-company-name" type="text" name="company_name" required maxlength="200" value="<?php echo esc_attr( isset( $values['company_name'] ) ? $values['company_name'] : '' ); ?>" placeholder="Höglunds Verkstad AB">
			</div>

			<div class="iuc-form-grid">
				<?php iuc_company_render_fields( $values ); ?>

				<div class="iuc-field">
					<label for="iuc-company-status">Status</label>
					<select id="iuc-company-status" name="company_status">
						<option value="publish">Godkänd (syns för medlemmar)</option>
						<option value="pending" <?php selected( isset( $values['company_status'] ) ? $values['company_status'] : '', 'pending' ); ?>>Väntar på godkännande</option>
					</select>
				</div>
			</div>

			<div class="iuc-actions">
				<button type="submit" class="iuc-btn iuc-btn-primary">Spara företag</button>
				<a class="iuc-btn iuc-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . IUC_ADMIN_PAGE ) ); ?>">Avbryt</a>
			</div>
		</form>
	</div>
	<?php
}

/* ---------------------------------------------------------------------
 * Add form handler
 * ------------------------------------------------------------------ */

function iuc_company_handle_add() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'iuc_company_add', 'iuc_company_nonce' );

	$post = wp_unslash( $_POST );
	$data = array( 'name' => isset( $post['company_name'] ) ? (string) $post['company_name'] : '' );

	foreach ( array_keys( iuc_company_fields() ) as $key ) {
		$data[ $key ] = isset( $post[ $key ] ) ? (string) $post[ $key ] : '';
	}

	$status = isset( $post['company_status'] ) && 'pending' === $post['company_status'] ? 'pending' : 'publish';
	$result = iuc_company_create( $data, $status );

	if ( is_wp_error( $result ) ) {
		$form                   = $data;
		$form['company_name']   = $data['name'];
		$form['company_status'] = $status;

		set_transient( 'iuc_company_form_' . get_current_user_id(), $form, 5 * MINUTE_IN_SECONDS );
		set_transient( 'iuc_company_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => IUC_COMPANY_NEW_PAGE,
					'iuc_notice' => 'iuc_duplicate' === $result->get_error_code() ? 'company_duplicate' : 'company_invalid',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'       => IUC_ADMIN_PAGE,
				'iuc_notice' => 'company_added',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_iuc_company_add', 'iuc_company_handle_add' );

/* ---------------------------------------------------------------------
 * Edit screen: show the same fields on the native company editor
 * ------------------------------------------------------------------ */

function iuc_company_add_meta_box() {
	add_meta_box(
		'iuc-company-details',
		'Företagsuppgifter',
		'iuc_company_render_meta_box',
		'iuc_company',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'iuc_company_add_meta_box' );

function iuc_company_render_meta_box( $post ) {
	wp_nonce_field( 'iuc_company_meta', 'iuc_company_meta_nonce' );

	$values = array();

	foreach ( array_keys( iuc_company_fields() ) as $key ) {
		$values[ $key ] = (string) get_post_meta( $post->ID, $key, true );
	}

	echo '<div class="iuc-admin"><div class="iuc-form-grid">';
	iuc_company_render_fields( $values );
	echo '</div></div>';
}

function iuc_company_save_meta_box( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if (
		! isset( $_POST['iuc_company_meta_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['iuc_company_meta_nonce'] ) ), 'iuc_company_meta' ) ||
		! current_user_can( 'edit_post', $post_id )
	) {
		return;
	}

	foreach ( iuc_company_fields() as $key => $field ) {
		$raw   = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		$value = iuc_company_sanitize_field( $field['type'], $raw );

		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
add_action( 'save_post_iuc_company', 'iuc_company_save_meta_box' );

/* ---------------------------------------------------------------------
 * Import: template download
 * ------------------------------------------------------------------ */

function iuc_import_columns() {
	return array(
		'Företagsnamn',
		'Organisationsnummer',
		'Kommun',
		'Kontaktperson',
		'Kontakt e-post',
		'Telefon',
		'Adress',
		'Postnummer',
		'Ort',
		'Webbplats',
		'Bransch',
		'Antal anställda',
		'Beskrivning',
		'Status',
	);
}

function iuc_company_download_template() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'iuc_company_template' );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="foretag-mall.csv"' );

	// BOM + semicolon so Swedish Excel opens it with å, ä and ö intact.
	echo "\xEF\xBB\xBF";

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, iuc_import_columns(), ';', '"', '\\' );
	fputcsv(
		$out,
		array( 'Exempel Verkstad AB', '556677-8899', 'Malmö', 'Anna Andersson', 'anna@exempel.se', '040-123 45 67', 'Industrigatan 1', '211 20', 'Malmö', 'https://www.exempel.se', 'Verkstadsindustri', '25', 'Tillverkar kundanpassade komponenter', 'Godkänd' ),
		';',
		'"',
		'\\'
	);
	fclose( $out );
	exit;
}
add_action( 'admin_post_iuc_company_template', 'iuc_company_download_template' );

/* ---------------------------------------------------------------------
 * Import: reading files
 * ------------------------------------------------------------------ */

function iuc_import_normalize_header( $header ) {
	$header = mb_strtolower( trim( (string) $header ) );
	$header = strtr(
		$header,
		array(
			'å' => 'a',
			'ä' => 'a',
			'ö' => 'o',
			'é' => 'e',
		)
	);

	return preg_replace( '/[^a-z0-9]/', '', $header );
}

/**
 * Map a normalized header to a field key ('name', 'status' or a meta key).
 */
function iuc_import_header_map() {
	return array(
		'foretagsnamn'   => 'name',
		'foretag'        => 'name',
		'namn'           => 'name',
		'company'        => 'name',
		'companyname'    => 'name',
		'name'           => 'name',
		'organisationsnummer' => 'iuc_org_nr',
		'orgnr'          => 'iuc_org_nr',
		'orgnummer'      => 'iuc_org_nr',
		'organizationnumber' => 'iuc_org_nr',
		'kontaktperson'  => 'iuc_contact_name',
		'huvudkontakt'   => 'iuc_contact_name',
		'kontakt'        => 'iuc_contact_name',
		'contact'        => 'iuc_contact_name',
		'kontaktepost'   => 'iuc_contact_email',
		'epost'          => 'iuc_contact_email',
		'email'          => 'iuc_contact_email',
		'mail'           => 'iuc_contact_email',
		'telefon'        => 'iuc_contact_phone',
		'tel'            => 'iuc_contact_phone',
		'phone'          => 'iuc_contact_phone',
		'adress'         => 'iuc_address',
		'address'        => 'iuc_address',
		'postnummer'     => 'iuc_postcode',
		'postnr'         => 'iuc_postcode',
		'zip'            => 'iuc_postcode',
		'ort'            => 'iuc_city',
		'stad'           => 'iuc_city',
		'city'           => 'iuc_city',
		'webbplats'      => 'iuc_website',
		'hemsida'        => 'iuc_website',
		'webb'           => 'iuc_website',
		'website'        => 'iuc_website',
		'bransch'        => 'iuc_industry',
		'industry'       => 'iuc_industry',
		'antalanstallda' => 'iuc_employees',
		'anstallda'      => 'iuc_employees',
		'employees'      => 'iuc_employees',
		'kommun'         => 'iuc_kommun',
		'beskrivning'    => 'iuc_description',
		'description'    => 'iuc_description',
		'status'         => 'status',
	);
}

function iuc_import_read_csv( $path ) {
	$content = (string) file_get_contents( $path );
	$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );

	if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
		$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
	}

	$first_line = strtok( $content, "\n" );
	$delimiter  = ';';
	$best       = -1;

	foreach ( array( ';', ',', "\t" ) as $candidate ) {
		$count = substr_count( (string) $first_line, $candidate );

		if ( $count > $best ) {
			$best      = $count;
			$delimiter = $candidate;
		}
	}

	$handle = fopen( 'php://temp', 'r+' );
	fwrite( $handle, $content );
	rewind( $handle );

	$rows = array();

	while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
		$rows[] = array_map( 'trim', $row );

		if ( count( $rows ) > IUC_IMPORT_MAX_ROWS + 1 ) {
			break;
		}
	}

	fclose( $handle );

	return $rows;
}

function iuc_import_column_index( $cell_ref ) {
	$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $cell_ref ) );
	$index   = 0;

	for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
		$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
	}

	return max( 0, $index - 1 );
}

/**
 * Minimal .xlsx reader (first worksheet). An .xlsx file is a zip of XML files.
 */
function iuc_import_read_xlsx( $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'iuc_nozip', 'Servern saknar zip-stöd för Excel-filer. Spara filen som CSV i stället.' );
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'iuc_badzip', 'Excel-filen kunde inte öppnas.' );
	}

	$sheet_name = null;

	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );

		if ( preg_match( '#^xl/worksheets/sheet\d+\.xml$#', $name ) ) {
			if ( null === $sheet_name || strnatcmp( $name, $sheet_name ) < 0 ) {
				$sheet_name = $name;
			}
		}
	}

	$limit = 10 * 1024 * 1024;
	$stat  = $sheet_name ? $zip->statName( $sheet_name ) : false;

	if ( ! $stat || $stat['size'] > $limit ) {
		$zip->close();

		return new WP_Error( 'iuc_badzip', 'Kunde inte läsa något kalkylblad i Excel-filen.' );
	}

	$sheet_xml  = $zip->getFromName( $sheet_name );
	$shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
	$zip->close();

	$shared = array();

	if ( false !== $shared_xml && strlen( $shared_xml ) <= $limit ) {
		$ss = simplexml_load_string( $shared_xml, 'SimpleXMLElement', LIBXML_NONET );

		if ( $ss ) {
			foreach ( $ss->si as $si ) {
				if ( isset( $si->t ) ) {
					$shared[] = (string) $si->t;
					continue;
				}

				$text = '';

				foreach ( $si->r as $run ) {
					$text .= (string) $run->t;
				}

				$shared[] = $text;
			}
		}
	}

	$xml = simplexml_load_string( (string) $sheet_xml, 'SimpleXMLElement', LIBXML_NONET );

	if ( ! $xml || ! isset( $xml->sheetData ) ) {
		return new WP_Error( 'iuc_badzip', 'Kunde inte läsa något kalkylblad i Excel-filen.' );
	}

	$rows = array();

	foreach ( $xml->sheetData->row as $row ) {
		$cells = array();

		foreach ( $row->c as $cell ) {
			$type = (string) $cell['t'];

			if ( 's' === $type ) {
				$value = isset( $shared[ (int) $cell->v ] ) ? $shared[ (int) $cell->v ] : '';
			} elseif ( 'inlineStr' === $type ) {
				$value = (string) $cell->is->t;
			} else {
				$value = (string) $cell->v;
			}

			$cells[ iuc_import_column_index( (string) $cell['r'] ) ] = trim( $value );
		}

		if ( ! $cells ) {
			continue;
		}

		$line = array();

		for ( $i = 0, $max = max( array_keys( $cells ) ); $i <= $max; $i++ ) {
			$line[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
		}

		$rows[] = $line;

		if ( count( $rows ) > IUC_IMPORT_MAX_ROWS + 1 ) {
			break;
		}
	}

	return $rows;
}

/* ---------------------------------------------------------------------
 * Import: handler
 * ------------------------------------------------------------------ */

function iuc_import_status_value( $value ) {
	$value = iuc_import_normalize_header( $value );

	if ( in_array( $value, array( 'vantar', 'vantarpagodkannande', 'pending', 'ejgodkand' ), true ) ) {
		return 'pending';
	}

	return 'publish';
}

function iuc_company_handle_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har inte behörighet.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'iuc_company_import', 'iuc_import_nonce' );

	$back = admin_url( 'admin.php?page=' . IUC_COMPANY_IMPORT_PAGE );
	$fail = static function ( $message ) use ( $back ) {
		set_transient( 'iuc_company_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'iuc_notice', 'import_failed', $back ) );
		exit;
	};

	if (
		empty( $_FILES['iuc_import_file']['tmp_name'] ) ||
		UPLOAD_ERR_OK !== (int) $_FILES['iuc_import_file']['error'] ||
		! is_uploaded_file( $_FILES['iuc_import_file']['tmp_name'] )
	) {
		$fail( 'Ingen fil laddades upp.' );
	}

	$tmp  = $_FILES['iuc_import_file']['tmp_name'];
	$name = sanitize_file_name( wp_unslash( $_FILES['iuc_import_file']['name'] ) );
	$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

	if ( filesize( $tmp ) > IUC_IMPORT_MAX_BYTES ) {
		$fail( 'Filen är större än 2 MB.' );
	}

	if ( 'csv' === $ext || 'txt' === $ext ) {
		$rows = iuc_import_read_csv( $tmp );
	} elseif ( 'xlsx' === $ext ) {
		$rows = iuc_import_read_xlsx( $tmp );

		if ( is_wp_error( $rows ) ) {
			$fail( $rows->get_error_message() );
		}
	} else {
		$fail( 'Filtypen stöds inte. Använd .csv eller .xlsx.' );
	}

	if ( count( $rows ) < 2 ) {
		$fail( 'Filen innehåller inga rader under rubrikraden.' );
	}

	$map     = iuc_import_header_map();
	$columns = array();

	foreach ( array_shift( $rows ) as $index => $header ) {
		$normalized = iuc_import_normalize_header( $header );

		if ( isset( $map[ $normalized ] ) && ! in_array( $map[ $normalized ], $columns, true ) ) {
			$columns[ $index ] = $map[ $normalized ];
		}
	}

	if ( ! in_array( 'name', $columns, true ) ) {
		$fail( 'Hittade ingen kolumn för företagsnamn. Använd mallen och behåll rubrikraden.' );
	}

	$created = 0;
	$skipped = 0;
	$errors  = array();

	foreach ( $rows as $offset => $row ) {
		$line = $offset + 2; // 1-based, after the header row.

		if ( ! array_filter( $row, 'strlen' ) ) {
			continue;
		}

		$data   = array();
		$status = 'publish';

		foreach ( $columns as $index => $key ) {
			$value = isset( $row[ $index ] ) ? (string) $row[ $index ] : '';

			if ( 'status' === $key ) {
				$status = iuc_import_status_value( $value );
			} else {
				$data[ $key ] = $value;
			}
		}

		$result = iuc_company_create( $data, $status );

		if ( is_wp_error( $result ) ) {
			if ( 'iuc_duplicate' === $result->get_error_code() ) {
				$skipped++;
			}

			$errors[] = 'Rad ' . $line . ': ' . $result->get_error_message();
			continue;
		}

		$created++;
	}

	set_transient(
		'iuc_import_result_' . get_current_user_id(),
		array(
			'file'    => $name,
			'created' => $created,
			'skipped' => $skipped,
			'errors'  => array_slice( $errors, 0, 50 ),
			'error_n' => count( $errors ),
		),
		10 * MINUTE_IN_SECONDS
	);

	wp_safe_redirect( add_query_arg( 'iuc_notice', 'import_done', $back ) );
	exit;
}
add_action( 'admin_post_iuc_company_import', 'iuc_company_handle_import' );

/* ---------------------------------------------------------------------
 * Import page
 * ------------------------------------------------------------------ */

function iuc_company_render_import_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$result = get_transient( 'iuc_import_result_' . get_current_user_id() );
	delete_transient( 'iuc_import_result_' . get_current_user_id() );

	$template_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=iuc_company_template' ),
		'iuc_company_template'
	);
	?>
	<div class="wrap iuc-admin">
		<header class="iuc-header">
			<h1>Importera företag</h1>
			<p>Ladda upp en CSV- eller Excel-fil (.xlsx) med företag. Företagen läggs till direkt när filen har laddats upp.</p>
		</header>

		<?php iuc_company_render_notice(); ?>

		<?php if ( is_array( $result ) ) : ?>
			<section class="iuc-card iuc-result" aria-labelledby="iuc-result-title">
				<h2 id="iuc-result-title">Resultat för <?php echo esc_html( $result['file'] ); ?></h2>
				<p>
					<strong><?php echo esc_html( (string) $result['created'] ); ?></strong> företag lades till.
					<?php if ( $result['skipped'] ) : ?>
						<strong><?php echo esc_html( (string) $result['skipped'] ); ?></strong> hoppades över eftersom de redan finns.
					<?php endif; ?>
					<?php if ( $result['error_n'] > $result['skipped'] ) : ?>
						<strong><?php echo esc_html( (string) ( $result['error_n'] - $result['skipped'] ) ); ?></strong> rader hade fel.
					<?php endif; ?>
				</p>

				<?php if ( $result['errors'] ) : ?>
					<ul class="iuc-errors">
						<?php foreach ( $result['errors'] as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<div class="iuc-actions">
					<a class="iuc-btn iuc-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=iuc-syd-companies' ) ); ?>">Visa företag</a>
					<a class="iuc-btn iuc-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . IUC_ADMIN_PAGE ) ); ?>">Till översikten</a>
				</div>
			</section>
		<?php endif; ?>

		<div class="iuc-grid">
			<section class="iuc-card" aria-labelledby="iuc-import-title">
				<h2 id="iuc-import-title">1. Ladda upp fil</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="iuc-form">
					<input type="hidden" name="action" value="iuc_company_import">
					<?php wp_nonce_field( 'iuc_company_import', 'iuc_import_nonce' ); ?>

					<div class="iuc-field iuc-field-wide">
						<label for="iuc-import-file">Fil (.csv eller .xlsx, högst 2 MB)</label>
						<input id="iuc-import-file" type="file" name="iuc_import_file" accept=".csv,.txt,.xlsx" required>
					</div>

					<div class="iuc-actions">
						<button type="submit" class="iuc-btn iuc-btn-primary">Ladda upp och lägg till</button>
					</div>
				</form>
			</section>

			<section class="iuc-card" aria-labelledby="iuc-template-title">
				<h2 id="iuc-template-title">Mall och regler</h2>
				<p class="iuc-meta">
					Börja gärna med mallen. Första raden ska vara rubriker. Bara <strong>Företagsnamn</strong> är obligatoriskt.
				</p>
				<div class="iuc-actions">
					<a class="iuc-btn iuc-btn-ghost" href="<?php echo esc_url( $template_url ); ?>">Ladda ner CSV-mall</a>
				</div>
				<ul class="iuc-rules">
					<li>Kolumner: <?php echo esc_html( implode( ', ', iuc_import_columns() ) ); ?>.</li>
					<li>Status: <em>Godkänd</em> (standard) eller <em>Väntar</em>.</li>
					<li>Företag med samma organisationsnummer eller namn som redan finns hoppas över.</li>
					<li>Högst <?php echo esc_html( (string) IUC_IMPORT_MAX_ROWS ); ?> rader per fil.</li>
				</ul>
			</section>
		</div>
	</div>
	<?php
}
