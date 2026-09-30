<?php
// QuickCal (appointment booking) against the real plugin. QuickCal is sold on
// CodeCanyon, so it is tested only with your own copy: integrations.sh unzips
// ATT_QUICKCAL_ZIP into the test site and runs, one process per phase,
//
//   php quickcal-test.php activate|setup|book|admin|deactivate
//
// setup: an agent sets booking up from scratch (calendars, weekly and special
// slots, closed days, blocked slots, form, settings, emails, a page) and QuickCal's
// own functions must see exactly that. book: a visitor books through QuickCal's own
// booking handler over HTTP, then the agent approves, books, reschedules, cancels,
// deletes, and undoes. Needs the test server (integrations.sh starts it).
$_SERVER['REQUEST_METHOD'] = 'POST';
chdir( getenv( 'ATT_WP_DIR' ) ? getenv( 'ATT_WP_DIR' ) : __DIR__ . '/wordpress' );
$att_admin = isset( $argv[1] ) && 'admin' === $argv[1]; // renders MCP > Settings
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );

$phase = isset( $argv[1] ) ? $argv[1] : '';
$file  = 'quickcal/quickcal.php';
t_section( "quickcal: {$phase}" );

/** Monday to Sunday of the week after next (8 to 20 days ahead), in site time. */
function qc_week() {
	$today  = strtotime( current_time( 'Y-m-d' ) );
	$monday = $today + ( 8 - (int) gmdate( 'N', $today ) ) * DAY_IN_SECONDS + WEEK_IN_SECONDS;
	$out    = array();
	foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $i => $day ) {
		$out[ $day ] = gmdate( 'Y-m-d', $monday + $i * DAY_IN_SECONDS );
	}
	return $out;
}

/** Mail the site "sent" (mu-plugin.php records it), read fresh from the database. */
function qc_mails() {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'att_e2e_mail_log' ) );
	$value = $value ? maybe_unserialize( $value ) : array();
	return is_array( $value ) ? $value : array();
}

function qc_mailed( $to, $subject_part ) {
	foreach ( qc_mails() as $mail ) {
		if ( false !== stripos( implode( ',', (array) $mail['to'] ), $to ) && false !== strpos( (string) $mail['subject'], $subject_part ) ) {
			return true;
		}
	}
	return false;
}

function qc_free( $date, $slot, $calendar ) {
	return (int) booked_appt_is_available( $date, $slot, $calendar ); // QuickCal's own check
}

function qc_answer( $appointment, $label ) {
	foreach ( $appointment['answers'] as $answer ) {
		if ( $label === $answer['label'] ) {
			return $answer['value'];
		}
	}
	return null;
}

function qc_notes_have( $notes, $text ) {
	foreach ( (array) $notes as $note ) {
		if ( false !== strpos( $note, $text ) ) {
			return true;
		}
	}
	return false;
}

$w = qc_week();

