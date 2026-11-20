<?php
/**
 * Links members, requests and the BuddyPress "Företag" field to company posts.
 *
 * - The BuddyPress profile field "Företag" becomes a drop-down whose options
 *   are the approved companies (iuc_company posts).
 * - When a member saves that field, the user is linked to the company
 *   (user meta iuc_company_id + iuc_company).
 * - Private requests store the company ID (iuc_request_company_id).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IUC_BP_COMPANY_FIELD   = 'Företag';
const IUC_LINK_SETUP_VERSION = '1';

/* ---------------------------------------------------------------------
 * Look-ups
 * ------------------------------------------------------------------ */

/**
 * Find a company post by exact name. Returns 0 when there is none.
 */
function iuc_company_id_by_name( $name ) {
	global $wpdb;

	$name = trim( wp_unslash( (string) $name ) );

	if ( '' === $name ) {
		return 0;
	}

	foreach ( array_unique( array( $name, html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) ) as $candidate ) {
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'iuc_company' AND post_status IN ('publish','pending','draft','private') AND post_title = %s ORDER BY ID ASC LIMIT 1",
				$candidate
			)
		);

		if ( $id ) {
			return (int) $id;
		}
	}

	return 0;
}

/**
 * Company a user belongs to: the stored ID, else matched from the company name.
 */
function iuc_company_id_for_user( $user_id ) {
	$user_id = (int) $user_id;
	$id      = (int) get_user_meta( $user_id, 'iuc_company_id', true );

	if ( $id && 'iuc_company' === get_post_type( $id ) ) {
		return $id;
	}

	$name = function_exists( 'iuc_get_request_company' ) ? iuc_get_request_company( $user_id ) : '';
	$id   = iuc_company_id_by_name( $name );

	if ( $id ) {
		update_user_meta( $user_id, 'iuc_company_id', $id );
		update_user_meta( $user_id, 'iuc_company', get_the_title( $id ) );
	}

	return $id;
}

/**
 * Link a user to the company with this name.
 */
function iuc_company_link_user( $user_id, $name ) {
	$company_id = iuc_company_id_by_name( $name );

	update_user_meta( $user_id, 'iuc_company_id', $company_id );
	update_user_meta( $user_id, 'iuc_company', $company_id ? get_the_title( $company_id ) : trim( wp_unslash( (string) $name ) ) );

	return $company_id;
}

/* ---------------------------------------------------------------------
 * BuddyPress: "Företag" as a drop-down of companies
 * ------------------------------------------------------------------ */

function iuc_bp_xprofile_ready() {
	return function_exists( 'xprofile_insert_field' )
		&& function_exists( 'bp_is_active' )
		&& bp_is_active( 'xprofile' );
}

function iuc_bp_company_field_id() {
	if ( ! iuc_bp_xprofile_ready() || ! function_exists( 'xprofile_get_field_id_from_name' ) ) {
		return 0;
	}

	return (int) xprofile_get_field_id_from_name( IUC_BP_COMPANY_FIELD );
}

/**
 * Make sure the "Företag" field exists and is a drop-down.
 */
function iuc_bp_ensure_company_field() {
	if ( ! iuc_bp_xprofile_ready() ) {
		return 0;
	}

	$field_id = iuc_bp_company_field_id();

	if ( $field_id ) {
		$field = new BP_XProfile_Field( $field_id );

		if ( 'selectbox' === $field->type ) {
			return $field_id;
		}

		return (int) xprofile_insert_field(
			array(
				'field_id'       => $field_id,
				'field_group_id' => (int) $field->group_id,
				'parent_id'      => 0,
				'type'           => 'selectbox',
				'name'           => IUC_BP_COMPANY_FIELD,
				'description'    => $field->description,
				'is_required'    => (bool) $field->is_required,
				'can_delete'     => (bool) $field->can_delete,
				'order_by'       => 'asc',
			)
		);
	}

	return (int) xprofile_insert_field(
		array(
			'field_group_id' => function_exists( 'bp_xprofile_base_group_id' ) ? bp_xprofile_base_group_id() : 1,
			'parent_id'      => 0,
			'type'           => 'selectbox',
			'name'           => IUC_BP_COMPANY_FIELD,
			'description'    => 'Välj ert företag i listan. Saknas det? Kontakta IUC Syd.',
			'is_required'    => true,
			'can_delete'     => true,
			'order_by'       => 'asc',
		)
	);
}