switch ( $phase ) {
	case 'activate':
		$r = activate_plugin( $file );
		t_ok( 'activate QuickCal', ! is_wp_error( $r ), $r );
		t_ok( 'QuickCal is detected', att_mcp_detect_quickcal() );
		break;

	case 'deactivate':
		deactivate_plugins( $file );
		t_ok( 'deactivate QuickCal', ! is_plugin_active( $file ) );
		break;

	case 'admin':
		$_GET['page'] = 'att-mcp-abilities';
		set_current_screen( 'toplevel_page_att-mcp-abilities' );
		ob_start();
		att_mcp_settings_page();
		$html = ob_get_clean();
		t_ok( 'MCP > Settings shows the QuickCal card with its tools', false !== strpos( $html, 'data-addon-card="quickcal"' ) && false !== strpos( $html, 'att_mcp_abilities[att/quickcal-set-weekly-slots]' ) && false !== strpos( $html, 'att_mcp_abilities[att/quickcal-cancel-appointments]' ) );
		break;

	case 'setup':
		// A clean QuickCal (so this phase can be re-run).
		foreach ( get_terms( array( 'taxonomy' => 'booked_custom_calendars', 'hide_empty' => false, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_term( $id, 'booked_custom_calendars' );
		}
		foreach ( get_posts( array( 'post_type' => 'booked_appointments', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_post( $id, true );
		}
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'booked%' OR option_name LIKE 'taxonomy%'" ) as $name ) {
			delete_option( $name );
		}
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 's' => 'quickcal-calendar', 'fields' => 'ids' ) ) as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( array( 'qc_editor', 'qc_agent', 'qc_customer' ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user->ID );
			}
		}
		$admin_email = get_userdata( 1 )->user_email;

		foreach ( array_keys( att_mcp_quickcal_registry() ) as $name ) {
			t_ok( "{$name} is registered", wp_has_ability( $name ) );
		}

		// A fresh install: nothing bookable yet, and the tools say why.
		$s = t_run( 'att/quickcal-get-setup' );
		t_ok( 'get-setup: a fresh install has only the default calendar', is_array( $s ) && 1 === count( $s['calendars'] ) && 0 === $s['calendars'][0]['id'], $s );
		t_ok( '... and notes that nobody can book yet, no page shows a calendar and the emails are off', is_array( $s ) && qc_notes_have( $s['notes'], 'No time slots' ) && qc_notes_have( $s['notes'], 'No page shows' ) && qc_notes_have( $s['notes'], 'emails are off' ), is_array( $s ) ? $s['notes'] : $s );

		// Calendars.
		$r   = t_run( 'att/quickcal-save-calendar', array( 'name' => 'Consultations', 'description' => 'One-to-one advice', 'notification_email' => $admin_email ) );
		$cal = is_array( $r ) ? (int) $r['calendar']['id'] : 0;
		t_ok( 'save-calendar creates a calendar with its shortcode', $cal > 0 && $r['created'] && '[quickcal-calendar calendar=' . $cal . ']' === $r['calendar']['shortcode'] && $r['change_id'] > 0, $r );
		t_ok( '... and its notification email where QuickCal reads it', $admin_email === quickcal_get_calendar_notifications_user_id( $cal ) );
		t_ok( 'a second calendar with the same name is refused', is_wp_error( t_run( 'att/quickcal-save-calendar', array( 'name' => 'Consultations' ) ) ) );
		$editor = wp_insert_user( array( 'user_login' => 'qc_editor', 'user_pass' => wp_generate_password(), 'user_email' => 'qc_editor@example.com', 'role' => 'editor' ) );
		t_ok( 'the notification email must be an administrator\'s or Booking Agent\'s', 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-save-calendar', array( 'name' => 'Other', 'notification_email' => 'qc_editor@example.com' ) ) ) );
		$r    = t_run( 'att/quickcal-save-calendar', array( 'name' => 'Room B' ) );
		$cal2 = is_array( $r ) ? (int) $r['calendar']['id'] : 0;
		$r    = t_run( 'att/quickcal-save-calendar', array( 'id' => $cal2, 'name' => 'Room B (upstairs)' ) );
		t_ok( 'save-calendar renames a calendar', is_array( $r ) && ! $r['created'] && 'Room B (upstairs)' === get_term( $cal2 )->name, $r );

		// Weekly slots: Mon–Fri 9–12 hourly, Saturday one group slot with 2 spaces.
		$r = t_run( 'att/quickcal-set-weekly-slots', array(
			'calendar' => $cal,
			'generate' => array( array( 'days' => array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri' ), 'start' => '09:00', 'end' => '12:00', 'duration' => 60, 'title' => 'Consultation' ) ),
			'days'     => array( 'Saturday' => array( array( 'start' => '10:00', 'end' => '11:30', 'spots' => 2, 'title' => 'Group session' ) ) ),
		) );
		t_ok( 'set-weekly-slots', is_array( $r ) && 'own' === $r['weekly']['uses'] && 3 === count( $r['weekly']['days']['Mon'] ) && 1 === count( $r['weekly']['days']['Sat'] ) && ! $r['weekly']['days']['Sun'], $r );
		$raw = get_option( 'booked_defaults_' . $cal );
		t_ok( '... stored the way QuickCal\'s Time Slots screen stores it', is_array( $raw ) && 1 === $raw['Mon']['0900-1000'] && 'Consultation' === $raw['Mon-details']['0900-1000']['title'] && 2 === $raw['Sat']['1000-1130'] && array() === $raw['Sun'], $raw );
		t_ok( 'QuickCal now offers Monday 09:00 (1 space) and Saturday 10:00 (2), and nothing on Sunday', 1 === qc_free( $w['mon'], '0900-1000', $cal ) && 2 === qc_free( $w['sat'], '1000-1130', $cal ) && 0 === qc_free( $w['sun'], '0900-1000', $cal ) );
		$s = t_run( 'att/quickcal-get-setup', array( 'calendar' => $cal2, 'sections' => array( 'weekly_slots' ) ) );
		t_ok( 'the other calendar still follows the default calendar', is_array( $s ) && 'default calendar' === $s['weekly_slots'][0]['uses'], $s );
		$r = t_run( 'att/quickcal-set-weekly-slots', array( 'calendar' => $cal, 'merge' => true, 'days' => array( 'Mon' => array( array( 'start' => '13:00', 'end' => '14:00' ) ) ) ) );
		t_ok( '"merge" adds a slot to a weekday', is_array( $r ) && 4 === count( $r['weekly']['days']['Mon'] ) && 1 === qc_free( $w['mon'], '1300-1400', $cal ), $r );
		t_ok( 'bad slots are refused (end before start, a bad time, a bad weekday, 0 spots)',
			'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-set-weekly-slots', array( 'calendar' => $cal, 'days' => array( 'Mon' => array( array( 'start' => '10:00', 'end' => '09:00' ) ) ) ) ) )
			&& 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-set-weekly-slots', array( 'calendar' => $cal, 'days' => array( 'Mon' => array( array( 'start' => '25:00', 'end' => '26:00' ) ) ) ) ) )
			&& 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-set-weekly-slots', array( 'calendar' => $cal, 'days' => array( 'Funday' => array() ) ) ) )
			&& 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-set-weekly-slots', array( 'calendar' => $cal, 'days' => array( 'Mon' => array( array( 'start' => '09:00', 'end' => '10:00', 'spots' => 0 ) ) ) ) ) ) );
		$raw = get_option( 'booked_defaults_' . $cal );
		t_ok( 'refused calls change nothing', 4 === count( $raw['Mon'] ) && 3 === count( $raw['Tue'] ), $raw );

		// Special dates: Tuesday closed, Wednesday afternoon clinic only, Thursday 09:00 blocked.
		$r = t_run( 'att/quickcal-set-date-slots', array(
			'calendar' => $cal,
			'dates'    => array(
				array( 'date' => $w['tue'], 'closed' => true ),
				array( 'date' => $w['wed'], 'slots' => array( array( 'start' => '14:00', 'end' => '15:00', 'spots' => 3, 'title' => 'Late clinic' ) ) ),
			),
			'block'    => array( array( 'date' => $w['thu'], 'start' => '09:00' ) ),
		) );
		t_ok( 'set-date-slots', is_array( $r ) && 2 === count( $r['date_slots'] ) && 1 === count( $r['blocked_slots'] ) && $r['change_id'] > 0, $r );
		t_ok( 'QuickCal: the closed day has no slots', 0 === qc_free( $w['tue'], '0900-1000', $cal ) );
		t_ok( 'QuickCal: the special hours replace the weekly slots that day', 3 === qc_free( $w['wed'], '1400-1500', $cal ) && 0 === qc_free( $w['wed'], '0900-1000', $cal ) );
		t_ok( 'QuickCal: the blocked slot is off, the next one on', 0 === qc_free( $w['thu'], '0900-1000', $cal ) && 1 === qc_free( $w['thu'], '1000-1100', $cal ) );
		$stored = json_decode( get_option( 'booked_custom_timeslots_encoded' ), true );
		t_ok( '... stored in QuickCal\'s format (two entries = parallel lists)', is_array( $stored['booked_custom_start_date'] ) && array( $w['tue'], $w['wed'] ) === $stored['booked_custom_start_date'] && array( (string) $cal, (string) $cal ) === $stored['booked_custom_calendar_id'] && array( true, '' ) === $stored['vacationDayCheckbox'], $stored );
		$r      = t_run( 'att/quickcal-set-date-slots', array( 'calendar' => $cal, 'remove_dates' => array( $w['tue'] ) ) );
		$stored = json_decode( get_option( 'booked_custom_timeslots_encoded' ), true );
		t_ok( 'one entry left is stored with plain values, as QuickCal needs', is_array( $r ) && is_string( $stored['booked_custom_start_date'] ) && $w['wed'] === $stored['booked_custom_start_date'], $stored );
		t_ok( 'QuickCal: Tuesday is open again, Wednesday still special', 1 === qc_free( $w['tue'], '0900-1000', $cal ) && 3 === qc_free( $w['wed'], '1400-1500', $cal ) && 0 === qc_free( $w['wed'], '1000-1100', $cal ) );
		$r = t_run( 'att/quickcal-set-date-slots', array( 'calendar' => $cal, 'dates' => array( array( 'date' => $w['tue'], 'closed' => true ), array( 'date' => $w['fri'], 'slots' => array( array( 'start' => '09:00', 'end' => '10:00', 'title' => 'Tax & Audit <review>' ) ) ) ) ) );
		t_ok( 'Tuesday closed again, Friday special', is_array( $r ) && 0 === qc_free( $w['tue'], '0900-1000', $cal ) && 1 === qc_free( $w['fri'], '0900-1000', $cal ) && 0 === qc_free( $w['fri'], '1000-1100', $cal ), $r );
		$r = t_run( 'att/quickcal-set-date-slots', array( 'calendar' => 0, 'dates' => array( array( 'date' => $w['mon'], 'closed' => true ) ) ) );
		t_ok( 'a closed day on the default calendar does not close this calendar', is_array( $r ) && 0 === qc_free( $w['mon'], '0900-1000', 0 ) && 1 === qc_free( $w['mon'], '0900-1000', $cal ), $r );
		$s     = t_run( 'att/quickcal-get-setup', array( 'calendar' => $cal, 'sections' => array( 'date_slots' ) ) );
		$title = '';
		foreach ( is_array( $s ) ? $s['date_slots'] : array() as $entry ) {
			if ( $w['fri'] === $entry['date'] ) {
				$title = $entry['slots'][0]['title'];
			}
		}
		t_ok( 'a slot title with "&" survives re-saving (no double encoding)', 'Tax & Audit' === $title && false === strpos( (string) get_option( 'booked_custom_timeslots_encoded' ), '&amp;amp;' ), $title );
		$next = array_map( function ( $date ) { return gmdate( 'Y-m-d', strtotime( $date ) + WEEK_IN_SECONDS ); }, $w );
		t_ok( 'a closed range covers every day in it, and only those', ! is_wp_error( t_run( 'att/quickcal-set-date-slots', array( 'calendar' => $cal, 'dates' => array( array( 'date' => $next['mon'], 'end_date' => $next['wed'], 'closed' => true ) ) ) ) ) && 0 === qc_free( $next['mon'], '0900-1000', $cal ) && 0 === qc_free( $next['tue'], '0900-1000', $cal ) && 0 === qc_free( $next['wed'], '0900-1000', $cal ) && 1 === qc_free( $next['thu'], '0900-1000', $cal ) );
		t_ok( 'bad dates are refused', 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-set-date-slots', array( 'calendar' => $cal, 'dates' => array( array( 'date' => '2026-02-30', 'closed' => true ) ) ) ) ) && 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-set-date-slots', array( 'calendar' => $cal, 'dates' => array( array( 'date' => $w['wed'], 'end_date' => $w['mon'], 'closed' => true ) ) ) ) ) );
		$blocked = get_option( 'booked_disabled_timeslots' );
		t_ok( 'the blocked slot is stored where QuickCal looks', ! empty( $blocked[ $cal ][ $w['thu'] ]['0900-1000'] ), $blocked );

		// Booking form.
		$r = t_run( 'att/quickcal-set-form-fields', array(
			'calendar' => $cal,
			'fields'   => array(
				array( 'type' => 'text', 'label' => 'Phone', 'required' => true ),
				array( 'type' => 'dropdown', 'label' => 'Topic', 'options' => array( 'Tax', 'Audit' ), 'required' => true ),
				array( 'type' => 'checkboxes', 'label' => 'Extras', 'options' => array( 'Parking', 'Wheelchair access' ) ),
				array( 'type' => 'content', 'content' => '<p>Please bring <strong>ID</strong>.</p>' ),
			),
		) );
		t_ok( 'set-form-fields', is_array( $r ) && 'own' === $r['uses'] && 4 === count( $r['fields'] ) && array( 'Tax', 'Audit' ) === $r['fields'][1]['options'] && $r['fields'][0]['required'] && ! $r['fields'][2]['required'], $r );
		$stored = json_decode( stripslashes( get_option( 'booked_custom_fields_' . $cal ) ), true );
		$names  = is_array( $stored ) ? implode( ' ', wp_list_pluck( $stored, 'name' ) ) : '';
		t_ok( '... stored as QuickCal\'s form builder saves it', (bool) preg_match( '/^single-line-text-label---(\d+)___required drop-down-label---(\d+)___required single-drop-down---\2 single-drop-down---\2 checkboxes-label---(\d+) single-checkbox---\3 single-checkbox---\3 plain-text-content---\d+ required---\1 required---\2 required---\3$/', $names ) && true === $stored[8]['value'] && true === $stored[9]['value'] && false === $stored[10]['value'], $names );
		ob_start();
		booked_custom_fields( $cal );
		$form = ob_get_clean();
		t_ok( 'QuickCal renders the form: Phone required, the Topic options, the text', false !== strpos( $form, 'required="required" type="text" name="single-line-text-label---' ) && false !== strpos( $form, '<option value="Audit">Audit</option>' ) && false !== strpos( $form, 'Please bring <strong>ID</strong>.' ), $form );
		t_ok( 'a dropdown without options, or an unknown type, is refused', 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-set-form-fields', array( 'calendar' => $cal, 'fields' => array( array( 'type' => 'dropdown', 'label' => 'X' ) ) ) ) ) && is_wp_error( t_run( 'att/quickcal-set-form-fields', array( 'calendar' => $cal, 'fields' => array( array( 'type' => 'signature', 'label' => 'X' ) ) ) ) ) );
		update_option( 'booked_custom_fields_' . $cal2, wp_slash( wp_json_encode( array( array( 'name' => 'paid-service-label---1234567', 'value' => 'Service' ), array( 'name' => 'single-paid-service---1234567', 'value' => '42' ) ) ) ) );
		t_ok( 'a form with fields the tool cannot recreate is only replaced with "force"', 'att_mcp_unsupported_fields' === t_code( t_run( 'att/quickcal-set-form-fields', array( 'calendar' => $cal2, 'fields' => array() ) ) ) && ! is_wp_error( t_run( 'att/quickcal-set-form-fields', array( 'calendar' => $cal2, 'fields' => array(), 'force' => true ) ) ) && false === get_option( 'booked_custom_fields_' . $cal2 ) );

		// Settings.
		$r = t_run( 'att/quickcal-update-settings', array( 'booking_type' => 'guest', 'require_guest_email' => true, 'new_appointment_status' => 'pending', 'booking_buffer_hours' => 0, 'cancellation_buffer_hours' => 0.5, 'hide_weekends' => false, 'button_color' => '#123456', 'customer_reminder_minutes' => 60, 'guest_name_fields' => 'first_and_last' ) );
		t_ok( 'update-settings stores QuickCal\'s own values', is_array( $r ) && 'guest' === get_option( 'booked_booking_type' ) && 'true' === get_option( 'booked_require_guest_email_address' ) && 'draft' === get_option( 'booked_new_appointment_default' ) && '0.50' === get_option( 'booked_cancellation_buffer' ) && '#123456' === get_option( 'booked_button_color' ) && '60' === get_option( 'booked_reminder_buffer' ) && array( 'require_surname' ) === get_option( 'booked_registration_name_requirements' ), $r );
		t_ok( '... and reads them back', is_array( $r ) && 'guest' === $r['updated']['booking_type'] && 0.5 === $r['updated']['cancellation_buffer_hours'] && 'pending' === $r['updated']['new_appointment_status'] && true === $r['updated']['require_guest_email'], $r );
		t_ok( 'unknown settings and values are refused', is_wp_error( t_run( 'att/quickcal-update-settings', array( 'booking_type' => 'everyone' ) ) ) && 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-update-settings', array( 'booking_buffer_hours' => 7 ) ) ) && is_wp_error( t_run( 'att/quickcal-update-settings', array( 'nope' => 1 ) ) ) && 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-update-settings', array( 'notification_email' => 'qc_editor@example.com' ) ) ) );
		$u = t_run( 'att/undo-change', array( 'id' => is_array( $r ) ? $r['change_id'] : 0 ) );
		t_ok( 'undo restores the settings', is_array( $u ) && 'registered' === get_option( 'booked_booking_type', 'registered' ) && false === get_option( 'booked_cancellation_buffer' ), $u );
		$u = t_run( 'att/undo-change', array( 'id' => is_array( $u ) ? $u['redo_change_id'] : 0 ) );
		t_ok( '... and the undo can be undone', is_array( $u ) && 'guest' === get_option( 'booked_booking_type' ), $u );

		// Emails.
		$r = t_run( 'att/quickcal-update-emails', array( 'emails' => array(
			'customer_confirmation' => array( 'subject' => 'We got your booking, %name%', 'content' => '<p>%title% on %date% at %time%.</p>%customfields%' ),
			'customer_approval'     => array( 'subject' => 'Confirmed: %date% %time%', 'content' => '<p>See you then, %name%.</p>' ),
			'customer_cancellation' => array( 'subject' => 'Cancelled: %date%', 'content' => '<p>Your appointment was cancelled.</p>' ),
			'admin_new_appointment' => array( 'subject' => 'New booking: %name%', 'content' => '<p>%calendar%: %date% %time%</p>' ),
		) ) );
		t_ok( 'update-emails', is_array( $r ) && $r['active']['customer_confirmation'] && $r['active']['admin_new_appointment'] && ! $r['active']['customer_reminder'] && 'We got your booking, %name%' === get_option( 'booked_appt_confirmation_email_subject' ), $r );
		t_ok( 'an email switched on without a subject is refused, and so is an unknown email', 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-update-emails', array( 'emails' => array( 'admin_reminder' => array( 'enabled' => true ) ) ) ) ) && is_wp_error( t_run( 'att/quickcal-update-emails', array( 'emails' => array( 'nope' => array( 'subject' => 'x' ) ) ) ) ) );

		// QuickCal's private feed key stays private.
		update_option( 'quickcal_feed_hash', wp_generate_password( 48, false ) );
		t_ok( 'the private calendar-feed key cannot be read or written', is_wp_error( t_run( 'att/get-option', array( 'name' => 'quickcal_feed_hash' ) ) ) && is_wp_error( t_run( 'att/update-option', array( 'name' => 'quickcal_feed_hash', 'value' => 'x' ) ) ) );

		// A page that shows the calendar; the setup is complete.
		$page = t_run( 'att/create-page', array( 'title' => 'Book a consultation', 'content' => '<!-- wp:shortcode -->[quickcal-calendar calendar=' . $cal . ']<!-- /wp:shortcode -->', 'status' => 'publish' ) );
		$s    = t_run( 'att/quickcal-get-setup' );
		t_ok( 'get-setup lists the page, and nothing keeps customers from booking now', is_array( $s ) && is_array( $page ) && (int) $page['id'] === $s['pages'][0]['id'] && ! qc_notes_have( $s['notes'], 'No time slots' ) && ! qc_notes_have( $s['notes'], 'No page shows' ) && ! qc_notes_have( $s['notes'], 'registration is off' ), is_array( $s ) ? array( $s['pages'], $s['notes'] ) : $s );
		t_ok( '... and reads everything back', is_array( $s ) && 3 === count( $s['calendars'] ) && 'guest' === $s['settings']['booking_type'] && $s['emails']['customer_approval']['active'] && ! $s['emails']['admin_reminder']['active'] );

		// Free slots, exactly as QuickCal counts them.
		$a        = t_run( 'att/quickcal-get-availability', array( 'calendar' => $cal, 'from' => $w['mon'], 'days' => 7 ) );
		$mismatch = array();
		foreach ( is_array( $a ) ? $a['days'] : array() as $day ) {
			foreach ( $day['slots'] as $slot ) {
				if ( qc_free( $day['date'], $slot['slot'], $cal ) !== $slot['left'] ) {
					$mismatch[] = $day['date'] . ' ' . $slot['slot'];
				}
			}
		}
		t_ok( 'get-availability agrees with QuickCal on every slot of the week', is_array( $a ) && 7 === count( $a['days'] ) && ! $mismatch, $mismatch ? $mismatch : $a );
		t_ok( '... Tuesday closed, Wednesday special, Thursday 09:00 blocked', is_array( $a ) && 'closed' === $a['days'][1]['source'] && ! $a['days'][1]['slots'] && 'date' === $a['days'][2]['source'] && ! empty( $a['days'][3]['slots'][0]['blocked'] ) && ! $a['days'][3]['slots'][0]['bookable'] && $a['days'][3]['slots'][1]['bookable'] );

		// A Booking Agent manages only the calendars assigned to them; an Editor none.
		$agent = wp_insert_user( array( 'user_login' => 'qc_agent', 'user_pass' => wp_generate_password(), 'user_email' => 'qc_agent@example.com', 'role' => 'booked_booking_agent' ) );
		t_run( 'att/quickcal-save-calendar', array( 'id' => $cal2, 'notification_email' => 'qc_agent@example.com' ) );
		wp_set_current_user( $agent );
		t_ok( 'a Booking Agent sets the slots of their own calendar', ! is_wp_error( t_run( 'att/quickcal-set-weekly-slots', array( 'calendar' => $cal2, 'days' => array( 'Fri' => array( array( 'start' => '13:00', 'end' => '14:00' ) ) ) ) ) ) );
		t_ok( '... but not of another calendar or the default one', 'att_mcp_forbidden' === t_code( t_run( 'att/quickcal-set-weekly-slots', array( 'calendar' => $cal, 'days' => array( 'Fri' => array() ) ) ) ) && 'att_mcp_forbidden' === t_code( t_run( 'att/quickcal-set-form-fields', array( 'calendar' => 0, 'fields' => array() ) ) ) );
		t_ok( '... cannot change settings or emails', is_wp_error( t_run( 'att/quickcal-update-settings', array( 'hide_weekends' => true ) ) ) && is_wp_error( t_run( 'att/quickcal-update-emails', array( 'emails' => array() ) ) ) );
		$s = t_run( 'att/quickcal-get-setup' );
		t_ok( '... and sees only their calendar, without the settings', is_array( $s ) && array( $cal2 ) === wp_list_pluck( $s['calendars'], 'id' ) && ! isset( $s['settings'] ), $s );
		wp_set_current_user( $editor );
		t_ok( 'an Editor (no QuickCal role) cannot use the QuickCal tools', is_wp_error( t_run( 'att/quickcal-get-setup' ) ) && is_wp_error( t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['mon'], 'start' => '10:00', 'name' => 'X' ) ) ) );
		wp_set_current_user( 1 );

		update_option( 'att_e2e_qc', array( 'cal' => $cal, 'cal2' => $cal2, 'page' => is_array( $page ) ? (int) $page['id'] : 0, 'admin_email' => $admin_email ) );
		break;

	case 'book':
		$qc          = get_option( 'att_e2e_qc' );
		$cal         = (int) $qc['cal'];
		$admin_email = $qc['admin_email'];
		delete_option( 'att_e2e_mail_log' );

		// 1. A visitor books through QuickCal's own booking handler (guest booking, pending approval).
		$form  = json_decode( stripslashes( get_option( 'booked_custom_fields_' . $cal ) ), true );
		$field = array();
		foreach ( $form as $row ) {
			$type = strtok( $row['name'], '-' );
			if ( ! isset( $field[ $type ] ) ) {
				$field[ $type ] = $row['name'];
			}
		}
		wp_set_current_user( 0 );
		$nonce = wp_create_nonce( 'ajax-nonce' );
		wp_set_current_user( 1 );
		$response = wp_remote_post( admin_url( 'admin-ajax.php' ), array(
			'timeout' => 60,
			'body'    => array(
				'action'                   => 'booked_add_appt',
				'nonce'                    => $nonce,
				'date'                     => $w['mon'],
				'timestamp'                => (string) strtotime( $w['mon'] . ' 09:00' ),
				'timeslot'                 => '0900-1000',
				'calendar_id'              => $cal,
				'customer_type'            => 'guest',
				'guest_name'               => 'Ada',
				'guest_surname'            => 'Lovelace',
				'guest_email'              => 'ada@example.com',
				'title'                    => 'Consultation',
				$field['single']           => '555 0100',
				$field['drop']             => 'Audit',
				str_replace( 'checkboxes-label', 'single-checkbox', current( explode( '___', $field['checkboxes'] ) ) ) => array( 'Parking' ),
			),
		) );
		$body = is_wp_error( $response ) ? $response->get_error_message() : trim( wp_remote_retrieve_body( $response ) );
		t_ok( 'a visitor books Monday 09:00 through QuickCal\'s own booking form', 0 === strpos( $body, 'success###' ), $body );
		$list = t_run( 'att/quickcal-get-appointments', array( 'calendar' => $cal, 'from' => $w['mon'], 'to' => $w['mon'] ) );
		$ada  = is_array( $list ) && $list['appointments'] ? $list['appointments'][0] : array();
		t_ok( 'get-appointments shows it pending, with the guest and the form answers', $ada && 'pending' === $ada['status'] && 'Ada Lovelace' === $ada['customer']['name'] && 'ada@example.com' === $ada['customer']['email'] && '555 0100' === qc_answer( $ada, 'Phone' ) && 'Audit' === qc_answer( $ada, 'Topic' ) && 'Parking' === qc_answer( $ada, 'Extras' ) && 'Consultation' === $ada['title'], $list );
		$ada_id = $ada ? (int) $ada['id'] : 0;
		t_ok( 'QuickCal sent the confirmation the agent wrote, with tokens filled', qc_mailed( 'ada@example.com', 'We got your booking, Ada Lovelace' ), qc_mails() );
		t_ok( '... and told the calendar\'s notification email', qc_mailed( $admin_email, 'New booking: Ada Lovelace' ), qc_mails() );
		$a = t_run( 'att/quickcal-get-availability', array( 'calendar' => $cal, 'from' => $w['mon'], 'days' => 1 ) );
		t_ok( 'the slot is now taken', is_array( $a ) && 0 === $a['days'][0]['slots'][0]['left'] && 1 === $a['days'][0]['slots'][0]['booked'] && 0 === qc_free( $w['mon'], '0900-1000', $cal ), $a );

		// 2. Approve it: QuickCal's approval email goes out.
		$r = t_run( 'att/quickcal-save-appointment', array( 'id' => $ada_id, 'status' => 'approved' ) );
		t_ok( 'approve a pending appointment', is_array( $r ) && 'approved' === $r['appointment']['status'] && array( 'customer_approval' ) === $r['emailed'] && 'publish' === get_post_status( $ada_id ), $r );
		t_ok( '... and the customer got the approval email', qc_mailed( 'ada@example.com', 'Confirmed: ' ), qc_mails() );
		t_ok( 'an approved appointment cannot go back to pending', 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-save-appointment', array( 'id' => $ada_id, 'status' => 'pending' ) ) ) );

		// 3. Book for customers: a registered user quietly, then guests until the slot is full.
		$customer = wp_insert_user( array( 'user_login' => 'qc_customer', 'user_pass' => wp_generate_password(), 'user_email' => 'qc_customer@example.com', 'first_name' => 'Cleo', 'last_name' => 'Patra', 'role' => 'subscriber' ) );
		$mails    = count( qc_mails() );
		$r        = t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['sat'], 'start' => '10:00', 'user_id' => $customer, 'notify' => false, 'fields' => array( 'Phone' => '555 0101', 'Topic' => 'Tax' ) ) );
		$cleo     = is_array( $r ) ? (int) $r['appointment']['id'] : 0;
		t_ok( 'book for a registered customer (approved, no email)', $cleo && 'approved' === $r['appointment']['status'] && 'Group session' === $r['appointment']['title'] && (int) get_post_field( 'post_author', $cleo ) === $customer && $customer === (int) get_post_meta( $cleo, '_appointment_user', true ) && array() === $r['emailed'] && count( qc_mails() ) === $mails, $r );
		t_ok( '... stored as QuickCal stores it', $cleo && strtotime( $w['sat'] . ' 10:00' ) === (int) get_post_meta( $cleo, '_appointment_timestamp', true ) && '1000-1130' === get_post_meta( $cleo, '_appointment_timeslot', true ) && array( $cal ) === wp_get_object_terms( $cleo, 'booked_custom_calendars', array( 'fields' => 'ids' ) ) && 'publish' === get_post_status( $cleo ) && gmdate( 'Y-m-01 00:00:00', strtotime( $w['sat'] ) ) === get_post_field( 'post_date', $cleo ) );
		$r   = t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['sat'], 'start' => '10:00', 'name' => 'Bob', 'surname' => 'Builder', 'email' => 'bob@example.com', 'fields' => array( 'Topic' => 'Tax' ) ) );
		$bob = is_array( $r ) ? (int) $r['appointment']['id'] : 0;
		t_ok( 'the second space of the Saturday slot, with the approval email', $bob && array( 'customer_approval' ) === $r['emailed'] && qc_mailed( 'bob@example.com', 'Confirmed: ' ) && qc_notes_have( $r['notes'], 'Phone' ), $r );
		t_ok( 'QuickCal: Saturday 10:00 is full now', 0 === qc_free( $w['sat'], '1000-1130', $cal ) );
		t_ok( 'a third booking is refused as full', 'att_mcp_slot_unavailable' === t_code( t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['sat'], 'start' => '10:00', 'name' => 'Cy', 'email' => 'cy@example.com' ) ) ) );
		$r   = t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['sat'], 'start' => '10:00', 'name' => 'Cy', 'email' => 'cy@example.com', 'ignore_availability' => true, 'notify' => false ) );
		$cy  = is_array( $r ) ? (int) $r['appointment']['id'] : 0;
		t_ok( '... unless "ignore_availability" is set, as an admin can', $cy > 0, $r );
		$yesterday = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - DAY_IN_SECONDS );
		t_ok( 'a closed day, a blocked slot, a slot not offered and the past are refused',
			'att_mcp_slot_unavailable' === t_code( t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['tue'], 'slot' => '0900-1000', 'name' => 'X', 'email' => 'x@example.com' ) ) )
			&& 'att_mcp_slot_unavailable' === t_code( t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['thu'], 'start' => '09:00', 'name' => 'X', 'email' => 'x@example.com' ) ) )
			&& 'att_mcp_slot_unavailable' === t_code( t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['mon'], 'start' => '07:00', 'name' => 'X', 'email' => 'x@example.com' ) ) )
			&& 'att_mcp_slot_unavailable' === t_code( t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $yesterday, 'slot' => '0900-1000', 'name' => 'X', 'email' => 'x@example.com' ) ) ) );
		t_ok( 'guests need an email address (require_guest_email is on)', 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['fri'], 'start' => '09:00', 'name' => 'NoMail' ) ) ) );
		$r   = t_run( 'att/quickcal-save-appointment', array( 'calendar' => $cal, 'date' => $w['thu'], 'start' => '11:00', 'name' => 'Dee', 'email' => 'dee@example.com', 'status' => 'pending', 'fields' => array( 'Phone' => '1', 'Topic' => 'Tax' ) ) );
		$dee = is_array( $r ) ? (int) $r['appointment']['id'] : 0;
		t_ok( 'a pending booking sends the confirmation email instead', $dee && 'pending' === $r['appointment']['status'] && array( 'customer_confirmation' ) === $r['emailed'] && qc_mailed( 'dee@example.com', 'We got your booking, Dee' ), $r );

		// 4. Reschedule Ada to Wednesday's clinic, then undo.
		$r = t_run( 'att/quickcal-save-appointment', array( 'id' => $ada_id, 'date' => $w['wed'], 'start' => '14:00' ) );
		t_ok( 'reschedule to a special-hours slot (takes its title)', is_array( $r ) && $w['wed'] === $r['appointment']['date'] && '1400-1500' === $r['appointment']['slot'] && 'Late clinic' === $r['appointment']['title'] && array( 'time' ) === $r['changed'], $r );
		t_ok( 'QuickCal counts it in the new slot and frees the old one', 2 === qc_free( $w['wed'], '1400-1500', $cal ) && 1 === qc_free( $w['mon'], '0900-1000', $cal ) );
		$u = t_run( 'att/undo-change', array( 'id' => is_array( $r ) ? $r['change_id'] : 0 ) );
		t_ok( 'undo puts it back at Monday 09:00', is_array( $u ) && '0900-1000' === get_post_meta( $ada_id, '_appointment_timeslot', true ) && strtotime( $w['mon'] . ' 09:00' ) === (int) get_post_meta( $ada_id, '_appointment_timestamp', true ) && 'Consultation' === get_post_meta( $ada_id, '_appointment_title', true ) && 'publish' === get_post_status( $ada_id ), $u );
		t_ok( 'rescheduling into a full slot is refused', 'att_mcp_slot_unavailable' === t_code( t_run( 'att/quickcal-save-appointment', array( 'id' => $ada_id, 'date' => $w['sat'], 'start' => '10:00' ) ) ) );

		// 5. Details.
		$r = t_run( 'att/quickcal-save-appointment', array( 'id' => $ada_id, 'email' => 'ada.l@example.com', 'fields' => array( 'Phone' => '555 0199', 'Topic' => 'Tax' ) ) );
		t_ok( 'change a guest\'s email and answers', is_array( $r ) && 'ada.l@example.com' === $r['appointment']['customer']['email'] && '555 0199' === qc_answer( $r['appointment'], 'Phone' ) && null === qc_answer( $r['appointment'], 'Extras' ), $r );
		t_ok( '... but not a registered customer\'s, nor the calendar', 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-save-appointment', array( 'id' => $cleo, 'email' => 'x@example.com' ) ) ) && 'att_mcp_bad_input' === t_code( t_run( 'att/quickcal-save-appointment', array( 'id' => $cleo, 'calendar' => (int) $qc['cal2'] ) ) ) );

		// 6. Cancel: the upcoming appointment's customer is told; undo brings both back.
		delete_option( 'att_e2e_mail_log' );
		$before = array( get_post_meta( $ada_id ), get_post_meta( $bob ) );
		$r      = t_run( 'att/quickcal-cancel-appointments', array( 'ids' => array( $ada_id, $bob, 999999 ) ) );
		t_ok( 'cancel-appointments deletes them, as QuickCal does', is_array( $r ) && array( $ada_id, $bob ) === $r['cancelled'] && array( 999999 ) === $r['not_found'] && null === get_post( $ada_id ) && null === get_post( $bob ), $r );
		t_ok( '... and QuickCal emailed the customers', is_array( $r ) && array( $ada_id, $bob ) === $r['emailed'] && qc_mailed( 'ada.l@example.com', 'Cancelled: ' ) && qc_mailed( 'bob@example.com', 'Cancelled: ' ), qc_mails() );
		$u = t_run( 'att/undo-change', array( 'id' => is_array( $r ) ? $r['change_id'] : 0 ) );
		t_ok( 'undo brings them back under the same ids, with their details and calendar', is_array( $u ) && 'publish' === get_post_status( $ada_id ) && 'publish' === get_post_status( $bob ) && array( get_post_meta( $ada_id ), get_post_meta( $bob ) ) == $before && array( $cal ) === wp_get_object_terms( $ada_id, 'booked_custom_calendars', array( 'fields' => 'ids' ) ), $u );
		t_ok( 'QuickCal counts them again', 0 === qc_free( $w['mon'], '0900-1000', $cal ) );

		// 7. A calendar with appointments cannot be deleted; an emptied one can, and undo restores it all.
		t_ok( 'delete-calendar refuses a calendar with appointments', 'att_mcp_calendar_in_use' === t_code( t_run( 'att/quickcal-delete-calendar', array( 'id' => $cal ) ) ) );
		$all = t_run( 'att/quickcal-get-appointments', array( 'calendar' => $cal, 'from' => current_time( 'Y-m-d' ), 'to' => gmdate( 'Y-m-d', strtotime( $w['sun'] ) + WEEK_IN_SECONDS ), 'limit' => 200 ) );
		t_ok( 'get-appointments lists all five', is_array( $all ) && 5 === $all['total'], $all );
		$r = t_run( 'att/quickcal-cancel-appointments', array( 'ids' => wp_list_pluck( is_array( $all ) ? $all['appointments'] : array(), 'id' ), 'notify' => false ) );
		t_ok( 'cancel them all without emails', is_array( $r ) && 5 === count( $r['cancelled'] ) && array() === $r['emailed'], $r );
		$weekly  = get_option( 'booked_defaults_' . $cal );
		$fields  = get_option( 'booked_custom_fields_' . $cal );
		$entries = get_option( 'booked_custom_timeslots_encoded' );
		$r       = t_run( 'att/quickcal-delete-calendar', array( 'id' => $cal ) );
		$blocked = get_option( 'booked_disabled_timeslots' );
		t_ok( 'delete-calendar removes it with its slots, form, dates and blocked slots', is_array( $r ) && null === get_term( $cal, 'booked_custom_calendars' ) && false === get_option( 'booked_defaults_' . $cal ) && false === get_option( 'booked_custom_fields_' . $cal ) && false === strpos( (string) get_option( 'booked_custom_timeslots_encoded' ), '"' . $cal . '"' ) && ! isset( $blocked[ $cal ] ), $r );
		$u = t_run( 'att/undo-change', array( 'id' => is_array( $r ) ? $r['change_id'] : 0 ) );
		t_ok( 'undo restores the calendar under the same id, with everything', is_array( $u ) && 'Consultations' === get_term( $cal, 'booked_custom_calendars' )->name && $weekly === get_option( 'booked_defaults_' . $cal ) && $fields === get_option( 'booked_custom_fields_' . $cal ) && $entries === get_option( 'booked_custom_timeslots_encoded' ) && $admin_email === quickcal_get_calendar_notifications_user_id( $cal ), $u );
		t_ok( 'QuickCal sees it all again', 3 === qc_free( $w['wed'], '1400-1500', $cal ) && 0 === qc_free( $w['thu'], '0900-1000', $cal ) && 1 === qc_free( $w['mon'], '0900-1000', $cal ) );
		$r = t_run( 'att/quickcal-save-calendar', array( 'name' => 'Temporary' ) );
		$u = t_run( 'att/undo-change', array( 'id' => is_array( $r ) ? $r['change_id'] : 0 ) );
		t_ok( 'undoing a new calendar deletes it', is_array( $u ) && is_array( $r ) && null === get_term( (int) $r['calendar']['id'], 'booked_custom_calendars' ), $u );
		$page = t_run( 'att/create-page', array( 'title' => 'Old booking page', 'content' => '[quickcal-calendar calendar=987654]', 'status' => 'draft' ) );
		$s    = t_run( 'att/quickcal-get-setup', array( 'sections' => array( 'pages' ) ) );
		t_ok( 'get-setup warns about a page showing a calendar that does not exist', is_array( $s ) && qc_notes_have( $s['notes'], 'shows calendar 987654, which does not exist' ), is_array( $s ) ? $s['notes'] : $s );
		wp_delete_post( is_array( $page ) ? (int) $page['id'] : 0, true );

		// 8. The booking page renders QuickCal's calendar on the live site.
		$r = t_run( 'att/render-page', array( 'id' => (int) $qc['page'], 'strip_scripts' => true ) );
		t_ok( 'the booking page shows QuickCal\'s calendar', is_array( $r ) && 200 === $r['status'] && false !== strpos( $r['html'], 'booked-calendar' ), is_array( $r ) ? $r['status'] : $r );
		break;

	default:
		fwrite( STDERR, "Unknown phase {$phase}\n" );
		exit( 2 );
}
t_finish();