/**
 * Options of the drop-down = approved companies.
 */
function iuc_bp_company_field_children( $children, $for_editing, $field ) {
	$field_id = iuc_bp_company_field_id();

	if ( ! $field_id || (int) $field->id !== $field_id ) {
		return $children;
	}

	// Keep the stored children only on BuddyPress's own field-setup screen.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( $for_editing && is_admin() && 'bp-profile-setup' === $page ) {
		return $children;
	}

	$companies = get_posts(
		array(
			'post_type'      => 'iuc_company',
			'post_status'    => 'publish',
			'posts_per_page' => 1000,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$options = array();

	foreach ( $companies as $index => $company ) {
		$options[] = (object) array(
			'id'                => (int) $company->ID,
			'group_id'          => (int) $field->group_id,
			'parent_id'         => $field_id,
			'type'              => 'option',
			'name'              => $company->post_title,
			'description'       => '',
			'is_required'       => 0,
			'can_delete'        => 0,
			'field_order'       => 0,
			'option_order'      => $index + 1,
			'order_by'          => 'asc',
			'is_default_option' => 0,
		);
	}

	return $options;
}
add_filter( 'bp_xprofile_field_get_children', 'iuc_bp_company_field_children', 10, 3 );

/**
 * When a member saves the "Företag" field, link the user to that company.
 */
function iuc_bp_link_user_on_save( $data ) {
	$field_id = iuc_bp_company_field_id();

	if ( ! $field_id || (int) $data->field_id !== $field_id ) {
		return;
	}

	iuc_company_link_user( (int) $data->user_id, (string) $data->value );
}
add_action( 'xprofile_data_after_save', 'iuc_bp_link_user_on_save' );

/* ---------------------------------------------------------------------
 * Keep names in sync when a company is renamed
 * ------------------------------------------------------------------ */

function iuc_company_sync_rename( $post_id, $after, $before ) {
	if ( 'iuc_company' !== $after->post_type || $after->post_title === $before->post_title ) {
		return;
	}

	$field_id = iuc_bp_company_field_id();
	$users    = get_users(
		array(
			'meta_key'   => 'iuc_company_id',
			'meta_value' => (string) $post_id,
			'fields'     => 'ID',
		)
	);

	foreach ( $users as $user_id ) {
		update_user_meta( $user_id, 'iuc_company', $after->post_title );

		if ( $field_id && function_exists( 'xprofile_set_field_data' ) ) {
			xprofile_set_field_data( $field_id, $user_id, $after->post_title );
		}
	}

	$requests = get_posts(
		array(
			'post_type'      => 'iuc_private_request',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'iuc_request_company_id',
			'meta_value'     => (string) $post_id,
		)
	);

	foreach ( $requests as $request_id ) {
		update_post_meta( $request_id, 'iuc_request_company', $after->post_title );
	}
}
add_action( 'post_updated', 'iuc_company_sync_rename', 10, 3 );

/* ---------------------------------------------------------------------
 * One-time setup and back-fill of links
 * ------------------------------------------------------------------ */

/**
 * Link existing BuddyPress values, user meta and requests to company posts.
 */
function iuc_company_backfill_links() {
	global $wpdb;

	$field_id = iuc_bp_company_field_id();

	if ( $field_id && function_exists( 'buddypress' ) ) {
		$table = buddypress()->profile->table_name_data;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM {$table} WHERE field_id = %d", $field_id ) );

		foreach ( (array) $rows as $row ) {
			iuc_company_link_user( (int) $row->user_id, $row->value );
		}
	}

	$users = get_users(
		array(
			'meta_key'     => 'iuc_company',
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
		)
	);

	foreach ( $users as $user_id ) {
		if ( ! (int) get_user_meta( $user_id, 'iuc_company_id', true ) ) {
			iuc_company_link_user( (int) $user_id, (string) get_user_meta( $user_id, 'iuc_company', true ) );
		}
	}

	$requests = get_posts(
		array(
			'post_type'      => 'iuc_private_request',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $requests as $request_id ) {
		if ( (int) get_post_meta( $request_id, 'iuc_request_company_id', true ) ) {
			continue;
		}

		$company_id = iuc_company_id_by_name( (string) get_post_meta( $request_id, 'iuc_request_company', true ) );

		if ( ! $company_id ) {
			$company_id = iuc_company_id_for_user( (int) get_post_field( 'post_author', $request_id ) );
		}

		if ( $company_id ) {
			update_post_meta( $request_id, 'iuc_request_company_id', $company_id );
		}
	}
}

function iuc_company_link_setup() {
	if ( IUC_LINK_SETUP_VERSION === get_option( 'iuc_link_setup_version' ) ) {
		return;
	}

	iuc_bp_ensure_company_field();
	iuc_company_backfill_links();

	update_option( 'iuc_link_setup_version', IUC_LINK_SETUP_VERSION );
}
add_action( 'admin_init', 'iuc_company_link_setup', 20 );

/* ---------------------------------------------------------------------
 * Requests: labels, admin columns, and lookup by company
 * ------------------------------------------------------------------ */

function iuc_request_type_labels() {
	return array(
		'need'     => 'Behov eller utmaning',
		'question' => 'Fråga till IUC Syd',
		'contact'  => 'Kontaktperson eller användare',
		'other'    => 'Annat',
	);
}

function iuc_request_status_labels() {
	return array(
		'new'         => 'Ny',
		'in_progress' => 'Pågår',
		'answered'    => 'Besvarad',
	);
}

function iuc_company_requests( $company_id, $limit = 20 ) {
	return get_posts(
		array(
			'post_type'      => 'iuc_private_request',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_key'       => 'iuc_request_company_id',
			'meta_value'     => (string) (int) $company_id,
		)
	);
}

function iuc_request_columns( $columns ) {
	$new = array();

	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;

		if ( 'title' === $key ) {
			$new['iuc_company'] = 'Företag';
			$new['iuc_type']    = 'Typ';
			$new['iuc_status']  = 'Status';
		}
	}

	return $new;
}
add_filter( 'manage_iuc_private_request_posts_columns', 'iuc_request_columns' );

function iuc_request_column_content( $column, $post_id ) {
	if ( 'iuc_company' === $column ) {
		$company_id = (int) get_post_meta( $post_id, 'iuc_request_company_id', true );
		$name       = (string) get_post_meta( $post_id, 'iuc_request_company', true );

		if ( $company_id && function_exists( 'iuc_company_card_url' ) ) {
			printf(
				'<a href="%s">%s</a>',
				esc_url( iuc_company_card_url( $company_id ) ),
				esc_html( get_the_title( $company_id ) )
			);
		} else {
			echo esc_html( $name ? $name : '–' );
		}
	}

	if ( 'iuc_type' === $column ) {
		$types = iuc_request_type_labels();
		$type  = (string) get_post_meta( $post_id, 'iuc_request_type', true );

		echo esc_html( isset( $types[ $type ] ) ? $types[ $type ] : '–' );
	}

	if ( 'iuc_status' === $column ) {
		$statuses = iuc_request_status_labels();
		$status   = (string) get_post_meta( $post_id, 'iuc_request_status', true );

		echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : '–' );
	}
}
add_action( 'manage_iuc_private_request_posts_custom_column', 'iuc_request_column_content', 10, 2 );
