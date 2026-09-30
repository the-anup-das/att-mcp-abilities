<?php
/**
 * QuickCal (appointment booking).
 *
 * QuickCal keeps its whole setup in WordPress options and its appointments as
 * posts, in the formats of the Booked plugin it grew from, and has no API or REST
 * routes of its own. These abilities read and write exactly what QuickCal's own
 * screens store, so an agent can set booking up end to end and QuickCal's screens
 * keep working with the result:
 *
 *  att/quickcal-get-setup            calendars, weekly and date-specific slots, blocked slots,
 *                                    booking forms, settings, emails, pages with a calendar
 *  att/quickcal-get-availability     free spaces per day and time slot
 *  att/quickcal-get-appointments     appointments with customer details
 *  att/quickcal-save-calendar        create or edit a calendar (term of booked_custom_calendars)
 *  att/quickcal-delete-calendar      delete an unused calendar with its slots and form
 *  att/quickcal-set-weekly-slots     booked_defaults / booked_defaults_{id}
 *  att/quickcal-set-date-slots       booked_custom_timeslots_encoded + booked_disabled_timeslots
 *  att/quickcal-set-form-fields      booked_custom_fields / booked_custom_fields_{id}
 *  att/quickcal-update-settings      QuickCal's settings (booked_* options)
 *  att/quickcal-update-emails        QuickCal's email subjects and texts
 *  att/quickcal-save-appointment     book, reschedule, edit or approve an appointment
 *  att/quickcal-cancel-appointments  cancel (delete) appointments, as QuickCal does
 *
 * Times are QuickCal's "local timestamps": the site's local date and time read as
 * if it were UTC (strtotime() of the local time under WordPress's UTC default).
 *
 * Every write is undoable: options through the option history type, calendars and
 * appointments through quickcal_calendar / quickcal_appointment (restored under
 * their own ids). QuickCal's private calendar-feed key (quickcal_feed_hash) is never
 * read or written (att_mcp_secret_pattern()).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Registry entries for the QuickCal addon (added only while QuickCal is active). */
function att_mcp_quickcal_registry() {
    $e = 'att_mcp_registry_entry';
    return array(
        'att/quickcal-get-setup'           => $e( __( 'Read Booking Setup', 'att-mcp-abilities' ), __( 'Calendars, weekly and date-specific time slots, closed days, booking forms, settings, emails, and the pages that show a calendar.', 'att-mcp-abilities' ), 'QuickCal', 'read' ),
        'att/quickcal-get-availability'    => $e( __( 'Read Free Time Slots', 'att-mcp-abilities' ), __( 'Free spaces per day and time slot, as customers see them.', 'att-mcp-abilities' ), 'QuickCal', 'read' ),
        'att/quickcal-get-appointments'    => $e( __( 'Read Appointments', 'att-mcp-abilities' ), __( "Appointments with the customers' names, email addresses and booking-form answers.", 'att-mcp-abilities' ), 'QuickCal', 'read' ),
        'att/quickcal-save-calendar'       => $e( __( 'Save Calendar', 'att-mcp-abilities' ), __( 'Create or rename a calendar and choose who gets its booking emails. Undoable.', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
        'att/quickcal-delete-calendar'     => $e( __( 'Delete Calendar', 'att-mcp-abilities' ), __( 'Delete a calendar that has no appointments, with its time slots and booking form. Undoable.', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
        'att/quickcal-set-weekly-slots'    => $e( __( 'Set Weekly Time Slots', 'att-mcp-abilities' ), __( 'The regular weekly hours: time slots per weekday, with spaces and titles. Undoable.', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
        'att/quickcal-set-date-slots'      => $e( __( 'Set Special Dates & Closed Days', 'att-mcp-abilities' ), __( 'Different hours on particular dates, closed days (holidays) and single blocked time slots. Undoable.', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
        'att/quickcal-set-form-fields'     => $e( __( 'Set Booking Form', 'att-mcp-abilities' ), __( 'The questions customers answer when booking (text, paragraph, checkboxes, radio buttons, drop-down, information text). Undoable.', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
        'att/quickcal-update-settings'     => $e( __( 'Update Booking Settings', 'att-mcp-abilities' ), __( 'Guest or account booking, approval, booking window and buffers, limits, display options, colors, reminders. Undoable.', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
        'att/quickcal-update-emails'       => $e( __( 'Update Booking Emails', 'att-mcp-abilities' ), __( 'Subjects and texts of the emails to customers and admins (confirmation, approval, cancellation, reminders, registration). Undoable.', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
        'att/quickcal-save-appointment'    => $e( __( 'Book / Edit Appointment', 'att-mcp-abilities' ), __( 'Book an appointment for a customer, or reschedule, edit or approve one; can email the customer. Undoable.', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
        'att/quickcal-cancel-appointments' => $e( __( 'Cancel Appointments', 'att-mcp-abilities' ), __( 'Cancel appointments as QuickCal does: they are deleted, and customers of upcoming ones get the cancellation email. Undoable (sent emails stay sent).', 'att-mcp-abilities' ), 'QuickCal', 'write' ),
    );
}

/** JSON schema of one time slot (weekly and date-specific slots). */
function att_mcp_quickcal_slot_schema() {
    return array(
        'type'       => 'object',
        'properties' => array(
            'start'   => array( 'type' => 'string', 'description' => '24-hour start time, e.g. "09:00".' ),
            'end'     => array( 'type' => 'string', 'description' => '24-hour end time, e.g. "10:00" ("24:00" = midnight).' ),
            'slot'    => array( 'type' => 'string', 'description' => 'Instead of start/end: the slot id "HHMM-HHMM" that att/quickcal-get-setup shows, e.g. "0900-1000".' ),
            'all_day' => array( 'type' => 'boolean', 'description' => 'One slot for the whole day, instead of start/end.' ),
            'spots'   => array( 'type' => 'integer', 'description' => 'How many appointments fit in the slot (1-500). Default 1.' ),
            'title'   => array( 'type' => 'string', 'description' => 'Optional title customers see, e.g. "Consultation".' ),
        ),
    );
}

function att_mcp_register_quickcal_abilities() {
    $base     = att_mcp_ability_base();
    $slot     = att_mcp_quickcal_slot_schema();
    $staff    = function () { return current_user_can( 'edit_booked_appointments' ); };
    $managers = function () { return current_user_can( 'manage_booked_options' ); };
    $calendar = array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Calendar id from att/quickcal-get-setup; 0 or omitted = the default calendar.' );

    if ( att_mcp_is_enabled( 'att/quickcal-get-setup' ) ) {
        att_mcp_register( 'att/quickcal-get-setup', array_merge( $base, array(
            'label'               => 'Read QuickCal Booking Setup',
            'description'         => 'Reads how QuickCal (appointment booking) is set up: the calendars (id 0 = the default calendar) with their shortcode and notification email; the weekly time slots of each calendar ("uses": "default calendar" when a calendar has none of its own and follows the default calendar\'s); date-specific entries (special hours and closed days) and single blocked slots; the booking form of each calendar; every setting (the keys att/quickcal-update-settings takes); the emails (the keys att/quickcal-update-emails takes; an email is only sent when both its subject and text are set); the pages showing a QuickCal shortcode; and notes on what still keeps customers from booking. Pass "sections" to read only part of it. Start here before changing anything.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'calendar' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Only this calendar (0 = the default calendar). Omit for all.' ),
                    'sections' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string', 'enum' => array( 'calendars', 'weekly_slots', 'date_slots', 'form_fields', 'settings', 'emails', 'pages' ) ),
                        'description' => 'Parts to read. Default: all.',
                    ),
                ),
            ),
            'permission_callback' => $staff,
            'execute_callback'    => 'att_mcp_execute_quickcal_get_setup',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-get-availability' ) ) {
        att_mcp_register( 'att/quickcal-get-availability', array_merge( $base, array(
            'label'               => 'Read QuickCal Free Time Slots',
            'description'         => 'Free spaces per day and time slot in one QuickCal calendar, counted the way QuickCal does when a customer books: the weekly slots (replaced on dates that have a date-specific entry), minus blocked slots and existing appointments (pending ones count too). "bookable" also applies the booking window, the booking buffer and the current time. Use it to check a setup or to find a time before booking for a customer. Default: the next 14 days.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'calendar' => $calendar,
                    'from'     => array( 'type' => 'string', 'description' => 'First day, YYYY-MM-DD. Default today.' ),
                    'days'     => array( 'type' => 'integer', 'description' => 'Number of days (1-62). Default 14.' ),
                ),
            ),
            'permission_callback' => $staff,
            'execute_callback'    => 'att_mcp_execute_quickcal_get_availability',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-get-appointments' ) ) {
        att_mcp_register( 'att/quickcal-get-appointments', array_merge( $base, array(
            'label'               => 'Read QuickCal Appointments',
            'description'         => 'Lists QuickCal appointments: date, time slot, calendar, status (pending or approved), the customer (a guest or a registered user: name and email) and their booking-form answers. This is customers\' personal data: use it only to manage bookings. Default: from today for 30 days, any status, 50 at most. Pass "id" for one appointment.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'       => array( 'type' => 'integer', 'description' => 'One appointment.' ),
                    'calendar' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Only this calendar (0 = the default calendar). Omit for all calendars you manage.' ),
                    'from'     => array( 'type' => 'string', 'description' => 'YYYY-MM-DD. Default today.' ),
                    'to'       => array( 'type' => 'string', 'description' => 'YYYY-MM-DD (inclusive). Default 30 days after "from".' ),
                    'status'   => array( 'type' => 'string', 'enum' => array( 'pending', 'approved', 'any' ), 'description' => 'Default any.' ),
                    'limit'    => array( 'type' => 'integer', 'description' => '1-200. Default 50.' ),
                ),
            ),
            'permission_callback' => $staff,
            'execute_callback'    => 'att_mcp_execute_quickcal_get_appointments',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-save-calendar' ) ) {
        att_mcp_register( 'att/quickcal-save-calendar', array_merge( $base, array(
            'label'               => 'Save QuickCal Calendar',
            'description'         => 'Creates a QuickCal calendar (e.g. one per service, room or staff member), or edits one with "id". "notification_email" chooses who receives the calendar\'s booking emails and, when that user is a Booking Agent, lets them manage it; it must be the email of an administrator or a Booking Agent ("" = QuickCal\'s default notification email). A new calendar has no time slots or form of its own: it uses the default calendar\'s weekly slots and booking form until you set its own (att/quickcal-set-weekly-slots, att/quickcal-set-form-fields). Customers see it on a page with the returned shortcode (e.g. add it with att/create-page). Undoable with att/undo-change.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'                 => array( 'type' => 'integer', 'description' => 'Calendar to edit. Omit to create one.' ),
                    'name'               => array( 'type' => 'string', 'description' => 'Required for a new calendar.' ),
                    'description'        => array( 'type' => 'string' ),
                    'slug'               => array( 'type' => 'string' ),
                    'notification_email' => array( 'type' => 'string', 'description' => 'Email of an administrator or Booking Agent; "" for the default.' ),
                ),
            ),
            'permission_callback' => $managers,
            'execute_callback'    => 'att_mcp_execute_quickcal_save_calendar',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => false ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-delete-calendar' ) ) {
        att_mcp_register( 'att/quickcal-delete-calendar', array_merge( $base, array(
            'label'               => 'Delete QuickCal Calendar',
            'description'         => 'Deletes a QuickCal calendar that has no appointments (cancel them first with att/quickcal-cancel-appointments, or keep the calendar), together with its own weekly slots, booking form, date-specific entries and blocked slots. Pages showing its shortcode then show the default calendar: update them. Undoable with att/undo-change (the calendar comes back under the same id).',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'id' ),
                'properties' => array(
                    'id' => array( 'type' => 'integer', 'description' => 'Calendar id (not 0: the default calendar cannot be deleted).' ),
                ),
            ),
            'permission_callback' => $managers,
            'execute_callback'    => 'att_mcp_execute_quickcal_delete_calendar',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-set-weekly-slots' ) ) {
        att_mcp_register( 'att/quickcal-set-weekly-slots', array_merge( $base, array(
            'label'               => 'Set QuickCal Weekly Time Slots',
            'description'         => 'Sets the regular weekly time slots of a QuickCal calendar, as its Time Slots screen does. "days" replaces the listed weekdays, e.g. {"Mon": [{"start": "09:00", "end": "10:00", "spots": 1, "title": "Consultation"}], "Sun": []} (an empty list closes that weekday). "generate" fills weekdays with back-to-back slots, e.g. [{"days": ["Mon","Tue","Wed","Thu","Fri"], "start": "09:00", "end": "17:00", "duration": 60, "gap": 0, "spots": 1}]; it adds to "days" given for the same weekday. Weekdays you leave out keep their slots; with "merge": true the given slots are added to a weekday instead of replacing it. A calendar without slots of its own follows the default calendar\'s: once you set any weekday for it, it has its own week (weekdays not set are closed), and "use_default": true makes it follow the default calendar again. "clear": true removes every weekly slot. Customers can book only slots that exist, so set these before publishing a calendar. Undoable.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'calendar'    => $calendar,
                    'days'        => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'array', 'items' => $slot ), 'description' => 'Weekday (Mon…Sun) => its time slots.' ),
                    'generate'    => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'object' ),
                        'description' => 'Back-to-back slots: a list of {"days", "start", "end", "duration" (minutes, default 60), "gap" (minutes between slots, default 0), "spots", "title"}.',
                    ),
                    'merge'       => array( 'type' => 'boolean', 'description' => 'Add to the weekdays\' slots instead of replacing them.' ),
                    'clear'       => array( 'type' => 'boolean', 'description' => 'Remove every weekly slot (first, when combined).' ),
                    'use_default' => array( 'type' => 'boolean', 'description' => 'Drop this calendar\'s own weekly slots so it follows the default calendar.' ),
                ),
            ),
            'permission_callback' => $staff,
            'execute_callback'    => 'att_mcp_execute_quickcal_set_weekly_slots',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => true, 'idempotent' => true ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-set-date-slots' ) ) {
        att_mcp_register( 'att/quickcal-set-date-slots', array_merge( $base, array(
            'label'               => 'Set QuickCal Special Dates, Closed Days and Blocked Slots',
            'description'         => 'Changes the dates on which a QuickCal calendar differs from its weekly slots, as its Custom Time Slots screen does. "dates" adds entries: special hours, e.g. {"date": "2026-12-24", "slots": [{"start": "09:00", "end": "12:00"}]}, or closed days, e.g. {"date": "2026-12-25", "end_date": "2026-12-26", "closed": true}; an entry replaces all weekly slots on its dates, and replaces this calendar\'s earlier entry with the same start date. "remove_dates" deletes this calendar\'s entries by start date, "clear": true all of them. "block" and "unblock" switch single time slots off or back on for one date, without changing the others: [{"date": "2026-10-06", "start": "09:00"}] or "slot": "0900-1000". Applied in this order: clear, remove_dates, dates, block, unblock. Undoable.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'calendar'     => $calendar,
                    'dates'        => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'date'     => array( 'type' => 'string', 'description' => 'YYYY-MM-DD.' ),
                                'end_date' => array( 'type' => 'string', 'description' => 'Optional last day (YYYY-MM-DD) for a range.' ),
                                'closed'   => array( 'type' => 'boolean', 'description' => 'No appointments on these dates.' ),
                                'slots'    => array( 'type' => 'array', 'items' => $slot, 'description' => 'The time slots on these dates (when not closed).' ),
                            ),
                        ),
                    ),
                    'remove_dates' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Start dates (YYYY-MM-DD) of entries to delete.' ),
                    'clear'        => array( 'type' => 'boolean', 'description' => 'Delete all date-specific entries of this calendar.' ),
                    'block'        => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => '[{"date", "start" | "slot"}]: time slots to switch off on a date.' ),
                    'unblock'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Blocked time slots to switch back on (same format).' ),
                ),
            ),
            'permission_callback' => $staff,
            'execute_callback'    => 'att_mcp_execute_quickcal_set_date_slots',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => true, 'idempotent' => false ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-set-form-fields' ) ) {
        att_mcp_register( 'att/quickcal-set-form-fields', array_merge( $base, array(
            'label'               => 'Set QuickCal Booking Form',
            'description'         => 'Sets the booking form of a QuickCal calendar: the questions customers answer when booking (QuickCal asks for their name and email itself). "fields" replaces the whole form, in order. Each field is {"type": "text" | "paragraph", "label", "required"}, {"type": "checkboxes" | "radio" | "dropdown", "label", "required", "options": ["…"]}, or {"type": "content", "content": "<p>HTML shown in the form</p>"}. An empty list removes the form. A calendar other than the default one without a form of its own uses the default calendar\'s; "use_default": true drops its own form. Answers appear in the appointment (att/quickcal-get-appointments) and in emails as %customfields%. Undoable.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'calendar'    => $calendar,
                    'fields'      => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'type'     => array( 'type' => 'string', 'enum' => array( 'text', 'paragraph', 'checkboxes', 'radio', 'dropdown', 'content' ) ),
                                'label'    => array( 'type' => 'string' ),
                                'required' => array( 'type' => 'boolean' ),
                                'options'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                                'content'  => array( 'type' => 'string', 'description' => 'HTML, for type "content".' ),
                            ),
                        ),
                    ),
                    'use_default' => array( 'type' => 'boolean' ),
                    'force'       => array( 'type' => 'boolean', 'description' => 'Replace a form that has fields this tool cannot recreate (e.g. WooCommerce paid services).' ),
                ),
            ),
            'permission_callback' => $staff,
            'execute_callback'    => 'att_mcp_execute_quickcal_set_form_fields',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => true, 'idempotent' => true ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-update-settings' ) ) {
        $props = array();
        $help  = array();
        foreach ( att_mcp_quickcal_settings_map() as $key => $cfg ) {
            $types = array( 'flag' => 'boolean', 'page' => 'integer', 'number' => 'number' );
            $props[ $key ] = array( 'type' => isset( $types[ $cfg['type'] ] ) ? $types[ $cfg['type'] ] : 'string', 'description' => $cfg['help'] );
            if ( 'choice' === $cfg['type'] ) {
                $props[ $key ]['enum'] = array_keys( $cfg['choices'] );
            }
            $help[] = $key;
        }
        att_mcp_register( 'att/quickcal-update-settings', array_merge( $base, array(
            'label'               => 'Update QuickCal Settings',
            'description'         => 'Changes QuickCal settings: pass only the keys to change (att/quickcal-get-setup lists them with their current values). The main ones: booking_type ("guest" = anyone can book with a name and email; "registered" = customers need an account), new_appointment_status ("pending" = you approve each booking; "approved"), booking_buffer_hours (minimum notice), bookable_from / bookable_until (the booking window), appointment_limit (upcoming appointments per customer), cancellation_buffer_hours, the display options, colors, notification_email, and the reminder timings. Keys: ' . implode( ', ', $help ) . '. Undoable.',
            'input_schema'        => array(
                'type'                 => 'object',
                'properties'           => $props,
                'additionalProperties' => false,
            ),
            'permission_callback' => $managers,
            'execute_callback'    => 'att_mcp_execute_quickcal_update_settings',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-update-emails' ) ) {
        $emails = array();
        foreach ( att_mcp_quickcal_emails_map() as $key => $email ) {
            $emails[ $key ] = array(
                'type'        => 'object',
                'description' => $email[2],
                'properties'  => array(
                    'subject' => array( 'type' => 'string' ),
                    'content' => array( 'type' => 'string', 'description' => 'HTML.' ),
                    'enabled' => array( 'type' => 'boolean', 'description' => 'false turns the email off (empties its subject).' ),
                ),
            );
        }
        att_mcp_register( 'att/quickcal-update-emails', array_merge( $base, array(
            'label'               => 'Update QuickCal Emails',
            'description'         => 'Sets the emails QuickCal sends. "emails" maps an email to {"subject", "content" (HTML), "enabled"}: customer_confirmation (to the customer when they book), customer_approval (when their appointment is approved, or booked for them by an admin), customer_cancellation (when an upcoming appointment is cancelled), customer_reminder (before the appointment), customer_registration (when booking created their account), admin_new_appointment (to the calendar\'s notification email when a customer books), admin_cancellation (when a customer cancels), admin_reminder. QuickCal sends an email only when both its subject and content are set, and a fresh install has none set; "enabled": false turns one off by emptying its subject. Tokens: %name% %email% %title% %calendar% %date% %time% %customfields% %id% (customer_registration: %name% %email% %username% %password%). Reminder timings are settings (customer_reminder_minutes, admin_reminder_minutes). Undoable.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'emails' ),
                'properties' => array(
                    'emails' => array( 'type' => 'object', 'properties' => $emails, 'additionalProperties' => false ),
                ),
            ),
            'permission_callback' => $managers,
            'execute_callback'    => 'att_mcp_execute_quickcal_update_emails',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-save-appointment' ) ) {
        att_mcp_register( 'att/quickcal-save-appointment', array_merge( $base, array(
            'label'               => 'Book or Edit a QuickCal Appointment',
            'description'         => 'Books a QuickCal appointment for a customer, or changes one ("id" from att/quickcal-get-appointments). To book: "date" (YYYY-MM-DD), "start" ("09:00"; or "slot": "0900-1000"), "calendar", and the customer: "user_id" of a registered user, or a guest "name" (+ "surname", "email"; the email is required when require_guest_email is on), plus optional "fields" (booking-form answers, e.g. {"Phone": "555 0100"}) and "title" (default: the slot\'s title). The slot must be offered that day, have a free space and not be in the past; "ignore_availability": true books it anyway, as an admin can. "status": "approved" (default, as in QuickCal\'s admin screen) or "pending". "notify" (default true) emails the customer as QuickCal does: the approval email for approved bookings, the confirmation email for pending ones. To change one: "date" / "start" / "slot" reschedules it (same calendar), plus "title", "fields", and a guest\'s "name" / "surname" / "email"; "status": "approved" approves a pending one (and emails the customer unless "notify" is false). Undoable (sent emails stay sent).',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'                  => array( 'type' => 'integer', 'description' => 'Appointment to change. Omit to book a new one.' ),
                    'calendar'            => $calendar,
                    'date'                => array( 'type' => 'string', 'description' => 'YYYY-MM-DD.' ),
                    'start'               => array( 'type' => 'string', 'description' => '24-hour start time of the slot, e.g. "09:00".' ),
                    'end'                 => array( 'type' => 'string', 'description' => 'Only to tell apart slots starting at the same time.' ),
                    'slot'                => array( 'type' => 'string', 'description' => 'Instead of start: "HHMM-HHMM".' ),
                    'user_id'             => array( 'type' => 'integer', 'description' => 'A registered customer.' ),
                    'name'                => array( 'type' => 'string', 'description' => 'Guest customer\'s (first) name.' ),
                    'surname'             => array( 'type' => 'string' ),
                    'email'               => array( 'type' => 'string' ),
                    'title'               => array( 'type' => 'string' ),
                    'fields'              => array( 'type' => 'object', 'description' => 'Booking-form answers: {"Label": "answer"} (a list of strings for checkboxes).' ),
                    'status'              => array( 'type' => 'string', 'enum' => array( 'approved', 'pending' ) ),
                    'notify'              => array( 'type' => 'boolean', 'description' => 'Email the customer as QuickCal does. Default true.' ),
                    'ignore_availability' => array( 'type' => 'boolean', 'description' => 'Book even if the slot is full, blocked, not offered or in the past.' ),
                ),
            ),
            'permission_callback' => $staff,
            'execute_callback'    => 'att_mcp_execute_quickcal_save_appointment',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => false ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/quickcal-cancel-appointments' ) ) {
        att_mcp_register( 'att/quickcal-cancel-appointments', array_merge( $base, array(
            'label'               => 'Cancel QuickCal Appointments',
            'description'         => 'Cancels QuickCal appointments the way its admin screen does: each one is deleted, and the customer of an upcoming appointment gets the cancellation email ("notify": false skips it). Up to 50 ids from att/quickcal-get-appointments. Undoable with att/undo-change: the appointments come back under the same ids (emails already sent stay sent).',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'ids' ),
                'properties' => array(
                    'ids'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                    'notify' => array( 'type' => 'boolean', 'description' => 'Send the cancellation email. Default true.' ),
                ),
            ),
            'permission_callback' => $staff,
            'execute_callback'    => 'att_mcp_execute_quickcal_cancel_appointments',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => true, 'idempotent' => false ) ) ),
        ) ) );
    }
}

/* ----- Helpers: access and calendars --------------------------------------------- */

function att_mcp_quickcal_ready() {
    if ( ! att_mcp_detect_quickcal() || ! taxonomy_exists( 'booked_custom_calendars' ) ) {
        return new WP_Error( 'att_mcp_plugin_inactive', 'QuickCal is not active on this site.' );
    }
    return true;
}

/** May the current user manage this calendar (0 = the default calendar)? */
function att_mcp_quickcal_can_calendar( $calendar ) {
    if ( current_user_can( 'manage_booked_options' ) ) {
        return true;
    }
    // Booking Agents manage the calendars whose notification email is theirs (QuickCal's own rule).
    return $calendar > 0 && function_exists( 'quickcal_current_user_can_manage_calendar' ) && quickcal_current_user_can_manage_calendar( (int) $calendar );
}

function att_mcp_quickcal_can_appointment( $id ) {
    if ( 'booked_appointments' !== get_post_type( (int) $id ) ) {
        return false;
    }
    if ( current_user_can( 'manage_booked_options' ) ) {
        return true;
    }
    return function_exists( 'quickcal_current_user_can_manage_appointment' ) && quickcal_current_user_can_manage_appointment( (int) $id );
}

/** The calendar id an input names (0 = the default calendar), or WP_Error. */
function att_mcp_quickcal_calendar_arg( $input, $key = 'calendar' ) {
    $id = isset( $input[ $key ] ) ? (int) $input[ $key ] : 0;
    if ( 0 === $id ) {
        return 0;
    }
    $term = $id > 0 ? get_term( $id, 'booked_custom_calendars' ) : null;
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'att_mcp_not_found', sprintf( 'No QuickCal calendar with id %d. att/quickcal-get-setup lists them (0 = the default calendar).', $id ) );
    }
    return $id;
}

/** QuickCal is ready, the input names a calendar and the user may manage it: its id, or WP_Error. */
function att_mcp_quickcal_calendar_access( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $calendar = att_mcp_quickcal_calendar_arg( $input );
    if ( is_wp_error( $calendar ) ) {
        return $calendar;
    }
    if ( ! att_mcp_quickcal_can_calendar( $calendar ) ) {
        return new WP_Error( 'att_mcp_forbidden', $calendar
            ? 'You cannot manage this calendar: Booking Agents manage only the calendars assigned to them.'
            : 'Only QuickCal administrators (manage_booked_options) can change the default calendar.' );
    }
    return $calendar;
}

function att_mcp_quickcal_calendar_terms() {
    $terms = get_terms( array( 'taxonomy' => 'booked_custom_calendars', 'hide_empty' => false, 'orderby' => 'name' ) );
    return is_wp_error( $terms ) ? array() : $terms;
}

function att_mcp_quickcal_calendar_brief( $calendar ) {
    if ( ! $calendar ) {
        return array( 'id' => 0, 'name' => 'Default' );
    }
    $term = get_term( (int) $calendar, 'booked_custom_calendars' );
    return array( 'id' => (int) $calendar, 'name' => ( $term && ! is_wp_error( $term ) ) ? $term->name : '' );
}

function att_mcp_quickcal_calendar_out( $calendar ) {
    if ( ! $calendar ) {
        $email = (string) get_option( 'booked_default_email_user', '' );
        return array(
            'id'                 => 0,
            'name'               => 'Default',
            'shortcode'          => '[quickcal-calendar]',
            'notification_email' => '' !== $email ? $email : ( current_user_can( 'manage_options' ) ? (string) get_option( 'admin_email' ) : 'the site admin email' ),
        );
    }
    $term = get_term( (int) $calendar, 'booked_custom_calendars' );
    if ( ! $term || is_wp_error( $term ) ) {
        return null;
    }
    $meta = get_option( 'taxonomy_' . (int) $calendar );
    return array(
        'id'                 => (int) $term->term_id,
        'name'               => $term->name,
        'slug'               => $term->slug,
        'description'        => $term->description,
        'shortcode'          => '[quickcal-calendar calendar=' . (int) $term->term_id . ']',
        'notification_email' => ( is_array( $meta ) && ! empty( $meta['notifications_user_id'] ) ) ? (string) $meta['notifications_user_id'] : '',
    );
}

/** How many appointments (any status) a calendar has. */
function att_mcp_quickcal_calendar_appointments( $calendar ) {
    $query = new WP_Query( array(
        'post_type'      => 'booked_appointments',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'tax_query'      => array( array( 'taxonomy' => 'booked_custom_calendars', 'field' => 'term_id', 'terms' => (int) $calendar ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- QuickCal assigns appointments to calendars with this taxonomy; one row is fetched.
    ) );
    return (int) $query->found_posts;
}

/** An administrator's or Booking Agent's email (the users QuickCal's screens offer), or WP_Error. */
function att_mcp_quickcal_staff_email( $email ) {
    $email = sanitize_email( (string) $email );
    $user  = '' !== $email ? get_user_by( 'email', $email ) : false;
    if ( ! $user || ! array_intersect( array( 'administrator', 'booked_booking_agent' ), (array) $user->roles ) ) {
        return new WP_Error( 'att_mcp_bad_input', sprintf( '"%s" is not the email of an administrator or Booking Agent on this site. QuickCal notifies (and gives calendar access to) only those users.', $email ) );
    }
    return $user->user_email;
}

/* ----- Helpers: dates, times and slots ---------------------------------------------- */

function att_mcp_quickcal_days() {
    return array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
}

/** "Monday", "mon", "Mon" => "Mon"; '' when not a weekday. */
function att_mcp_quickcal_day( $name ) {
    $key = ucfirst( strtolower( substr( trim( (string) $name ), 0, 3 ) ) );
    return in_array( $key, att_mcp_quickcal_days(), true ) ? $key : '';
}

/** A valid YYYY-MM-DD date, or ''. */
function att_mcp_quickcal_date( $value ) {
    $value = trim( (string) $value );
    $date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
    return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
}

/** "9:00", "09:00", "0900" => "0900" ("24:00" => "2400" for end times); '' when invalid. */
function att_mcp_quickcal_time( $value, $end = false ) {
    if ( ! preg_match( '/^(\d{1,2}):?(\d{2})$/', trim( (string) $value ), $m ) || (int) $m[2] > 59 ) {
        return '';
    }
    $hour = (int) $m[1];
    if ( $end && 24 === $hour && 0 === (int) $m[2] ) {
        return '2400';
    }
    return $hour > 23 ? '' : sprintf( '%02d%02d', $hour, (int) $m[2] );
}

/** QuickCal's slot id "HHMM-HHMM" (start before end; "0000-2400" = all day). */
function att_mcp_quickcal_valid_slot( $slot ) {
    if ( ! preg_match( '/^([01][0-9]|2[0-3])[0-5][0-9]-(([01][0-9]|2[0-3])[0-5][0-9]|2400)$/', (string) $slot ) ) {
        return false;
    }
    list( $start, $end ) = explode( '-', (string) $slot );
    return (int) $start < (int) $end;
}

/** The local timestamp of a slot's start, as QuickCal stores it in _appointment_timestamp. */
function att_mcp_quickcal_ts( $date, $slot ) {
    return (int) strtotime( $date . ' ' . substr( $slot, 0, 2 ) . ':' . substr( $slot, 2, 2 ) . ':00' );
}

/** The site's current local time as such a timestamp. */
function att_mcp_quickcal_now() {
    return (int) strtotime( current_time( 'mysql' ) );
}

/** One slot from the input: array( key, spots, title ), or WP_Error. */
function att_mcp_quickcal_slot_in( $slot, $where ) {
    if ( ! is_array( $slot ) ) {
        return new WP_Error( 'att_mcp_bad_input', $where . ': a slot is an object like {"start": "09:00", "end": "10:00"}.' );
    }
    if ( ! empty( $slot['all_day'] ) ) {
        $key = '0000-2400';
    } elseif ( isset( $slot['slot'] ) && '' !== (string) $slot['slot'] ) {
        $key = (string) $slot['slot'];
    } else {
        $start = isset( $slot['start'] ) ? att_mcp_quickcal_time( $slot['start'] ) : '';
        $end   = isset( $slot['end'] ) ? att_mcp_quickcal_time( $slot['end'], true ) : '';
        $key   = ( '' !== $start && '' !== $end ) ? $start . '-' . $end : '';
    }
    if ( ! att_mcp_quickcal_valid_slot( $key ) ) {
        return new WP_Error( 'att_mcp_bad_input', $where . ': a slot needs 24-hour "start" and "end" times with the end after the start (e.g. "09:00" and "10:00"), a "slot" like "0900-1000", or "all_day": true.' );
    }
    $spots = isset( $slot['spots'] ) ? (int) $slot['spots'] : 1;
    if ( $spots < 1 || $spots > 500 ) {
        return new WP_Error( 'att_mcp_bad_input', $where . ': "spots" must be 1 to 500.' );
    }
    return array( 'key' => $key, 'spots' => $spots, 'title' => isset( $slot['title'] ) ? sanitize_text_field( (string) $slot['title'] ) : '' );
}

/** Back-to-back slots for a "generate" object: array( days, slots ), or WP_Error. */
function att_mcp_quickcal_generate( $gen, $where ) {
    if ( ! is_array( $gen ) ) {
        return new WP_Error( 'att_mcp_bad_input', $where . ' must be an object.' );
    }
    $days = array();
    foreach ( isset( $gen['days'] ) ? (array) $gen['days'] : array() as $name ) {
        $day = att_mcp_quickcal_day( $name );
        if ( '' === $day ) {
            return new WP_Error( 'att_mcp_bad_input', sprintf( '%s: "%s" is not a weekday (Mon, Tue, Wed, Thu, Fri, Sat or Sun).', $where, is_scalar( $name ) ? $name : '' ) );
        }
        $days[ $day ] = $day;
    }
    if ( ! $days ) {
        return new WP_Error( 'att_mcp_bad_input', $where . ' needs "days", e.g. ["Mon","Tue","Wed","Thu","Fri"].' );
    }
    $start    = isset( $gen['start'] ) ? att_mcp_quickcal_time( $gen['start'] ) : '';
    $end      = isset( $gen['end'] ) ? att_mcp_quickcal_time( $gen['end'], true ) : '';
    $duration = isset( $gen['duration'] ) ? (int) $gen['duration'] : 60;
    $gap      = isset( $gen['gap'] ) ? (int) $gen['gap'] : 0;
    if ( '' === $start || '' === $end || (int) $start >= (int) $end ) {
        return new WP_Error( 'att_mcp_bad_input', $where . ' needs 24-hour "start" and "end" times with the end after the start.' );
    }
    if ( $duration < 5 || $duration > 1440 || $gap < 0 || $gap > 1440 ) {
        return new WP_Error( 'att_mcp_bad_input', $where . ': "duration" must be 5 to 1440 minutes and "gap" 0 to 1440.' );
    }
    $template = att_mcp_quickcal_slot_in( array( 'all_day' => true, 'spots' => isset( $gen['spots'] ) ? $gen['spots'] : 1, 'title' => isset( $gen['title'] ) ? $gen['title'] : '' ), $where );
    if ( is_wp_error( $template ) ) {
        return $template;
    }
    $from  = (int) substr( $start, 0, 2 ) * 60 + (int) substr( $start, 2, 2 );
    $to    = (int) substr( $end, 0, 2 ) * 60 + (int) substr( $end, 2, 2 );
    $slots = array();
    for ( $minute = $from; $minute + $duration <= $to; $minute += $duration + $gap ) {
        $stop    = $minute + $duration;
        $slots[] = array(
            'key'   => sprintf( '%02d%02d-%02d%02d', intdiv( $minute, 60 ), $minute % 60, intdiv( $stop, 60 ), $stop % 60 ),
            'spots' => $template['spots'],
            'title' => $template['title'],
        );
    }
    if ( ! $slots ) {
        return new WP_Error( 'att_mcp_bad_input', $where . ': no slot of that duration fits between start and end.' );
    }
    return array( 'days' => array_values( $days ), 'slots' => $slots );
}

/** Slot counts (+ titles) as a readable list. */
function att_mcp_quickcal_slots_out( $counts, $details = array() ) {
    $counts  = is_array( $counts ) ? $counts : array();
    $details = is_array( $details ) ? $details : array();
    ksort( $counts );
    $out = array();
    foreach ( $counts as $key => $spots ) {
        if ( ! preg_match( '/^(\d{2})(\d{2})-(\d{2})(\d{2})$/', (string) $key, $m ) ) {
            continue;
        }
        $row = array( 'slot' => (string) $key, 'start' => $m[1] . ':' . $m[2], 'end' => $m[3] . ':' . $m[4], 'spots' => (int) $spots );
        if ( '0000-2400' === (string) $key ) {
            $row['all_day'] = true;
        }
        $title = isset( $details[ $key ]['title'] ) && is_scalar( $details[ $key ]['title'] ) ? html_entity_decode( (string) $details[ $key ]['title'], ENT_QUOTES, 'UTF-8' ) : '';
        if ( '' !== $title ) {
            $row['title'] = $title;
        }
        $out[] = $row;
    }
    return $out;
}

/* ----- Weekly slots (booked_defaults[_{id}]) -------------------------------------- */

function att_mcp_quickcal_weekly_option( $calendar ) {
    return $calendar ? 'booked_defaults_' . (int) $calendar : 'booked_defaults';
}

function att_mcp_quickcal_weekly_raw( $calendar ) {
    $value = get_option( att_mcp_quickcal_weekly_option( $calendar ) );
    return is_array( $value ) ? $value : array();
}

/** The weekly slots QuickCal uses for a calendar: its own, or else the default calendar's. */
function att_mcp_quickcal_weekly_effective( $calendar ) {
    $own = att_mcp_quickcal_weekly_raw( $calendar );
    return ( $calendar && ! $own ) ? att_mcp_quickcal_weekly_raw( 0 ) : $own;
}

function att_mcp_quickcal_weekly_out( $calendar ) {
    $own  = att_mcp_quickcal_weekly_raw( $calendar );
    $raw  = att_mcp_quickcal_weekly_effective( $calendar );
    $days = array();
    foreach ( att_mcp_quickcal_days() as $day ) {
        $days[ $day ] = att_mcp_quickcal_slots_out(
            isset( $raw[ $day ] ) ? $raw[ $day ] : array(),
            isset( $raw[ $day . '-details' ] ) ? $raw[ $day . '-details' ] : array()
        );
    }
    return array( 'uses' => ( $calendar && ! $own ) ? 'default calendar' : 'own', 'days' => $days );
}

/* ----- Date-specific entries (booked_custom_timeslots_encoded) ------------------------ */

/**
 * QuickCal's date-specific entries as a list of array( calendar, start, end, closed,
 * slots, details ). The option is one JSON object: with one entry its values are
 * scalars, with more they are parallel lists (see quickcal_custom_timeslots_reconfigured());
 * the slots are JSON strings inside it. calendar null = a legacy entry for every calendar.
 */
function att_mcp_quickcal_date_entries() {
    // Stored through htmlentities( …, ENT_NOQUOTES ): decode those entities too, so a
    // re-save (which encodes once) does not double-encode titles.
    $decoded = json_decode( html_entity_decode( (string) get_option( 'booked_custom_timeslots_encoded', '' ), ENT_NOQUOTES, 'UTF-8' ), true );
    if ( empty( $decoded ) || ! is_array( $decoded ) ) {
        return array();
    }
    $rows = array();
    if ( isset( $decoded['booked_custom_start_date'] ) && is_array( $decoded['booked_custom_start_date'] ) ) {
        $count = count( $decoded['booked_custom_start_date'] );
        for ( $i = 0; $i < $count; $i++ ) {
            $row = array();
            foreach ( $decoded as $key => $values ) {
                $row[ $key ] = is_array( $values ) ? ( isset( $values[ $i ] ) ? $values[ $i ] : '' ) : $values;
            }
            $rows[] = $row;
        }
    } else {
        $rows[] = $decoded;
    }

    $entries = array();
    foreach ( $rows as $row ) {
        $slots   = isset( $row['booked_this_custom_timelots'] ) ? $row['booked_this_custom_timelots'] : '';
        $details = isset( $row['booked_this_custom_timelots_details'] ) ? $row['booked_this_custom_timelots_details'] : '';
        $slots   = is_array( $slots ) ? $slots : json_decode( (string) $slots, true );
        $details = is_array( $details ) ? $details : json_decode( (string) $details, true );
        $start   = isset( $row['booked_custom_start_date'] ) && is_scalar( $row['booked_custom_start_date'] ) ? (string) $row['booked_custom_start_date'] : '';
        $end     = isset( $row['booked_custom_end_date'] ) && is_scalar( $row['booked_custom_end_date'] ) ? (string) $row['booked_custom_end_date'] : '';
        // QuickCal reads the dates with strtotime(); normalise what parses.
        $start_ts  = '' !== $start ? strtotime( $start ) : false;
        $end_ts    = '' !== $end ? strtotime( $end ) : false;
        $entries[] = array(
            'calendar' => array_key_exists( 'booked_custom_calendar_id', $row ) ? (int) $row['booked_custom_calendar_id'] : null,
            'start'    => false !== $start_ts ? gmdate( 'Y-m-d', $start_ts ) : $start,
            'end'      => false !== $end_ts ? gmdate( 'Y-m-d', $end_ts ) : $end,
            'closed'   => ! empty( $row['vacationDayCheckbox'] ),
            'slots'    => is_array( $slots ) ? $slots : array(),
            'details'  => is_array( $details ) ? $details : array(),
        );
    }
    return $entries;
}

/** Write the entries back in QuickCal's format (as its Custom Time Slots screen saves them). */
function att_mcp_quickcal_save_date_entries( $entries ) {
    $entries = array_values( $entries );
    $rows    = array();
    foreach ( $entries as $entry ) {
        $row = array();
        // A legacy "every calendar" entry has no calendar key, which only the one-entry form can express.
        if ( null !== $entry['calendar'] || 1 !== count( $entries ) ) {
            $row['booked_custom_calendar_id'] = $entry['calendar'] ? (string) (int) $entry['calendar'] : '';
        }
        $row['booked_custom_start_date']            = (string) $entry['start'];
        $row['booked_custom_end_date']              = (string) $entry['end'];
        $row['booked_this_custom_timelots']         = $entry['slots'] ? wp_json_encode( (object) $entry['slots'] ) : '';
        $row['booked_this_custom_timelots_details'] = $entry['details'] ? wp_json_encode( (object) $entry['details'] ) : '';
        $row['vacationDayCheckbox']                 = $entry['closed'] ? true : '';
        $rows[] = $row;
    }
    if ( ! $rows ) {
        $data = new stdClass();
    } elseif ( 1 === count( $rows ) ) {
        $data = $rows[0]; // one entry: plain values (a one-item list breaks QuickCal's reader)
    } else {
        $data = array();
        foreach ( array( 'booked_custom_calendar_id', 'booked_custom_start_date', 'booked_custom_end_date', 'booked_this_custom_timelots', 'booked_this_custom_timelots_details', 'vacationDayCheckbox' ) as $key ) {
            foreach ( $rows as $row ) {
                $data[ $key ][] = isset( $row[ $key ] ) ? $row[ $key ] : '';
            }
        }
    }
    // QuickCal stores the JSON through htmlentities( …, ENT_NOQUOTES ) and decodes it as is.
    update_option( 'booked_custom_timeslots_encoded', htmlentities( (string) wp_json_encode( $data ), ENT_NOQUOTES, 'UTF-8' ) );
}

/** Does a date entry apply to this calendar? (QuickCal's rule.) */
function att_mcp_quickcal_entry_applies( $entry, $calendar ) {
    if ( null === $entry['calendar'] ) {
        return true;
    }
    return $calendar ? (int) $entry['calendar'] === (int) $calendar : ! $entry['calendar'];
}

/** Is this entry one of this calendar's own (not a legacy all-calendars entry)? */
function att_mcp_quickcal_entry_is_mine( $entry, $calendar ) {
    return null !== $entry['calendar'] && att_mcp_quickcal_entry_applies( $entry, $calendar );
}

function att_mcp_quickcal_date_entries_out( $entries, $calendar = null ) {
    $out = array();
    foreach ( $entries as $entry ) {
        if ( null !== $calendar && ! att_mcp_quickcal_entry_applies( $entry, $calendar ) ) {
            continue;
        }
        $row = array(
            'calendar' => null === $entry['calendar'] ? 'all' : (int) $entry['calendar'],
            'date'     => $entry['start'],
        );
        if ( '' !== $entry['end'] && $entry['end'] !== $entry['start'] ) {
            $row['end_date'] = $entry['end'];
        }
        $row['closed'] = $entry['closed'];
        if ( ! $entry['closed'] ) {
            $row['slots'] = att_mcp_quickcal_slots_out( $entry['slots'], $entry['details'] );
        }
        $out[] = $row;
    }
    usort( $out, function ( $a, $b ) { return strcmp( $a['date'], $b['date'] ); } );
    return $out;
}

/** Blocked single slots (booked_disabled_timeslots: calendar => date => slot => true). */
function att_mcp_quickcal_blocked() {
    $value = get_option( 'booked_disabled_timeslots', array() );
    return is_array( $value ) ? $value : array();
}

function att_mcp_quickcal_is_blocked( $calendar, $date, $slot ) {
    $blocked = att_mcp_quickcal_blocked();
    return ! empty( $blocked[ (int) $calendar ][ $date ][ $slot ] );
}

function att_mcp_quickcal_blocked_out( $calendar = null ) {
    $out = array();
    foreach ( att_mcp_quickcal_blocked() as $cal => $dates ) {
        if ( ( null !== $calendar && (int) $cal !== (int) $calendar ) || ! is_array( $dates ) ) {
            continue;
        }
        foreach ( $dates as $date => $slots ) {
            foreach ( is_array( $slots ) ? $slots : array() as $slot => $on ) {
                if ( $on ) {
                    $out[] = array( 'calendar' => (int) $cal, 'date' => (string) $date, 'slot' => (string) $slot );
                }
            }
        }
    }
    return $out;
}

/**
 * The slots (slot => spaces) and titles a calendar offers on a date, as QuickCal
 * computes them: the latest date-specific entry covering the date wins over the
 * weekly slots of that weekday.
 */
function att_mcp_quickcal_day_slots( $calendar, $date, $entries = null, $weekly = null ) {
    $entries = null === $entries ? att_mcp_quickcal_date_entries() : $entries;
    $weekly  = null === $weekly ? att_mcp_quickcal_weekly_effective( $calendar ) : $weekly;
    $match   = null;
    foreach ( $entries as $entry ) {
        if ( '' === att_mcp_quickcal_date( $entry['start'] ) || ! att_mcp_quickcal_entry_applies( $entry, $calendar ) ) {
            continue;
        }
        $last = ( '' !== att_mcp_quickcal_date( $entry['end'] ) && $entry['end'] > $entry['start'] ) ? $entry['end'] : $entry['start'];
        if ( $date >= $entry['start'] && $date <= $last ) {
            $match = $entry;
        }
    }
    if ( $match ) {
        return array(
            'source'  => $match['closed'] ? 'closed' : 'date',
            'slots'   => $match['closed'] ? array() : $match['slots'],
            'details' => $match['details'],
        );
    }
    $day = gmdate( 'D', (int) strtotime( $date ) );
    return array(
        'source'  => 'weekly',
        'slots'   => isset( $weekly[ $day ] ) && is_array( $weekly[ $day ] ) ? $weekly[ $day ] : array(),
        'details' => isset( $weekly[ $day . '-details' ] ) && is_array( $weekly[ $day . '-details' ] ) ? $weekly[ $day . '-details' ] : array(),
    );
}

/**
 * Appointments (any status) per "Y-m-d|slot" between two dates. Like QuickCal, the
 * default calendar counts every appointment of the day, whatever its calendar.
 */
function att_mcp_quickcal_booked_counts( $calendar, $from, $to, $exclude = 0 ) {
    $args = array(
        'post_type'      => 'booked_appointments',
        'post_status'    => 'any',
        'posts_per_page' => 5000,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- QuickCal keeps the appointment time only in this meta; bounded by the date range.
            array(
                'key'     => '_appointment_timestamp',
                'value'   => array( strtotime( $from . ' 00:00:00' ), strtotime( $to . ' 23:59:59' ) ),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ),
        ),
    );
    if ( $calendar ) {
        $args['tax_query'] = array( array( 'taxonomy' => 'booked_custom_calendars', 'field' => 'term_id', 'terms' => (int) $calendar ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- QuickCal's calendars are this taxonomy.
    }
    $counts = array();
    foreach ( get_posts( $args ) as $id ) {
        if ( (int) $id === (int) $exclude ) {
            continue;
        }
        $key            = gmdate( 'Y-m-d', (int) get_post_meta( $id, '_appointment_timestamp', true ) ) . '|' . (string) get_post_meta( $id, '_appointment_timeslot', true );
        $counts[ $key ] = ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) + 1;
    }
    return $counts;
}

/** Free spaces left in a slot on a date (0 when not offered or blocked). */
function att_mcp_quickcal_spaces_left( $calendar, $date, $slot, $exclude = 0 ) {
    $day = att_mcp_quickcal_day_slots( $calendar, $date );
    if ( ! isset( $day['slots'][ $slot ] ) || att_mcp_quickcal_is_blocked( $calendar, $date, $slot ) ) {
        return 0;
    }
    $counts = att_mcp_quickcal_booked_counts( $calendar, $date, $date, $exclude );
    $taken  = isset( $counts[ $date . '|' . $slot ] ) ? $counts[ $date . '|' . $slot ] : 0;
    return max( 0, (int) $day['slots'][ $slot ] - $taken );
}

/* ----- Booking forms (booked_custom_fields[_{id}]) ---------------------------------- */

function att_mcp_quickcal_fields_option( $calendar ) {
    return $calendar ? 'booked_custom_fields_' . (int) $calendar : 'booked_custom_fields';
}

/** The stored form (QuickCal keeps it as slashed JSON); with $effective, a calendar without one gets the default form. */
function att_mcp_quickcal_fields_raw( $calendar, $effective = false ) {
    $value = get_option( att_mcp_quickcal_fields_option( $calendar ) );
    $list  = ( is_string( $value ) && '' !== $value ) ? json_decode( stripslashes( $value ), true ) : null;
    if ( ( ! is_array( $list ) || ! $list ) && $effective && $calendar ) {
        return att_mcp_quickcal_fields_raw( 0 );
    }
    return is_array( $list ) ? $list : array();
}

function att_mcp_quickcal_field_types() {
    return array(
        'text'       => array( 'single-line-text-label', '' ),
        'paragraph'  => array( 'paragraph-text-label', '' ),
        'checkboxes' => array( 'checkboxes-label', 'single-checkbox' ),
        'radio'      => array( 'radio-buttons-label', 'single-radio-button' ),
        'dropdown'   => array( 'drop-down-label', 'single-drop-down' ),
        'content'    => array( 'plain-text-content', '' ),
    );
}

/** A stored form as a readable list: array( fields, unsupported types ). */
function att_mcp_quickcal_fields_out( $raw ) {
    $labels = array();
    $subs   = array();
    foreach ( att_mcp_quickcal_field_types() as $type => $names ) {
        $labels[ $names[0] ] = $type;
        if ( '' !== $names[1] ) {
            $subs[ $names[1] ] = $type;
        }
    }
    $required = array();
    foreach ( $raw as $field ) {
        if ( is_array( $field ) && isset( $field['name'] ) && 0 === strpos( (string) $field['name'], 'required---' ) && ! empty( $field['value'] ) ) {
            $required[ substr( (string) $field['name'], 11 ) ] = true;
        }
    }
    $fields      = array();
    $unsupported = array();
    $last        = -1;
    foreach ( $raw as $field ) {
        if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
            continue;
        }
        $parts  = explode( '---', (string) $field['name'], 2 );
        $kind   = $parts[0];
        $number = isset( $parts[1] ) ? current( explode( '___', $parts[1] ) ) : '';
        $value  = isset( $field['value'] ) && is_scalar( $field['value'] ) ? (string) $field['value'] : '';
        if ( 'required' === $kind ) {
            continue;
        }
        if ( isset( $labels[ $kind ] ) ) {
            $type = $labels[ $kind ];
            if ( 'content' === $type ) {
                $fields[] = array( 'type' => 'content', 'content' => $value );
            } else {
                $row = array( 'type' => $type, 'label' => $value, 'required' => isset( $required[ $number ] ) );
                if ( in_array( $type, array( 'checkboxes', 'radio', 'dropdown' ), true ) ) {
                    $row['options'] = array();
                }
                $fields[] = $row;
            }
            $last = count( $fields ) - 1;
        } elseif ( isset( $subs[ $kind ] ) ) {
            if ( $last >= 0 && isset( $fields[ $last ]['options'] ) ) {
                $fields[ $last ]['options'][] = $value;
            }
        } else {
            $unsupported[ $kind ] = $kind;
            $fields[]             = array( 'type' => 'unsupported', 'name' => (string) $field['name'], 'value' => $value );
            $last                 = count( $fields ) - 1;
        }
    }
    return array( 'fields' => $fields, 'unsupported' => array_values( $unsupported ) );
}

/**
 * Build the stored form from readable fields, in the format QuickCal's form builder
 * saves: {name: "<type>---<N>", value} in order (options follow their field and share
 * its N; a required field's name ends in "___required"), then one {"required---<N>":
 * true|false} per question at the end. Returns the list or WP_Error.
 */
function att_mcp_quickcal_fields_build( $fields ) {
    $types    = att_mcp_quickcal_field_types();
    $raw      = array();
    $required = array();
    $used     = array();
    foreach ( array_values( $fields ) as $i => $field ) {
        $where = sprintf( 'fields[%d]', $i );
        $type  = is_array( $field ) && isset( $field['type'] ) ? (string) $field['type'] : '';
        if ( ! isset( $types[ $type ] ) ) {
            return new WP_Error( 'att_mcp_bad_input', $where . ': "type" must be text, paragraph, checkboxes, radio, dropdown or content.' );
        }
        do {
            $number = wp_rand( 1000000, 10999998 ); // the range QuickCal's form builder uses
        } while ( isset( $used[ $number ] ) );
        $used[ $number ] = true;

        if ( 'content' === $type ) {
            $html = att_mcp_prepare_content( (string) ( isset( $field['content'] ) ? $field['content'] : ( isset( $field['label'] ) ? $field['label'] : '' ) ) );
            if ( '' === trim( $html ) ) {
                return new WP_Error( 'att_mcp_bad_input', $where . ': a "content" field needs "content" (HTML).' );
            }
            $raw[] = array( 'name' => $types[ $type ][0] . '---' . $number, 'value' => $html );
            continue;
        }
        $label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
        if ( '' === $label ) {
            return new WP_Error( 'att_mcp_bad_input', $where . ' needs a "label".' );
        }
        $is_required = ! empty( $field['required'] );
        $raw[]       = array( 'name' => $types[ $type ][0] . '---' . $number . ( $is_required ? '___required' : '' ), 'value' => $label );
        if ( '' !== $types[ $type ][1] ) {
            $options = array();
            foreach ( isset( $field['options'] ) ? (array) $field['options'] : array() as $option ) {
                $option = is_scalar( $option ) ? sanitize_text_field( (string) $option ) : '';
                if ( '' !== $option ) {
                    $options[] = $option;
                }
            }
            if ( ! $options ) {
                return new WP_Error( 'att_mcp_bad_input', sprintf( '%s ("%s") needs "options".', $where, $label ) );
            }
            foreach ( $options as $option ) {
                $raw[] = array( 'name' => $types[ $type ][1] . '---' . $number, 'value' => $option );
            }
        }
        $required[] = array( 'name' => 'required---' . $number, 'value' => $is_required );
    }
    return array_merge( $raw, $required );
}

/* ----- Settings and emails ------------------------------------------------------------ */

/** Readable setting key => array( option, type, … ) with the values QuickCal's Settings screen offers. */
function att_mcp_quickcal_settings_map() {
    $hours   = array( '0', '1', '2', '3', '4', '5', '6', '12', '24', '48', '72', '96', '144', '168', '336', '504', '672', '840', '1008', '1176', '1344' );
    $minutes = array( '0', '5', '10', '15', '30', '45', '60', '120', '180', '240', '300', '360', '720', '1440', '2880', '4320', '5760', '7200', '8640', '10080', '20160', '30240', '40320', '60480', '80640', '120960' );
    $flag    = function ( $option, $help, $on = 'on' ) {
        return array( 'option' => $option, 'type' => 'flag', 'on' => $on, 'help' => $help );
    };
    $number  = function ( $option, $values, $default, $help ) {
        return array( 'option' => $option, 'type' => 'number', 'values' => $values, 'default' => $default, 'help' => $help . ' One of: ' . implode( ', ', $values ) . '.' );
    };
    return array(
        'booking_type'                      => array( 'option' => 'booked_booking_type', 'type' => 'choice', 'choices' => array( 'registered' => 'registered', 'guest' => 'guest' ), 'default' => 'registered', 'help' => '"guest": anyone can book with a name and email; "registered": customers need an account.' ),
        'require_guest_email'               => $flag( 'booked_require_guest_email_address', 'Guests must give an email address.', 'true' ),
        'guest_name_fields'                 => array( 'option' => 'booked_registration_name_requirements', 'type' => 'name', 'help' => '"name" (one name field) or "first_and_last".' ),
        'new_appointment_status'            => array( 'option' => 'booked_new_appointment_default', 'type' => 'choice', 'choices' => array( 'pending' => 'draft', 'approved' => 'publish' ), 'default' => 'draft', 'help' => '"pending": you approve each booking; "approved": bookings are confirmed at once.' ),
        'booking_buffer_hours'              => $number( 'booked_appointment_buffer', $hours, '0', 'Minimum notice: hours between now and the first bookable slot.' ),
        'cancellation_buffer_hours'         => $number( 'booked_cancellation_buffer', array( '0', '0.25', '0.50', '0.75', '1', '2', '3', '4', '5', '6', '12', '24', '48', '72', '96', '144', '168', '336', '504', '672', '840', '1008', '1176', '1344' ), '0', 'Customers cannot cancel later than this many hours before the appointment.' ),
        'appointment_limit'                 => $number( 'booked_appointment_limit', array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '15', '20', '25', '50' ), '0', 'Upcoming appointments a customer may hold (0 = no limit).' ),
        'bookable_from'                     => array( 'option' => 'booked_prevent_appointments_before', 'type' => 'date', 'help' => 'No bookings before this date (YYYY-MM-DD; "" = no limit).' ),
        'bookable_until'                    => array( 'option' => 'booked_prevent_appointments_after', 'type' => 'date', 'help' => 'No bookings after this date (YYYY-MM-DD; "" = no limit).' ),
        'timeslot_interval_minutes'         => $number( 'booked_timeslot_intervals', array( '120', '60', '30', '15', '10', '5' ), '5', 'Step of the time pickers on QuickCal\'s admin screens.' ),
        'hide_default_calendar'             => $flag( 'booked_hide_default_calendar', 'Hide "Default" in the calendar switcher.' ),
        'hide_weekends'                     => $flag( 'booked_hide_weekends', 'Hide weekends in the calendar.' ),
        'hide_add_to_calendar_button'       => $flag( 'booked_hide_google_link', 'Hide the "Add to Calendar" button in the customer\'s appointment list.' ),
        'show_only_titles'                  => $flag( 'booked_show_only_titles', 'Show slot titles instead of times (when a slot has a title).' ),
        'hide_end_times'                    => $flag( 'booked_hide_end_times', 'Show only start times.' ),
        'hide_available_count'              => $flag( 'booked_hide_available_timeslots', 'Hide how many spaces are left.' ),
        'hide_booked_slots'                 => $flag( 'booked_hide_unavailable_timeslots', 'Hide slots that are fully booked (not with public_appointments).' ),
        'public_appointments'               => $flag( 'booked_public_appointments', 'Show the names of booked customers under their slots.' ),
        'prevent_customer_cancellations'    => $flag( 'booked_dont_allow_user_cancellations', 'Customers cannot cancel their own appointments.' ),
        'prevent_customer_changes'          => $flag( 'booked_dont_allow_user_changes', 'Customers cannot change their own appointments.' ),
        'redirect_non_admins'               => $flag( 'booked_redirect_non_admins', 'Send users other than admins and Booking Agents away from /wp-admin/.' ),
        'hide_admin_bar_menu'               => $flag( 'booked_hide_admin_bar_menu', 'Hide the "Appointments" admin bar menu.' ),
        'after_booking_redirect'            => array( 'option' => 'booked_appointment_redirect_type', 'type' => 'choice', 'choices' => array( 'none' => '', 'profile' => 'booked-profile', 'page' => 'page' ), 'default' => '', 'help' => 'After booking: "none" (refresh the calendar), "profile" (the page with [quickcal-profile]) or "page" (after_booking_page).' ),
        'after_booking_page'                => array( 'option' => 'booked_appointment_success_redirect_page', 'type' => 'page', 'help' => 'Page id to send customers to after booking (with after_booking_redirect "page"); 0 = none.' ),
        'login_redirect_page'               => array( 'option' => 'booked_login_redirect_page', 'type' => 'page', 'help' => 'Page id to go to after logging in through QuickCal; 0 = the same page.' ),
        'login_message'                     => array( 'option' => 'booked_custom_login_message', 'type' => 'html', 'help' => 'HTML shown above QuickCal\'s login form.' ),
        'calendar_style'                    => array( 'option' => 'booked_css_style', 'type' => 'choice', 'choices' => array( 'style_1' => 'style_1', 'style_2' => 'style_2' ), 'default' => 'style_1', 'help' => 'The calendar\'s look.' ),
        'light_color'                       => array( 'option' => 'booked_light_color', 'type' => 'color', 'default' => '#2371B1', 'help' => 'Hex color.' ),
        'dark_color'                        => array( 'option' => 'booked_dark_color', 'type' => 'color', 'default' => '#014163', 'help' => 'Hex color.' ),
        'button_color'                      => array( 'option' => 'booked_button_color', 'type' => 'color', 'default' => '#56C477', 'help' => 'Hex color of the main buttons (and email links).' ),
        'notification_email'                => array( 'option' => 'booked_default_email_user', 'type' => 'staff_email', 'help' => 'Who gets admin emails for calendars without their own notification email: an administrator\'s or Booking Agent\'s email ("" = the site admin email).' ),
        'cancellation_notice_to_site_admin' => $flag( 'booked_send_cancellation_email_to_site_admin', 'Also send cancellation notices to the site admin email.', 'true' ),
        'email_logo'                        => array( 'option' => 'booked_email_logo', 'type' => 'url', 'help' => 'Image URL for the top of QuickCal\'s emails (up to 600px wide; "" = none).' ),
        'force_sender'                      => $flag( 'booked_email_force_sender', 'Send every email from force_sender_email (for mail servers that reject other senders).', 'true' ),
        'force_sender_email'                => array( 'option' => 'booked_email_force_sender_from', 'type' => 'email', 'help' => 'Sender address when force_sender is on.' ),
        'use_wordpress_mailer'              => $flag( 'booked_emailer_disabled', 'Let WordPress send the emails without QuickCal\'s sender handling.', 'true' ),
        'customer_reminder_minutes'         => $number( 'booked_reminder_buffer', $minutes, '30', 'When the customer reminder goes out, in minutes before the appointment.' ),
        'admin_reminder_minutes'            => $number( 'booked_admin_reminder_buffer', $minutes, '30', 'When the admin reminder goes out, in minutes before the appointment.' ),
    );
}

/** A setting's current value in readable form. */
function att_mcp_quickcal_setting_get( $cfg ) {
    $value = get_option( $cfg['option'], null );
    $unset = ( null === $value || false === $value || '' === $value );
    switch ( $cfg['type'] ) {
        case 'flag':
            return ! empty( $value );
        case 'choice':
            $stored = $unset ? $cfg['default'] : (string) $value;
            $key    = array_search( $stored, $cfg['choices'], true );
            return false === $key ? $stored : $key;
        case 'number':
            $stored = $unset || ! is_numeric( $value ) ? $cfg['default'] : (string) $value;
            return ( (float) $stored === floor( (float) $stored ) ) ? (int) $stored : (float) $stored;
        case 'name':
            return ( is_array( $value ) && isset( $value[0] ) && 'require_surname' === $value[0] ) ? 'first_and_last' : 'name';
        case 'page':
            return (int) $value;
        case 'color':
            return $unset ? $cfg['default'] : (string) $value;
    }
    return $unset || ! is_scalar( $value ) ? '' : (string) $value;
}

/** The value to store for a setting, or WP_Error. */
function att_mcp_quickcal_setting_value( $key, $cfg, $value ) {
    $bad = function ( $expected ) use ( $key ) {
        return new WP_Error( 'att_mcp_bad_input', sprintf( '"%s" must be %s.', $key, $expected ) );
    };
    switch ( $cfg['type'] ) {
        case 'flag':
            if ( ! is_bool( $value ) && ! in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
                return $bad( 'true or false' );
            }
            return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? $cfg['on'] : '';
        case 'choice':
            return is_string( $value ) && isset( $cfg['choices'][ $value ] ) ? $cfg['choices'][ $value ] : $bad( 'one of: ' . implode( ', ', array_keys( $cfg['choices'] ) ) );
        case 'number':
            foreach ( $cfg['values'] as $allowed ) {
                if ( is_numeric( $value ) && abs( (float) $allowed - (float) $value ) < 0.001 ) {
                    return $allowed;
                }
            }
            return $bad( 'one of: ' . implode( ', ', $cfg['values'] ) );
        case 'date':
            if ( '' === $value || null === $value ) {
                return '';
            }
            $date = att_mcp_quickcal_date( $value );
            return '' !== $date ? $date : $bad( 'a date (YYYY-MM-DD) or ""' );
        case 'name':
            $map = array( 'name' => 'require_name', 'first_and_last' => 'require_surname' );
            return is_string( $value ) && isset( $map[ $value ] ) ? array( $map[ $value ] ) : $bad( '"name" or "first_and_last"' );
        case 'page':
            if ( empty( $value ) ) {
                return '';
            }
            $page = get_post( (int) $value );
            return ( $page && 'page' === $page->post_type && 'trash' !== $page->post_status ) ? (string) (int) $value : $bad( 'the id of a page (or 0)' );
        case 'html':
            return is_scalar( $value ) ? att_mcp_prepare_content( (string) $value ) : $bad( 'HTML text' );
        case 'color':
            $color = is_string( $value ) ? sanitize_hex_color( $value ) : '';
            return $color ? $color : $bad( 'a hex color like "#2371B1"' );
        case 'staff_email':
            return ( '' === $value || null === $value ) ? '' : att_mcp_quickcal_staff_email( $value );
        case 'email':
            if ( '' === $value || null === $value ) {
                return '';
            }
            return is_string( $value ) && is_email( $value ) ? sanitize_email( $value ) : $bad( 'an email address' );
        case 'url':
            if ( '' === $value || null === $value ) {
                return '';
            }
            $url = is_string( $value ) ? esc_url_raw( $value, array( 'http', 'https' ) ) : '';
            return '' !== $url ? $url : $bad( 'an http(s) URL or ""' );
    }
    return $bad( 'valid' );
}

/** Readable email key => array( subject option, content option, when it is sent ). */
function att_mcp_quickcal_emails_map() {
    return array(
        'customer_confirmation' => array( 'booked_appt_confirmation_email_subject', 'booked_appt_confirmation_email_content', 'To the customer when they book (their booking was received).' ),
        'customer_approval'     => array( 'booked_approval_email_subject', 'booked_approval_email_content', 'To the customer when their appointment is approved, or booked for them by an admin.' ),
        'customer_cancellation' => array( 'booked_cancellation_email_subject', 'booked_cancellation_email_content', 'To the customer when an upcoming appointment is cancelled.' ),
        'customer_reminder'     => array( 'booked_reminder_email_subject', 'booked_reminder_email', 'To the customer before the appointment (customer_reminder_minutes).' ),
        'customer_registration' => array( 'booked_registration_email_subject', 'booked_registration_email_content', 'To a customer whose account was created while booking.' ),
        'admin_new_appointment' => array( 'booked_admin_appointment_email_subject', 'booked_admin_appointment_email_content', 'To the calendar\'s notification email when a customer books.' ),
        'admin_cancellation'    => array( 'booked_admin_cancellation_email_subject', 'booked_admin_cancellation_email_content', 'To the calendar\'s notification email when a customer cancels.' ),
        'admin_reminder'        => array( 'booked_admin_reminder_email_subject', 'booked_admin_reminder_email', 'To the calendar\'s notification email before an appointment (admin_reminder_minutes).' ),
    );
}

function att_mcp_quickcal_emails_out() {
    $out = array();
    foreach ( att_mcp_quickcal_emails_map() as $key => $email ) {
        $subject     = (string) get_option( $email[0], '' );
        $content     = (string) get_option( $email[1], '' );
        $out[ $key ] = array( 'sent' => $email[2], 'active' => '' !== $subject && '' !== $content, 'subject' => $subject, 'content' => $content );
    }
    return $out;
}

/**
 * Send one of QuickCal's appointment emails the way its screens do (its mailer is
 * hooked to these actions). True when QuickCal had an email to send.
 */
function att_mcp_quickcal_email( $which, $appointment_id, $calendar = 0 ) {
    $map = array(
        'approved'     => array( 'booked_approval_email_subject', 'booked_approval_email_content' ),
        'confirmation' => array( 'booked_appt_confirmation_email_subject', 'booked_appt_confirmation_email_content' ),
        'cancellation' => array( 'booked_cancellation_email_subject', 'booked_cancellation_email_content' ),
    );
    $subject = get_option( $map[ $which ][0] );
    $content = get_option( $map[ $which ][1] );
    if ( ! $subject || ! $content || ! function_exists( 'quickcal_get_appointment_tokens' ) || ! function_exists( 'quickcal_token_replacement' ) ) {
        return false;
    }
    $tokens = quickcal_get_appointment_tokens( $appointment_id );
    if ( empty( $tokens['email'] ) ) {
        return false;
    }
    $subject = quickcal_token_replacement( $subject, $tokens );
    $content = quickcal_token_replacement( $content, $tokens );
    if ( 'approved' === $which ) {
        do_action( 'booked_approved_email', $tokens['email'], $subject, $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- QuickCal's own mail hook, fired as its admin screen does.
    } elseif ( 'confirmation' === $which ) {
        $from = function_exists( 'quickcal_which_admin_to_send_email' ) ? quickcal_which_admin_to_send_email( $calendar ) : get_option( 'admin_email' );
        do_action( 'booked_confirmation_email', $tokens['email'], $subject, $content, $from ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- QuickCal's own mail hook, fired as its booking form does.
    } else {
        do_action( 'booked_cancellation_email', $tokens['email'], $subject, $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- QuickCal's own mail hook, fired as its admin screen does.
    }
    return true;
}

/* ----- Appointments -------------------------------------------------------------------- */

/** The calendar an appointment is in (0 = the default calendar). */
function att_mcp_quickcal_appointment_calendar( $id ) {
    $terms = wp_get_object_terms( (int) $id, 'booked_custom_calendars', array( 'fields' => 'ids' ) );
    return ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0] : 0;
}

/** Booking-form answers from _cf_meta_value (QuickCal stores them as HTML). */
function att_mcp_quickcal_answers_out( $html ) {
    $out = array();
    if ( is_string( $html ) && preg_match_all( '#<p class="cf-meta-value"><strong>(.*?)</strong><br\s*/?>(.*?)</p>#s', $html, $matches, PREG_SET_ORDER ) ) {
        foreach ( $matches as $match ) {
            $out[] = array(
                'label' => html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES, 'UTF-8' ),
                'value' => html_entity_decode( wp_strip_all_tags( $match[2] ), ENT_QUOTES, 'UTF-8' ),
            );
        }
    }
    return $out;
}

/** Booking-form answers from the input as QuickCal's HTML: array( html, answers ). */
function att_mcp_quickcal_answers_in( $answers ) {
    $pairs = array();
    foreach ( is_array( $answers ) ? $answers : array() as $key => $value ) {
        $label = $key;
        if ( is_array( $value ) && isset( $value['label'] ) ) {
            $label = $value['label'];
            $value = isset( $value['value'] ) ? $value['value'] : '';
        }
        $label = is_scalar( $label ) ? sanitize_text_field( (string) $label ) : '';
        if ( is_array( $value ) ) {
            $value = implode( ', ', array_map( 'sanitize_text_field', array_filter( $value, 'is_scalar' ) ) );
        } else {
            $value = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
        }
        if ( '' !== $label && '' !== $value ) {
            $pairs[] = array( 'label' => $label, 'value' => $value );
        }
    }
    $html = '';
    foreach ( $pairs as $pair ) {
        // The same markup QuickCal's booking handlers write.
        $html .= '<p class="cf-meta-value"><strong>' . esc_html( $pair['label'] ) . '</strong><br>' . $pair['value'] . '</p>';
    }
    return array( $html, $pairs );
}

function att_mcp_quickcal_appointment_out( $post ) {
    $id       = (int) $post->ID;
    $ts       = (int) get_post_meta( $id, '_appointment_timestamp', true );
    $slot     = (string) get_post_meta( $id, '_appointment_timeslot', true );
    $times    = explode( '-', $slot . '-' );
    $calendar = att_mcp_quickcal_appointment_calendar( $id );
    $user_id  = function_exists( 'quickcal_get_appointment_owner_id' ) ? (int) quickcal_get_appointment_owner_id( $id ) : (int) get_post_meta( $id, '_appointment_user', true );
    if ( $user_id ) {
        $user     = get_userdata( $user_id );
        $customer = array(
            'type'    => 'user',
            'user_id' => $user_id,
            'name'    => function_exists( 'quickcal_get_name' ) ? (string) quickcal_get_name( $user_id ) : ( $user ? $user->display_name : '' ),
            'email'   => $user ? $user->user_email : '',
        );
    } else {
        $customer = array(
            'type'    => 'guest',
            'name'    => trim( get_post_meta( $id, '_appointment_guest_name', true ) . ' ' . get_post_meta( $id, '_appointment_guest_surname', true ) ),
            'email'   => (string) get_post_meta( $id, '_appointment_guest_email', true ),
        );
    }
    $statuses = array( 'publish' => 'approved', 'future' => 'approved', 'draft' => 'pending', 'booked_wc_awaiting' => 'awaiting payment' );
    $out      = array(
        'id'       => $id,
        'calendar' => att_mcp_quickcal_calendar_brief( $calendar ),
        'date'     => $ts ? gmdate( 'Y-m-d', $ts ) : '',
        'start'    => 4 === strlen( $times[0] ) ? substr( $times[0], 0, 2 ) . ':' . substr( $times[0], 2 ) : '',
        'end'      => 4 === strlen( $times[1] ) ? substr( $times[1], 0, 2 ) . ':' . substr( $times[1], 2 ) : '',
        'slot'     => $slot,
        'title'    => (string) get_post_meta( $id, '_appointment_title', true ),
        'status'   => isset( $statuses[ $post->post_status ] ) ? $statuses[ $post->post_status ] : $post->post_status,
        'customer' => $customer,
        'answers'  => att_mcp_quickcal_answers_out( get_post_meta( $id, '_cf_meta_value', true ) ),
    );
    if ( '0000-2400' === $slot ) {
        $out['all_day'] = true;
    }
    return $out;
}

/**
 * The slot an input names on a date: array( slot, title, offered, offered_list ), or
 * WP_Error. "slot" is taken as is; "start" (+ "end") must match a slot offered that day
 * unless both times are given.
 */
function att_mcp_quickcal_resolve_slot( $input, $calendar, $date, $entries = null ) {
    $day     = att_mcp_quickcal_day_slots( $calendar, $date, $entries );
    $offered = array_map( 'strval', array_keys( is_array( $day['slots'] ) ? $day['slots'] : array() ) );
    sort( $offered );
    $list = $offered ? implode( ', ', $offered ) : 'none';
    if ( isset( $input['slot'] ) && '' !== (string) $input['slot'] ) {
        $slot = (string) $input['slot'];
        if ( ! att_mcp_quickcal_valid_slot( $slot ) ) {
            return new WP_Error( 'att_mcp_bad_input', '"slot" must look like "0900-1000" (24-hour start and end).' );
        }
    } elseif ( isset( $input['start'] ) && '' !== (string) $input['start'] ) {
        $start = att_mcp_quickcal_time( $input['start'] );
        $end   = isset( $input['end'] ) && '' !== (string) $input['end'] ? att_mcp_quickcal_time( $input['end'], true ) : '';
        if ( '' === $start ) {
            return new WP_Error( 'att_mcp_bad_input', '"start" must be a 24-hour time like "09:00".' );
        }
        $matches = array();
        foreach ( $offered as $key ) {
            if ( 0 === strpos( $key, $start . '-' ) && ( '' === $end || $key === $start . '-' . $end ) ) {
                $matches[] = $key;
            }
        }
        if ( count( $matches ) > 1 ) {
            return new WP_Error( 'att_mcp_bad_input', sprintf( 'Several time slots start at %1$s on %2$s (%3$s): pass "slot".', $input['start'], $date, implode( ', ', $matches ) ) );
        }
        if ( $matches ) {
            $slot = $matches[0];
        } elseif ( '' !== $end && att_mcp_quickcal_valid_slot( $start . '-' . $end ) ) {
            $slot = $start . '-' . $end; // not offered: only with ignore_availability
        } else {
            return new WP_Error( 'att_mcp_slot_unavailable', sprintf( 'No time slot starts at %1$s on %2$s in this calendar. Slots that day: %3$s (att/quickcal-get-availability shows the free ones).', $input['start'], $date, $list ) );
        }
    } else {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "start" (e.g. "09:00") or "slot" (e.g. "0900-1000").' );
    }
    $title = isset( $day['details'][ $slot ]['title'] ) && is_scalar( $day['details'][ $slot ]['title'] ) ? html_entity_decode( (string) $day['details'][ $slot ]['title'], ENT_QUOTES, 'UTF-8' ) : '';
    return array( 'slot' => $slot, 'title' => $title, 'offered' => in_array( $slot, $offered, true ), 'offered_list' => $list );
}

/** Can this slot be booked (offered, not blocked, a space free, not past)? true or WP_Error. */
function att_mcp_quickcal_check_bookable( $calendar, $date, $slot, $exclude = 0 ) {
    $hint = ' Pass "ignore_availability": true to book it anyway.';
    if ( ! $slot['offered'] ) {
        return new WP_Error( 'att_mcp_slot_unavailable', sprintf( 'This calendar does not offer %1$s on %2$s (slots that day: %3$s).', $slot['slot'], $date, $slot['offered_list'] ) . $hint );
    }
    if ( att_mcp_quickcal_ts( $date, $slot['slot'] ) < att_mcp_quickcal_now() ) {
        return new WP_Error( 'att_mcp_slot_unavailable', sprintf( '%1$s %2$s is in the past.', $date, $slot['slot'] ) . $hint );
    }
    if ( att_mcp_quickcal_is_blocked( $calendar, $date, $slot['slot'] ) ) {
        return new WP_Error( 'att_mcp_slot_unavailable', sprintf( '%1$s on %2$s is blocked (att/quickcal-set-date-slots "unblock" opens it).', $slot['slot'], $date ) . $hint );
    }
    if ( att_mcp_quickcal_spaces_left( $calendar, $date, $slot['slot'], $exclude ) < 1 ) {
        return new WP_Error( 'att_mcp_slot_unavailable', sprintf( '%1$s on %2$s is fully booked.', $slot['slot'], $date ) . $hint );
    }
    return true;
}

/** The customer of a new appointment: array( user_id, name, surname, email ), or WP_Error. */
function att_mcp_quickcal_customer_in( $input ) {
    if ( ! empty( $input['user_id'] ) ) {
        $user = get_userdata( (int) $input['user_id'] );
        if ( ! $user ) {
            return new WP_Error( 'att_mcp_not_found', 'No user with that "user_id".' );
        }
        return array( 'user_id' => (int) $user->ID, 'name' => '', 'surname' => '', 'email' => $user->user_email );
    }
    $guest = att_mcp_quickcal_guest_in( $input, array( 'name' => '', 'surname' => '', 'email' => '' ) );
    if ( is_wp_error( $guest ) ) {
        return $guest;
    }
    if ( '' === $guest['name'] ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass the customer: "user_id" of a registered user, or a guest "name" (and "email").' );
    }
    return array_merge( array( 'user_id' => 0 ), $guest );
}

/** A guest's name, surname and email from the input over the current ones, or WP_Error. */
function att_mcp_quickcal_guest_in( $input, $current ) {
    $guest = $current;
    foreach ( array( 'name', 'surname' ) as $key ) {
        if ( isset( $input[ $key ] ) ) {
            $guest[ $key ] = sanitize_text_field( (string) $input[ $key ] );
        }
    }
    if ( isset( $input['email'] ) ) {
        $email = trim( (string) $input['email'] );
        if ( '' !== $email && ! is_email( $email ) ) {
            return new WP_Error( 'att_mcp_bad_input', '"email" is not a valid email address.' );
        }
        $guest['email'] = sanitize_email( $email );
    }
    if ( '' === $guest['email'] && get_option( 'booked_require_guest_email_address' ) ) {
        return new WP_Error( 'att_mcp_bad_input', 'QuickCal requires an email address for guests (require_guest_email is on): pass "email".' );
    }
    return $guest;
}

/** Notes about answers that do not match the calendar's booking form. */
function att_mcp_quickcal_answer_notes( $calendar, $answers ) {
    $form     = att_mcp_quickcal_fields_out( att_mcp_quickcal_fields_raw( $calendar, true ) );
    $labels   = array();
    $required = array();
    foreach ( $form['fields'] as $field ) {
        if ( isset( $field['label'] ) ) {
            $labels[] = $field['label'];
            if ( ! empty( $field['required'] ) ) {
                $required[] = $field['label'];
            }
        }
    }
    $given = wp_list_pluck( $answers, 'label' );
    $notes = array();
    if ( array_diff( $required, $given ) ) {
        $notes[] = 'Required booking-form answers not given: ' . implode( ', ', array_diff( $required, $given ) ) . '.';
    }
    if ( array_diff( $given, $labels ) ) {
        $notes[] = 'Not in this calendar\'s booking form (kept anyway): ' . implode( ', ', array_diff( $given, $labels ) ) . '.';
    }
    return $notes;
}

/* ----- History (undo) ------------------------------------------------------------------- */

function att_mcp_capture_quickcal_calendar( $id ) {
    $term   = get_term( (int) $id, 'booked_custom_calendars' );
    $exists = $term && ! is_wp_error( $term );
    return array(
        'type'    => 'quickcal_calendar',
        'target'  => (int) $id,
        'existed' => $exists,
        'value'   => $exists ? array( 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'meta' => get_option( 'taxonomy_' . (int) $id, null ) ) : null,
    );
}

function att_mcp_restore_quickcal_calendar( $item ) {
    global $wpdb;
    if ( ! att_mcp_detect_quickcal() || ! taxonomy_exists( 'booked_custom_calendars' ) ) {
        return new WP_Error( 'att_mcp_plugin_inactive', 'QuickCal is not active, so this calendar change cannot be undone now.' );
    }
    $id     = (int) $item['target'];
    $term   = get_term( $id, 'booked_custom_calendars' );
    $exists = $term && ! is_wp_error( $term );

    if ( empty( $item['existed'] ) ) {
        if ( $exists ) {
            if ( att_mcp_quickcal_calendar_appointments( $id ) ) {
                return new WP_Error( 'att_mcp_calendar_in_use', sprintf( 'Calendar #%d has appointments now; cancel them before undoing its creation.', $id ) );
            }
            wp_delete_term( $id, 'booked_custom_calendars' );
            delete_option( 'taxonomy_' . $id );
        }
        return true;
    }

    $value = (array) $item['value'];
    if ( $exists ) {
        $result = wp_update_term( $id, 'booked_custom_calendars', wp_slash( array( 'name' => $value['name'], 'slug' => $value['slug'], 'description' => $value['description'] ) ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    } else {
        // Deleted since: put it back under its own id, so the slots, forms and pages that name it match again.
        if ( get_term( $id ) ) {
            return new WP_Error( 'att_mcp_id_taken', sprintf( 'Calendar #%d cannot be restored: its id is now used by another term.', $id ) );
        }
        $slug = (string) $value['slug'];
        if ( get_term_by( 'slug', $slug, 'booked_custom_calendars' ) ) {
            $slug .= '-' . $id;
        }
        $wpdb->insert( $wpdb->terms, array( 'term_id' => $id, 'name' => (string) $value['name'], 'slug' => $slug, 'term_group' => 0 ), array( '%d', '%s', '%s', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- core's term API cannot insert a term with a given id
        $wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $id, 'taxonomy' => 'booked_custom_calendars', 'description' => (string) $value['description'], 'parent' => 0, 'count' => 0 ), array( '%d', '%s', '%s', '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- as above
        clean_term_cache( $id, 'booked_custom_calendars' );
    }
    if ( null === $value['meta'] ) {
        delete_option( 'taxonomy_' . $id );
    } else {
        update_option( 'taxonomy_' . $id, $value['meta'] );
    }
    return true;
}

function att_mcp_capture_quickcal_appointment( $id ) {
    $post = get_post( (int) $id );
    if ( ! $post || 'booked_appointments' !== $post->post_type ) {
        return array( 'type' => 'quickcal_appointment', 'target' => (int) $id, 'existed' => false, 'value' => null );
    }
    $meta = array();
    foreach ( get_post_meta( $post->ID ) as $key => $values ) {
        if ( ! in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
            $meta[ $key ] = array_map( 'maybe_unserialize', (array) $values );
        }
    }
    $terms = wp_get_object_terms( $post->ID, 'booked_custom_calendars', array( 'fields' => 'ids' ) );
    return array(
        'type'    => 'quickcal_appointment',
        'target'  => (int) $post->ID,
        'existed' => true,
        'value'   => array(
            'post'      => array(
                'post_title'    => $post->post_title,
                'post_status'   => $post->post_status,
                'post_date'     => $post->post_date,
                'post_date_gmt' => $post->post_date_gmt,
                'post_author'   => (int) $post->post_author,
                'post_content'  => $post->post_content,
            ),
            'meta'      => $meta,
            'calendars' => is_wp_error( $terms ) ? array() : array_map( 'intval', $terms ),
        ),
    );
}

function att_mcp_restore_quickcal_appointment( $item ) {
    if ( ! att_mcp_detect_quickcal() || ! post_type_exists( 'booked_appointments' ) ) {
        return new WP_Error( 'att_mcp_plugin_inactive', 'QuickCal is not active, so this appointment change cannot be undone now.' );
    }
    $id     = (int) $item['target'];
    $post   = get_post( $id );
    $exists = $post && 'booked_appointments' === $post->post_type;

    if ( empty( $item['existed'] ) ) {
        if ( $exists ) {
            wp_delete_post( $id, true );
        }
        return true;
    }
    if ( $post && ! $exists ) {
        return new WP_Error( 'att_mcp_id_taken', sprintf( 'Appointment #%d cannot be restored: its id is now used by other content.', $id ) );
    }

    $value = (array) $item['value'];
    $data  = array_merge( (array) $value['post'], array( 'post_type' => 'booked_appointments' ) );
    if ( $exists ) {
        $data['ID'] = $id;
        $result     = wp_update_post( wp_slash( $data ), true );
    } else {
        $data['import_id'] = $id; // cancelled since: back under its own id
        $result            = wp_insert_post( wp_slash( $data ), true );
    }
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    if ( (int) $result !== $id ) {
        wp_delete_post( (int) $result, true );
        return new WP_Error( 'att_mcp_id_taken', sprintf( 'Appointment #%d could not be restored under its own id.', $id ) );
    }
    if ( 'publish' === $value['post']['post_status'] ) {
        wp_publish_post( $id ); // QuickCal keeps approved appointments "publish" even when dated in the future
    }
    foreach ( array_keys( get_post_meta( $id ) ) as $key ) {
        if ( ! isset( $value['meta'][ $key ] ) && ! in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
            delete_post_meta( $id, $key );
        }
    }
    foreach ( (array) $value['meta'] as $key => $rows ) {
        delete_post_meta( $id, $key );
        foreach ( (array) $rows as $row ) {
            add_post_meta( $id, $key, wp_slash( $row ) );
        }
    }
    wp_set_object_terms( $id, array_map( 'intval', (array) $value['calendars'] ), 'booked_custom_calendars' );
    return true;
}

/** May the current user undo this calendar or appointment change? */
function att_mcp_quickcal_can_restore( $item ) {
    if ( ! att_mcp_detect_quickcal() ) {
        return false;
    }
    if ( current_user_can( 'manage_booked_options' ) ) {
        return true;
    }
    if ( 'quickcal_appointment' !== $item['type'] || ! current_user_can( 'edit_booked_appointments' ) ) {
        return false;
    }
    // A Booking Agent: every calendar involved (then and now) must be one they manage.
    $calendars = isset( $item['value']['calendars'] ) ? (array) $item['value']['calendars'] : array();
    if ( get_post( (int) $item['target'] ) ) {
        $calendars[] = att_mcp_quickcal_appointment_calendar( (int) $item['target'] );
    }
    foreach ( $calendars ? $calendars : array( 0 ) as $calendar ) {
        if ( ! att_mcp_quickcal_can_calendar( (int) $calendar ) ) {
            return false;
        }
    }
    return true;
}

/* ----- Execute: reads ------------------------------------------------------------------- */

function att_mcp_execute_quickcal_get_setup( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $only = null;
    if ( isset( $input['calendar'] ) ) {
        $only = att_mcp_quickcal_calendar_arg( $input );
        if ( is_wp_error( $only ) ) {
            return $only;
        }
    }
    $all      = array( 'calendars', 'weekly_slots', 'date_slots', 'form_fields', 'settings', 'emails', 'pages' );
    $sections = ! empty( $input['sections'] ) ? array_intersect( $all, (array) $input['sections'] ) : $all;
    $ids      = array();
    foreach ( array_merge( array( 0 ), wp_list_pluck( att_mcp_quickcal_calendar_terms(), 'term_id' ) ) as $id ) {
        if ( att_mcp_quickcal_can_calendar( (int) $id ) ) {
            $ids[] = (int) $id; // Booking Agents: the calendars assigned to them
        }
    }
    if ( null !== $only ) {
        if ( ! att_mcp_quickcal_can_calendar( $only ) ) {
            return new WP_Error( 'att_mcp_forbidden', 'You cannot manage this calendar.' );
        }
        $ids = array( $only );
    }
    $manager = current_user_can( 'manage_booked_options' );
    if ( ! $manager ) {
        $sections = array_diff( $sections, array( 'settings', 'emails' ) ); // QuickCal administrators only
    }
    $out = array( 'quickcal_version' => defined( 'QUICKCAL_VERSION' ) ? QUICKCAL_VERSION : '' );

    if ( in_array( 'calendars', $sections, true ) ) {
        $out['calendars'] = array_values( array_filter( array_map( 'att_mcp_quickcal_calendar_out', $ids ) ) );
    }
    if ( in_array( 'weekly_slots', $sections, true ) ) {
        $out['weekly_slots'] = array();
        foreach ( $ids as $id ) {
            $out['weekly_slots'][] = array_merge( array( 'calendar' => $id ), att_mcp_quickcal_weekly_out( $id ) );
        }
    }
    if ( in_array( 'date_slots', $sections, true ) ) {
        $mine                 = function ( $row ) use ( $manager, $ids ) { return $manager || 'all' === $row['calendar'] || in_array( $row['calendar'], $ids, true ); };
        $out['date_slots']    = array_values( array_filter( att_mcp_quickcal_date_entries_out( att_mcp_quickcal_date_entries(), $only ), $mine ) );
        $out['blocked_slots'] = array_values( array_filter( att_mcp_quickcal_blocked_out( $only ), $mine ) );
    }
    if ( in_array( 'form_fields', $sections, true ) ) {
        $out['form_fields'] = array();
        foreach ( $ids as $id ) {
            $own  = att_mcp_quickcal_fields_raw( $id );
            $form = att_mcp_quickcal_fields_out( att_mcp_quickcal_fields_raw( $id, true ) );
            $out['form_fields'][] = array( 'calendar' => $id, 'uses' => ( $id && ! $own ) ? 'default calendar' : 'own', 'fields' => $form['fields'] );
        }
    }
    if ( in_array( 'settings', $sections, true ) ) {
        $out['settings'] = array();
        foreach ( att_mcp_quickcal_settings_map() as $key => $cfg ) {
            $out['settings'][ $key ] = att_mcp_quickcal_setting_get( $cfg );
        }
    }
    if ( in_array( 'emails', $sections, true ) ) {
        $out['emails'] = att_mcp_quickcal_emails_out();
    }
    if ( in_array( 'pages', $sections, true ) ) {
        $out['pages'] = att_mcp_quickcal_pages();
    }
    $out['shortcodes'] = array(
        '[quickcal-calendar]'                     => 'The booking calendar (default calendar). Attributes: calendar=ID, switcher=true (a calendar picker), style=list, size=small, members-only=true.',
        '[quickcal-appointments]'                 => 'The logged-in customer\'s upcoming appointments.',
        '[quickcal-profile]'                      => 'A customer profile page with login/registration and their appointments.',
    );
    $out['notes'] = att_mcp_quickcal_setup_notes();
    return $out;
}

/** Pages and posts that show a QuickCal shortcode. */
function att_mcp_quickcal_pages() {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a content search no WP_Query argument can express
        "SELECT ID, post_title, post_type, post_status, post_content FROM %i WHERE post_type NOT IN ('revision', 'booked_appointments') AND post_status IN ('publish', 'draft', 'pending', 'private', 'future') AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID LIMIT 50",
        $wpdb->posts,
        '%' . $wpdb->esc_like( '[quickcal-' ) . '%',
        '%' . $wpdb->esc_like( '[booked-' ) . '%'
    ) );
    $out = array();
    foreach ( (array) $rows as $row ) {
        if ( 'publish' !== $row->post_status && ! current_user_can( 'edit_post', (int) $row->ID ) ) {
            continue;
        }
        preg_match_all( '/\[(?:quickcal|booked)-[a-z-]+[^\]]*\]/', (string) $row->post_content, $matches );
        if ( ! $matches[0] ) {
            continue;
        }
        $page = array(
            'id'         => (int) $row->ID,
            'title'      => $row->post_title,
            'type'       => $row->post_type,
            'status'     => $row->post_status,
            'url'        => get_permalink( (int) $row->ID ),
            'shortcodes' => array_values( array_unique( $matches[0] ) ),
        );
        // A shortcode naming a calendar that no longer exists shows the default calendar.
        preg_match_all( '/calendar\s*=\s*["\']?(\d+)/', implode( ' ', $page['shortcodes'] ), $ids );
        foreach ( array_unique( array_map( 'intval', $ids[1] ) ) as $id ) {
            $term = get_term( $id, 'booked_custom_calendars' );
            if ( ! $term || is_wp_error( $term ) ) {
                $page['missing_calendars'][] = $id;
            }
        }
        $out[] = $page;
    }
    return $out;
}

/** What still keeps customers from booking, or makes QuickCal behave unexpectedly. */
function att_mcp_quickcal_setup_notes() {
    $notes = array();
    $ids   = array( 0 );
    foreach ( att_mcp_quickcal_calendar_terms() as $term ) {
        $ids[] = (int) $term->term_id;
    }
    $any = false;
    foreach ( $ids as $id ) {
        $weekly = att_mcp_quickcal_weekly_effective( $id );
        foreach ( att_mcp_quickcal_days() as $day ) {
            $any = $any || ( ! empty( $weekly[ $day ] ) && is_array( $weekly[ $day ] ) );
        }
    }
    foreach ( att_mcp_quickcal_date_entries() as $entry ) {
        $any = $any || ( ! $entry['closed'] && $entry['slots'] );
    }
    if ( ! $any ) {
        $notes[] = 'No time slots are set, so nobody can book yet: add weekly slots with att/quickcal-set-weekly-slots.';
    }
    $pages = att_mcp_quickcal_pages();
    if ( ! $pages ) {
        $notes[] = 'No page shows a booking calendar yet: put [quickcal-calendar] (or [quickcal-calendar calendar=ID]) on a page, e.g. with att/create-page, and look at it with att/render-page.';
    }
    foreach ( $pages as $page ) {
        if ( ! empty( $page['missing_calendars'] ) ) {
            $notes[] = sprintf( '"%1$s" (page %2$d) shows calendar %3$s, which does not exist (QuickCal shows the default calendar there): fix its shortcode.', $page['title'], $page['id'], implode( ', ', $page['missing_calendars'] ) );
        }
    }
    $off = array();
    foreach ( att_mcp_quickcal_emails_out() as $key => $email ) {
        if ( ! $email['active'] ) {
            $off[] = $key;
        }
    }
    if ( $off ) {
        $notes[] = 'These emails are off because their subject or text is empty: ' . implode( ', ', $off ) . '. Write them with att/quickcal-update-emails.';
    }
    if ( 'guest' !== get_option( 'booked_booking_type', 'registered' ) && ! get_option( 'users_can_register' ) ) {
        $notes[] = 'Only customers with an account can book (booking_type "registered"), and registration is off in Settings > General, so new customers cannot book. Set booking_type to "guest" with att/quickcal-update-settings, or allow registration.';
    }
    if ( 'publish' !== get_option( 'booked_new_appointment_default', 'draft' ) ) {
        $notes[] = 'New bookings wait for approval (new_appointment_status "pending"): approve them with att/quickcal-save-appointment {"id", "status": "approved"}.';
    }
    return $notes;
}

function att_mcp_execute_quickcal_get_availability( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $calendar = att_mcp_quickcal_calendar_arg( $input );
    if ( is_wp_error( $calendar ) ) {
        return $calendar;
    }
    $from = isset( $input['from'] ) && '' !== (string) $input['from'] ? att_mcp_quickcal_date( $input['from'] ) : current_time( 'Y-m-d' );
    if ( '' === $from ) {
        return new WP_Error( 'att_mcp_bad_input', '"from" must be a date (YYYY-MM-DD).' );
    }
    $days    = att_mcp_int_arg( $input, 'days', 14, 1, 62 );
    $to      = gmdate( 'Y-m-d', (int) strtotime( $from . ' +' . ( $days - 1 ) . ' days' ) );
    $entries = att_mcp_quickcal_date_entries();
    $weekly  = att_mcp_quickcal_weekly_effective( $calendar );
    $booked  = att_mcp_quickcal_booked_counts( $calendar, $from, $to );
    $now     = att_mcp_quickcal_now();
    $buffer  = (float) get_option( 'booked_appointment_buffer', 0 );
    $cutoff  = $now + (int) round( $buffer * HOUR_IN_SECONDS );
    $before  = att_mcp_quickcal_date( get_option( 'booked_prevent_appointments_before', '' ) );
    $after   = att_mcp_quickcal_date( get_option( 'booked_prevent_appointments_after', '' ) );

    $out = array();
    for ( $i = 0; $i < $days; $i++ ) {
        $date   = gmdate( 'Y-m-d', (int) strtotime( $from . ' +' . $i . ' days' ) );
        $day    = att_mcp_quickcal_day_slots( $calendar, $date, $entries, $weekly );
        $window = ( '' === $before || $date >= $before ) && ( '' === $after || $date <= $after );
        $slots  = array();
        foreach ( att_mcp_quickcal_slots_out( $day['slots'], $day['details'] ) as $slot ) {
            $taken            = isset( $booked[ $date . '|' . $slot['slot'] ] ) ? $booked[ $date . '|' . $slot['slot'] ] : 0;
            $blocked          = att_mcp_quickcal_is_blocked( $calendar, $date, $slot['slot'] );
            $slot['booked']   = $taken;
            $slot['left']     = $blocked ? 0 : max( 0, $slot['spots'] - $taken );
            if ( $blocked ) {
                $slot['blocked'] = true;
            }
            $slot['bookable'] = $slot['left'] > 0 && $window && att_mcp_quickcal_ts( $date, $slot['slot'] ) >= $cutoff;
            $slots[]          = $slot;
        }
        $out[] = array( 'date' => $date, 'weekday' => gmdate( 'D', (int) strtotime( $date ) ), 'source' => $day['source'], 'slots' => $slots );
    }
    return array(
        'calendar' => att_mcp_quickcal_calendar_brief( $calendar ),
        'from'     => $from,
        'to'       => $to,
        'rules'    => array(
            'now'                  => gmdate( 'Y-m-d H:i', $now ),
            'booking_buffer_hours' => $buffer,
            'bookable_from'        => $before,
            'bookable_until'       => $after,
        ),
        'days'     => $out,
        'note'     => 'source: weekly = the weekly slots; date = a date-specific entry; closed = a closed day. "bookable" is what a customer can book online right now.',
    );
}

function att_mcp_execute_quickcal_get_appointments( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    if ( ! empty( $input['id'] ) ) {
        $id = (int) $input['id'];
        if ( 'booked_appointments' !== get_post_type( $id ) ) {
            return new WP_Error( 'att_mcp_not_found', 'No QuickCal appointment with that id.' );
        }
        if ( ! att_mcp_quickcal_can_appointment( $id ) ) {
            return new WP_Error( 'att_mcp_forbidden', 'You cannot manage this appointment.' );
        }
        return array( 'total' => 1, 'appointments' => array( att_mcp_quickcal_appointment_out( get_post( $id ) ) ) );
    }
    $from = isset( $input['from'] ) && '' !== (string) $input['from'] ? att_mcp_quickcal_date( $input['from'] ) : current_time( 'Y-m-d' );
    $to   = isset( $input['to'] ) && '' !== (string) $input['to'] ? att_mcp_quickcal_date( $input['to'] ) : ( '' !== $from ? gmdate( 'Y-m-d', (int) strtotime( $from . ' +30 days' ) ) : '' );
    if ( '' === $from || '' === $to || $to < $from ) {
        return new WP_Error( 'att_mcp_bad_input', '"from" and "to" must be dates (YYYY-MM-DD), "to" not before "from".' );
    }
    $statuses = array( 'pending' => 'draft', 'approved' => array( 'publish', 'future' ), 'any' => 'any' );
    $status   = isset( $input['status'] ) && isset( $statuses[ $input['status'] ] ) ? (string) $input['status'] : 'any';
    $args     = array(
        'post_type'      => 'booked_appointments',
        'post_status'    => $statuses[ $status ],
        'posts_per_page' => att_mcp_int_arg( $input, 'limit', 50, 1, 200 ),
        'meta_key'       => '_appointment_timestamp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- QuickCal keeps the appointment time only in this meta.
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
        'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- as above; bounded by the date range and limit.
            array(
                'key'     => '_appointment_timestamp',
                'value'   => array( strtotime( $from . ' 00:00:00' ), strtotime( $to . ' 23:59:59' ) ),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ),
        ),
    );
    $calendars = null;
    if ( isset( $input['calendar'] ) ) {
        $calendar = att_mcp_quickcal_calendar_arg( $input );
        if ( is_wp_error( $calendar ) ) {
            return $calendar;
        }
        if ( ! att_mcp_quickcal_can_calendar( $calendar ) ) {
            return new WP_Error( 'att_mcp_forbidden', 'You cannot manage this calendar.' );
        }
        $calendars = array( $calendar );
    } elseif ( ! current_user_can( 'manage_booked_options' ) ) {
        $calendars = array();
        foreach ( att_mcp_quickcal_calendar_terms() as $term ) {
            if ( att_mcp_quickcal_can_calendar( (int) $term->term_id ) ) {
                $calendars[] = (int) $term->term_id;
            }
        }
        if ( ! $calendars ) {
            return array( 'from' => $from, 'to' => $to, 'total' => 0, 'appointments' => array(), 'note' => 'No calendar is assigned to you.' );
        }
    }
    if ( array( 0 ) === $calendars ) {
        $args['tax_query'] = array( array( 'taxonomy' => 'booked_custom_calendars', 'operator' => 'NOT EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the default calendar = no calendar term.
    } elseif ( null !== $calendars ) {
        $args['tax_query'] = array( array( 'taxonomy' => 'booked_custom_calendars', 'field' => 'term_id', 'terms' => $calendars ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- QuickCal's calendars are this taxonomy.
    }
    $query = new WP_Query( $args );
    return array(
        'from'         => $from,
        'to'           => $to,
        'total'        => (int) $query->found_posts,
        'appointments' => array_map( 'att_mcp_quickcal_appointment_out', $query->posts ),
    );
}

/* ----- Execute: calendars ------------------------------------------------------------------ */

function att_mcp_execute_quickcal_save_calendar( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
    $args = array();
    if ( isset( $input['name'] ) ) {
        $args['name'] = sanitize_text_field( (string) $input['name'] );
        if ( '' === $args['name'] ) {
            return new WP_Error( 'att_mcp_bad_input', '"name" cannot be empty.' );
        }
    }
    if ( isset( $input['description'] ) ) {
        $args['description'] = sanitize_textarea_field( (string) $input['description'] );
    }
    if ( isset( $input['slug'] ) ) {
        $args['slug'] = sanitize_title( (string) $input['slug'] );
        if ( '' === $args['slug'] ) {
            return new WP_Error( 'att_mcp_bad_input', '"slug" cannot be empty.' );
        }
    }
    $email = null;
    if ( isset( $input['notification_email'] ) ) {
        $email = '' === trim( (string) $input['notification_email'] ) ? '' : att_mcp_quickcal_staff_email( $input['notification_email'] );
        if ( is_wp_error( $email ) ) {
            return $email;
        }
    }

    if ( $id ) {
        $check = att_mcp_quickcal_calendar_arg( array( 'calendar' => $id ) );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        if ( ! $args && null === $email ) {
            return new WP_Error( 'att_mcp_bad_input', 'Nothing to change: pass name, description, slug or notification_email.' );
        }
        $before = att_mcp_capture_quickcal_calendar( $id );
        if ( $args ) {
            $result = wp_update_term( $id, 'booked_custom_calendars', wp_slash( $args ) );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }
    } else {
        if ( empty( $args['name'] ) ) {
            return new WP_Error( 'att_mcp_bad_input', '"name" is required for a new calendar.' );
        }
        $name = $args['name'];
        unset( $args['name'] );
        $result = wp_insert_term( wp_slash( $name ), 'booked_custom_calendars', wp_slash( $args ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $id     = (int) $result['term_id'];
        $before = array( 'type' => 'quickcal_calendar', 'target' => $id, 'existed' => false, 'value' => null );
    }
    if ( null !== $email ) {
        // QuickCal keeps a calendar's settings in the option taxonomy_{id} (its term screen writes it).
        $meta = get_option( 'taxonomy_' . $id );
        $meta = is_array( $meta ) ? $meta : array();
        if ( '' === $email ) {
            unset( $meta['notifications_user_id'] );
        } else {
            $meta['notifications_user_id'] = $email;
        }
        update_option( 'taxonomy_' . $id, $meta );
    }
    $created = empty( $before['existed'] );
    return array(
        'calendar'  => att_mcp_quickcal_calendar_out( $id ),
        'created'   => $created,
        'change_id' => att_mcp_record_change( array( $before ), 'QuickCal calendar "' . att_mcp_quickcal_calendar_brief( $id )['name'] . '"' ),
        'next'      => $created ? 'It uses the default calendar\'s weekly slots and form until you set its own (att/quickcal-set-weekly-slots, att/quickcal-set-form-fields). Show it on a page with its shortcode.' : '',
    );
}

function att_mcp_execute_quickcal_delete_calendar( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
    if ( $id <= 0 ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass the "id" of a calendar (the default calendar cannot be deleted).' );
    }
    $check = att_mcp_quickcal_calendar_arg( array( 'calendar' => $id ) );
    if ( is_wp_error( $check ) ) {
        return $check;
    }
    $count = att_mcp_quickcal_calendar_appointments( $id );
    if ( $count ) {
        return new WP_Error( 'att_mcp_calendar_in_use', sprintf( 'This calendar has %d appointment(s). Cancel them first (att/quickcal-get-appointments, att/quickcal-cancel-appointments), or keep the calendar.', $count ) );
    }
    $name  = att_mcp_quickcal_calendar_brief( $id )['name'];
    $items = array(
        att_mcp_capture_quickcal_calendar( $id ),
        att_mcp_capture( 'option', att_mcp_quickcal_weekly_option( $id ) ),
        att_mcp_capture( 'option', att_mcp_quickcal_fields_option( $id ) ),
    );
    // Its date-specific entries and blocked slots (QuickCal's screen would show orphans as the default calendar's).
    $entries = att_mcp_quickcal_date_entries();
    $keep    = array();
    foreach ( $entries as $entry ) {
        if ( ! ( null !== $entry['calendar'] && (int) $entry['calendar'] === $id ) ) {
            $keep[] = $entry;
        }
    }
    $blocked = att_mcp_quickcal_blocked();
    if ( count( $keep ) !== count( $entries ) ) {
        $items[] = att_mcp_capture( 'option', 'booked_custom_timeslots_encoded' );
    }
    if ( isset( $blocked[ $id ] ) ) {
        $items[] = att_mcp_capture( 'option', 'booked_disabled_timeslots' );
    }
    $change_id = att_mcp_record_change( $items, 'QuickCal calendar "' . $name . '" deleted' );

    $result = wp_delete_term( $id, 'booked_custom_calendars' );
    if ( is_wp_error( $result ) || ! $result ) {
        return is_wp_error( $result ) ? $result : new WP_Error( 'att_mcp_delete_failed', 'The calendar could not be deleted.' );
    }
    delete_option( 'taxonomy_' . $id );
    delete_option( att_mcp_quickcal_weekly_option( $id ) );
    delete_option( att_mcp_quickcal_fields_option( $id ) );
    if ( count( $keep ) !== count( $entries ) ) {
        att_mcp_quickcal_save_date_entries( $keep );
    }
    if ( isset( $blocked[ $id ] ) ) {
        unset( $blocked[ $id ] );
        update_option( 'booked_disabled_timeslots', $blocked );
    }
    return array( 'deleted' => $id, 'name' => $name, 'change_id' => $change_id, 'note' => 'Pages that showed [quickcal-calendar calendar=' . $id . '] now show the default calendar: update them.' );
}

/* ----- Execute: availability setup ----------------------------------------------------------- */

function att_mcp_execute_quickcal_set_weekly_slots( $input ) {
    $calendar = att_mcp_quickcal_calendar_access( $input );
    if ( is_wp_error( $calendar ) ) {
        return $calendar;
    }
    $option   = att_mcp_quickcal_weekly_option( $calendar );
    $clear    = ! empty( $input['clear'] );
    $has_days = ! empty( $input['days'] ) && is_array( $input['days'] );
    $has_gen  = ! empty( $input['generate'] ) && is_array( $input['generate'] );

    if ( ! empty( $input['use_default'] ) ) {
        if ( ! $calendar ) {
            return new WP_Error( 'att_mcp_bad_input', '"use_default" is for other calendars: the default calendar\'s slots are the default.' );
        }
        if ( $has_days || $has_gen || $clear ) {
            return new WP_Error( 'att_mcp_bad_input', '"use_default" cannot be combined with days, generate or clear.' );
        }
        $item = att_mcp_capture( 'option', $option );
        delete_option( $option );
    } else {
        if ( ! $has_days && ! $has_gen && ! $clear ) {
            return new WP_Error( 'att_mcp_bad_input', 'Pass "days", "generate", "clear": true or "use_default": true.' );
        }
        $new = array(); // weekday => slot => array( key, spots, title )
        if ( $has_days ) {
            foreach ( $input['days'] as $name => $slots ) {
                $day = att_mcp_quickcal_day( $name );
                if ( '' === $day ) {
                    return new WP_Error( 'att_mcp_bad_input', sprintf( '"%s" is not a weekday: use Mon, Tue, Wed, Thu, Fri, Sat or Sun.', $name ) );
                }
                if ( ! is_array( $slots ) ) {
                    return new WP_Error( 'att_mcp_bad_input', sprintf( '"%s" must be a list of slots ([] closes the day).', $name ) );
                }
                $new[ $day ] = isset( $new[ $day ] ) ? $new[ $day ] : array();
                foreach ( array_values( $slots ) as $i => $slot ) {
                    $parsed = att_mcp_quickcal_slot_in( $slot, sprintf( '%s slot %d', $day, $i + 1 ) );
                    if ( is_wp_error( $parsed ) ) {
                        return $parsed;
                    }
                    $new[ $day ][ $parsed['key'] ] = $parsed;
                }
            }
        }
        if ( $has_gen ) {
            $gens = isset( $input['generate']['days'] ) ? array( $input['generate'] ) : array_values( $input['generate'] );
            foreach ( $gens as $i => $gen ) {
                $made = att_mcp_quickcal_generate( $gen, sprintf( 'generate[%d]', $i ) );
                if ( is_wp_error( $made ) ) {
                    return $made;
                }
                foreach ( $made['days'] as $day ) {
                    foreach ( $made['slots'] as $slot ) {
                        $new[ $day ][ $slot['key'] ] = $slot;
                    }
                }
            }
        }
        $item  = att_mcp_capture( 'option', $option );
        $raw   = att_mcp_quickcal_weekly_raw( $calendar );
        $merge = ! empty( $input['merge'] );
        $week  = array();
        foreach ( att_mcp_quickcal_days() as $day ) {
            $counts  = ( ! $clear && isset( $raw[ $day ] ) && is_array( $raw[ $day ] ) ) ? $raw[ $day ] : array();
            $details = ( ! $clear && isset( $raw[ $day . '-details' ] ) && is_array( $raw[ $day . '-details' ] ) ) ? $raw[ $day . '-details' ] : array();
            if ( isset( $new[ $day ] ) ) {
                if ( ! $merge ) {
                    $counts  = array();
                    $details = array();
                }
                foreach ( $new[ $day ] as $key => $slot ) {
                    $counts[ $key ]  = $slot['spots'];
                    $details[ $key ] = array( 'title' => $slot['title'] );
                }
            }
            ksort( $counts );
            // Every weekday is stored, so a calendar with its own week never falls back to the default one.
            $week[ $day ]              = $counts;
            $week[ $day . '-details' ] = array_intersect_key( $details, $counts );
        }
        foreach ( $raw as $key => $value ) {
            if ( ! array_key_exists( $key, $week ) ) {
                $week[ $key ] = $value; // anything else QuickCal or an add-on keeps there
            }
        }
        update_option( $option, $week );
    }
    return array(
        'calendar'  => att_mcp_quickcal_calendar_brief( $calendar ),
        'weekly'    => att_mcp_quickcal_weekly_out( $calendar ),
        'change_id' => att_mcp_record_change( array( $item ), 'QuickCal weekly time slots (' . att_mcp_quickcal_calendar_brief( $calendar )['name'] . ')' ),
    );
}

function att_mcp_execute_quickcal_set_date_slots( $input ) {
    $calendar = att_mcp_quickcal_calendar_access( $input );
    if ( is_wp_error( $calendar ) ) {
        return $calendar;
    }
    $entries       = att_mcp_quickcal_date_entries();
    $blocked       = att_mcp_quickcal_blocked();
    $touch_entries = false;
    $touch_blocked = false;
    $notes         = array();

    if ( ! empty( $input['clear'] ) ) {
        $entries       = array_values( array_filter( $entries, function ( $entry ) use ( $calendar ) { return ! att_mcp_quickcal_entry_is_mine( $entry, $calendar ); } ) );
        $touch_entries = true;
    }
    if ( ! empty( $input['remove_dates'] ) ) {
        foreach ( (array) $input['remove_dates'] as $value ) {
            $date = att_mcp_quickcal_date( $value );
            if ( '' === $date ) {
                return new WP_Error( 'att_mcp_bad_input', '"remove_dates" must be dates (YYYY-MM-DD).' );
            }
            $before  = count( $entries );
            $entries = array_values( array_filter( $entries, function ( $entry ) use ( $calendar, $date ) { return ! ( att_mcp_quickcal_entry_is_mine( $entry, $calendar ) && $entry['start'] === $date ); } ) );
            if ( count( $entries ) === $before ) {
                $notes[] = sprintf( 'No entry of this calendar starts on %s.', $date );
            }
            $touch_entries = true;
        }
    }
    if ( ! empty( $input['dates'] ) ) {
        foreach ( array_values( (array) $input['dates'] ) as $i => $spec ) {
            $where = sprintf( 'dates[%d]', $i );
            $date  = is_array( $spec ) && isset( $spec['date'] ) ? att_mcp_quickcal_date( $spec['date'] ) : '';
            if ( '' === $date ) {
                return new WP_Error( 'att_mcp_bad_input', $where . ' needs a "date" (YYYY-MM-DD).' );
            }
            $end = '';
            if ( isset( $spec['end_date'] ) && '' !== (string) $spec['end_date'] ) {
                $end = att_mcp_quickcal_date( $spec['end_date'] );
                if ( '' === $end || $end < $date ) {
                    return new WP_Error( 'att_mcp_bad_input', $where . ': "end_date" must be a date (YYYY-MM-DD) on or after "date".' );
                }
                if ( ( strtotime( $end ) - strtotime( $date ) ) > 366 * DAY_IN_SECONDS ) {
                    return new WP_Error( 'att_mcp_bad_input', $where . ': a range can cover at most a year.' );
                }
                $end = $end === $date ? '' : $end;
            }
            $closed  = ! empty( $spec['closed'] );
            $slots   = array();
            $details = array();
            if ( ! $closed ) {
                if ( empty( $spec['slots'] ) || ! is_array( $spec['slots'] ) ) {
                    return new WP_Error( 'att_mcp_bad_input', $where . ' needs "slots", or "closed": true.' );
                }
                foreach ( array_values( $spec['slots'] ) as $j => $slot ) {
                    $parsed = att_mcp_quickcal_slot_in( $slot, sprintf( '%s slot %d', $where, $j + 1 ) );
                    if ( is_wp_error( $parsed ) ) {
                        return $parsed;
                    }
                    $slots[ $parsed['key'] ]   = $parsed['spots'];
                    $details[ $parsed['key'] ] = array( 'title' => $parsed['title'] );
                }
                ksort( $slots );
            }
            // An entry of this calendar with the same start date is replaced; a newer entry wins where ranges overlap.
            $entries   = array_values( array_filter( $entries, function ( $entry ) use ( $calendar, $date ) { return ! ( att_mcp_quickcal_entry_is_mine( $entry, $calendar ) && $entry['start'] === $date ); } ) );
            $entries[] = array( 'calendar' => $calendar, 'start' => $date, 'end' => $end, 'closed' => $closed, 'slots' => $slots, 'details' => $details );
            $touch_entries = true;
        }
    }
    foreach ( array( 'block' => true, 'unblock' => false ) as $key => $on ) {
        if ( empty( $input[ $key ] ) ) {
            continue;
        }
        foreach ( array_values( (array) $input[ $key ] ) as $i => $spec ) {
            $where = sprintf( '%s[%d]', $key, $i );
            $date  = is_array( $spec ) && isset( $spec['date'] ) ? att_mcp_quickcal_date( $spec['date'] ) : '';
            if ( '' === $date ) {
                return new WP_Error( 'att_mcp_bad_input', $where . ' needs a "date" (YYYY-MM-DD) and "start" or "slot".' );
            }
            $slot = att_mcp_quickcal_resolve_slot( $spec, $calendar, $date, $entries ); // with this call's date changes
            if ( is_wp_error( $slot ) ) {
                return new WP_Error( $slot->get_error_code(), $where . ': ' . $slot->get_error_message() );
            }
            if ( $on ) {
                $blocked[ $calendar ][ $date ][ $slot['slot'] ] = true;
                if ( ! $slot['offered'] ) {
                    $notes[] = sprintf( '%s is not a slot on %s now; it stays blocked if it is added later.', $slot['slot'], $date );
                }
            } else {
                unset( $blocked[ $calendar ][ $date ][ $slot['slot'] ] );
                if ( empty( $blocked[ $calendar ][ $date ] ) ) {
                    unset( $blocked[ $calendar ][ $date ] );
                }
                if ( empty( $blocked[ $calendar ] ) ) {
                    unset( $blocked[ $calendar ] );
                }
            }
            $touch_blocked = true;
        }
    }
    if ( ! $touch_entries && ! $touch_blocked ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "dates", "remove_dates", "clear", "block" or "unblock".' );
    }

    $items = array();
    if ( $touch_entries ) {
        $items[] = att_mcp_capture( 'option', 'booked_custom_timeslots_encoded' );
        att_mcp_quickcal_save_date_entries( $entries );
    }
    if ( $touch_blocked ) {
        $items[] = att_mcp_capture( 'option', 'booked_disabled_timeslots' );
        update_option( 'booked_disabled_timeslots', $blocked );
    }
    return array(
        'calendar'      => att_mcp_quickcal_calendar_brief( $calendar ),
        'date_slots'    => att_mcp_quickcal_date_entries_out( att_mcp_quickcal_date_entries(), $calendar ),
        'blocked_slots' => att_mcp_quickcal_blocked_out( $calendar ),
        'change_id'     => att_mcp_record_change( $items, 'QuickCal dates and blocked slots (' . att_mcp_quickcal_calendar_brief( $calendar )['name'] . ')' ),
        'notes'         => $notes,
    );
}

function att_mcp_execute_quickcal_set_form_fields( $input ) {
    $calendar = att_mcp_quickcal_calendar_access( $input );
    if ( is_wp_error( $calendar ) ) {
        return $calendar;
    }
    $option = att_mcp_quickcal_fields_option( $calendar );
    if ( ! empty( $input['use_default'] ) ) {
        if ( ! $calendar ) {
            return new WP_Error( 'att_mcp_bad_input', '"use_default" is for other calendars: the default calendar\'s form is the default.' );
        }
        $item = att_mcp_capture( 'option', $option );
        delete_option( $option );
    } else {
        if ( ! isset( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
            return new WP_Error( 'att_mcp_bad_input', 'Pass "fields" (the whole form, in order), or "use_default": true.' );
        }
        if ( count( $input['fields'] ) > 50 ) {
            return new WP_Error( 'att_mcp_bad_input', 'A form can have at most 50 fields.' );
        }
        $current = att_mcp_quickcal_fields_out( att_mcp_quickcal_fields_raw( $calendar ) );
        if ( $current['unsupported'] && empty( $input['force'] ) ) {
            return new WP_Error( 'att_mcp_unsupported_fields', sprintf( 'This form has fields this tool cannot recreate (%s, e.g. WooCommerce paid services). Edit it in QuickCal, or pass "force": true to replace them.', implode( ', ', $current['unsupported'] ) ) );
        }
        $raw = att_mcp_quickcal_fields_build( $input['fields'] );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }
        $item = att_mcp_capture( 'option', $option );
        if ( $raw ) {
            // Stored slashed, as QuickCal's form builder posts it (its readers stripslashes() it).
            update_option( $option, wp_slash( wp_json_encode( $raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
        } else {
            delete_option( $option ); // an empty form, as QuickCal saves one
        }
    }
    $form = att_mcp_quickcal_fields_out( att_mcp_quickcal_fields_raw( $calendar, true ) );
    return array(
        'calendar'  => att_mcp_quickcal_calendar_brief( $calendar ),
        'uses'      => ( $calendar && ! att_mcp_quickcal_fields_raw( $calendar ) ) ? 'default calendar' : 'own',
        'fields'    => $form['fields'],
        'change_id' => att_mcp_record_change( array( $item ), 'QuickCal booking form (' . att_mcp_quickcal_calendar_brief( $calendar )['name'] . ')' ),
        'note'      => 'QuickCal asks for the customer\'s name and email itself; these fields come after them.',
    );
}

/* ----- Execute: settings and emails ------------------------------------------------------------ */

function att_mcp_execute_quickcal_update_settings( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $map    = att_mcp_quickcal_settings_map();
    $writes = array();
    foreach ( (array) $input as $key => $value ) {
        if ( ! isset( $map[ $key ] ) ) {
            return new WP_Error( 'att_mcp_bad_input', sprintf( 'Unknown setting "%s". Settings: %s.', $key, implode( ', ', array_keys( $map ) ) ) );
        }
        $stored = att_mcp_quickcal_setting_value( $key, $map[ $key ], $value );
        if ( is_wp_error( $stored ) ) {
            return $stored;
        }
        $writes[ $key ] = $stored;
    }
    if ( ! $writes ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass the settings to change; att/quickcal-get-setup lists them.' );
    }
    $items = array();
    foreach ( $writes as $key => $stored ) {
        $items[] = att_mcp_capture( 'option', $map[ $key ]['option'] );
    }
    $change_id = att_mcp_record_change( $items, 'QuickCal settings: ' . implode( ', ', array_keys( $writes ) ) );
    $now       = array();
    foreach ( $writes as $key => $stored ) {
        update_option( $map[ $key ]['option'], $stored );
        $now[ $key ] = att_mcp_quickcal_setting_get( $map[ $key ] );
    }
    return array( 'updated' => $now, 'change_id' => $change_id );
}

function att_mcp_execute_quickcal_update_emails( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $map    = att_mcp_quickcal_emails_map();
    $writes = array();
    foreach ( isset( $input['emails'] ) && is_array( $input['emails'] ) ? $input['emails'] : array() as $key => $email ) {
        if ( ! isset( $map[ $key ] ) || ! is_array( $email ) ) {
            return new WP_Error( 'att_mcp_bad_input', sprintf( 'Unknown email "%s". Emails: %s.', $key, implode( ', ', array_keys( $map ) ) ) );
        }
        list( $subject_option, $content_option ) = $map[ $key ];
        if ( isset( $email['subject'] ) ) {
            $writes[ $subject_option ] = sanitize_text_field( (string) $email['subject'] );
        }
        if ( isset( $email['content'] ) ) {
            $writes[ $content_option ] = att_mcp_prepare_content( (string) $email['content'] );
        }
        if ( isset( $email['enabled'] ) && ! $email['enabled'] ) {
            $writes[ $subject_option ] = '';
        } elseif ( ! empty( $email['enabled'] ) ) {
            $subject = isset( $writes[ $subject_option ] ) ? $writes[ $subject_option ] : (string) get_option( $subject_option, '' );
            $content = isset( $writes[ $content_option ] ) ? $writes[ $content_option ] : (string) get_option( $content_option, '' );
            if ( '' === $subject || '' === trim( $content ) ) {
                return new WP_Error( 'att_mcp_bad_input', sprintf( '"%s" is only sent with both a subject and content: pass them.', $key ) );
            }
        }
    }
    if ( ! $writes ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "emails", e.g. {"customer_confirmation": {"subject": "…", "content": "…"}}.' );
    }
    $items = array();
    foreach ( array_keys( $writes ) as $option ) {
        $items[] = att_mcp_capture( 'option', $option );
    }
    $change_id = att_mcp_record_change( $items, 'QuickCal emails' );
    foreach ( $writes as $option => $value ) {
        update_option( $option, $value );
    }
    $out = array();
    foreach ( att_mcp_quickcal_emails_out() as $key => $email ) {
        $out[ $key ] = $email['active'];
    }
    return array( 'active' => $out, 'change_id' => $change_id );
}

/* ----- Execute: appointments ---------------------------------------------------------------------- */

function att_mcp_execute_quickcal_save_appointment( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    return empty( $input['id'] ) ? att_mcp_quickcal_book( $input ) : att_mcp_quickcal_change( $input );
}

/** A new appointment, the way QuickCal's admin screen creates one. */
function att_mcp_quickcal_book( $input ) {
    $calendar = att_mcp_quickcal_calendar_access( $input );
    if ( is_wp_error( $calendar ) ) {
        return $calendar;
    }
    $date = isset( $input['date'] ) ? att_mcp_quickcal_date( $input['date'] ) : '';
    if ( '' === $date ) {
        return new WP_Error( 'att_mcp_bad_input', '"date" must be a date (YYYY-MM-DD).' );
    }
    $slot = att_mcp_quickcal_resolve_slot( $input, $calendar, $date );
    if ( is_wp_error( $slot ) ) {
        return $slot;
    }
    if ( empty( $input['ignore_availability'] ) ) {
        $bookable = att_mcp_quickcal_check_bookable( $calendar, $date, $slot );
        if ( is_wp_error( $bookable ) ) {
            return $bookable;
        }
    }
    $customer = att_mcp_quickcal_customer_in( $input );
    if ( is_wp_error( $customer ) ) {
        return $customer;
    }
    $status = isset( $input['status'] ) ? (string) $input['status'] : 'approved';
    if ( ! in_array( $status, array( 'approved', 'pending' ), true ) ) {
        return new WP_Error( 'att_mcp_bad_input', '"status" must be approved or pending.' );
    }
    list( $answers_html, $answers ) = att_mcp_quickcal_answers_in( isset( $input['fields'] ) ? $input['fields'] : array() );
    $ts   = att_mcp_quickcal_ts( $date, $slot['slot'] );
    $data = array(
        'post_type'    => 'booked_appointments',
        'post_title'   => date_i18n( get_option( 'date_format' ), $ts ) . ' @ ' . date_i18n( get_option( 'time_format' ), $ts ) . ' (User: ' . ( $customer['user_id'] ? $customer['user_id'] : 'Guest' ) . ')',
        'post_content' => '',
        'post_status'  => 'approved' === $status ? 'publish' : 'draft',
        'post_date'    => gmdate( 'Y-m-01 00:00:00', $ts ), // QuickCal dates appointment posts to the 1st of their month
    );
    if ( $customer['user_id'] ) {
        $data['post_author'] = $customer['user_id'];
    }
    $id = wp_insert_post( wp_slash( $data ), true );
    if ( is_wp_error( $id ) ) {
        return $id;
    }
    $meta = array(
        '_appointment_title'     => isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : sanitize_text_field( $slot['title'] ),
        '_appointment_timestamp' => $ts,
        '_appointment_timeslot'  => $slot['slot'],
    );
    if ( $customer['user_id'] ) {
        $meta['_appointment_user'] = $customer['user_id'];
    } else {
        $meta['_appointment_guest_name']    = $customer['name'];
        $meta['_appointment_guest_surname'] = $customer['surname'];
        $meta['_appointment_guest_email']   = $customer['email'];
    }
    $meta['_cf_meta_value'] = $answers_html;
    foreach ( $meta as $key => $value ) {
        update_post_meta( $id, $key, wp_slash( $value ) );
    }
    if ( 'approved' === $status ) {
        wp_publish_post( $id ); // "publish" even when the post date is in the future, as QuickCal does
    }
    if ( $calendar ) {
        wp_set_object_terms( $id, array( $calendar ), 'booked_custom_calendars' );
    }
    $change_id = att_mcp_record_change( array( array( 'type' => 'quickcal_appointment', 'target' => $id, 'existed' => false, 'value' => null ) ), 'QuickCal appointment #' . $id . ' booked' );

    $emailed = array();
    if ( ! isset( $input['notify'] ) || ! empty( $input['notify'] ) ) {
        if ( 'approved' === $status ? att_mcp_quickcal_email( 'approved', $id ) : att_mcp_quickcal_email( 'confirmation', $id, $calendar ) ) {
            $emailed[] = 'approved' === $status ? 'customer_approval' : 'customer_confirmation';
        }
    }
    do_action( 'booked_new_appointment_created', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- QuickCal's own hook (calendar feeds, add-ons), fired as its booking handlers do.

    $notes = att_mcp_quickcal_answer_notes( $calendar, $answers );
    if ( ! $emailed && ( ! isset( $input['notify'] ) || ! empty( $input['notify'] ) ) ) {
        $notes[] = 'No email was sent: the customer has no email address, or the ' . ( 'approved' === $status ? 'customer_approval' : 'customer_confirmation' ) . ' email is off (att/quickcal-update-emails).';
    }
    return array(
        'appointment' => att_mcp_quickcal_appointment_out( get_post( $id ) ),
        'emailed'     => $emailed,
        'change_id'   => $change_id,
        'notes'       => $notes,
    );
}

/** Reschedule, edit or approve an appointment. */
function att_mcp_quickcal_change( $input ) {
    $id   = (int) $input['id'];
    $post = get_post( $id );
    if ( ! $post || 'booked_appointments' !== $post->post_type ) {
        return new WP_Error( 'att_mcp_not_found', 'No QuickCal appointment with that id.' );
    }
    if ( ! att_mcp_quickcal_can_appointment( $id ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You cannot manage this appointment.' );
    }
    $calendar = att_mcp_quickcal_appointment_calendar( $id );
    if ( isset( $input['calendar'] ) && (int) $input['calendar'] !== $calendar ) {
        return new WP_Error( 'att_mcp_bad_input', 'An appointment cannot move to another calendar: cancel it and book a new one.' );
    }
    if ( ! empty( $input['user_id'] ) ) {
        return new WP_Error( 'att_mcp_bad_input', 'The customer of an appointment cannot be changed: cancel it and book a new one.' );
    }
    $owner   = function_exists( 'quickcal_get_appointment_owner_id' ) ? (int) quickcal_get_appointment_owner_id( $id ) : (int) get_post_meta( $id, '_appointment_user', true );
    $before  = att_mcp_capture_quickcal_appointment( $id );
    $updates = array();
    $changed = array();
    $notes   = array();

    if ( isset( $input['date'] ) || isset( $input['start'] ) || isset( $input['slot'] ) ) {
        $current_ts   = (int) get_post_meta( $id, '_appointment_timestamp', true );
        $current_slot = (string) get_post_meta( $id, '_appointment_timeslot', true );
        $date         = isset( $input['date'] ) ? att_mcp_quickcal_date( $input['date'] ) : gmdate( 'Y-m-d', $current_ts );
        if ( '' === $date ) {
            return new WP_Error( 'att_mcp_bad_input', '"date" must be a date (YYYY-MM-DD).' );
        }
        $which = $input;
        if ( ! isset( $input['start'] ) && ! isset( $input['slot'] ) ) {
            $which['slot'] = $current_slot; // a new date, same time
        }
        $slot = att_mcp_quickcal_resolve_slot( $which, $calendar, $date );
        if ( is_wp_error( $slot ) ) {
            return $slot;
        }
        $ts = att_mcp_quickcal_ts( $date, $slot['slot'] );
        if ( ( $ts !== $current_ts || $slot['slot'] !== $current_slot ) && empty( $input['ignore_availability'] ) ) {
            $bookable = att_mcp_quickcal_check_bookable( $calendar, $date, $slot, $id );
            if ( is_wp_error( $bookable ) ) {
                return $bookable;
            }
        }
        $updates['_appointment_timestamp'] = $ts;
        $updates['_appointment_timeslot']  = $slot['slot'];
        $updates['_appointment_title']     = sanitize_text_field( $slot['title'] ); // QuickCal's edit takes the new slot's title
        $changed[]                         = 'time';
    }
    if ( isset( $input['title'] ) ) {
        $updates['_appointment_title'] = sanitize_text_field( (string) $input['title'] );
        $changed[]                     = 'title';
    }
    if ( isset( $input['name'] ) || isset( $input['surname'] ) || isset( $input['email'] ) ) {
        if ( $owner ) {
            return new WP_Error( 'att_mcp_bad_input', sprintf( 'This appointment belongs to user #%d: change their name or email in their profile instead.', $owner ) );
        }
        $guest = att_mcp_quickcal_guest_in( $input, array(
            'name'    => (string) get_post_meta( $id, '_appointment_guest_name', true ),
            'surname' => (string) get_post_meta( $id, '_appointment_guest_surname', true ),
            'email'   => (string) get_post_meta( $id, '_appointment_guest_email', true ),
        ) );
        if ( is_wp_error( $guest ) ) {
            return $guest;
        }
        if ( '' === $guest['name'] ) {
            return new WP_Error( 'att_mcp_bad_input', 'A guest appointment needs a "name".' );
        }
        $updates['_appointment_guest_name']    = $guest['name'];
        $updates['_appointment_guest_surname'] = $guest['surname'];
        $updates['_appointment_guest_email']   = $guest['email'];
        $changed[]                             = 'customer';
    }
    if ( isset( $input['fields'] ) ) {
        list( $answers_html, $answers ) = att_mcp_quickcal_answers_in( $input['fields'] );
        $updates['_cf_meta_value']      = $answers_html;
        $changed[]                      = 'answers';
        $notes                          = att_mcp_quickcal_answer_notes( $calendar, $answers );
    }
    $approve = false;
    if ( isset( $input['status'] ) ) {
        $approved = in_array( $post->post_status, array( 'publish', 'future' ), true );
        if ( 'approved' === $input['status'] ) {
            $approve = ! $approved;
        } elseif ( $approved ) {
            return new WP_Error( 'att_mcp_bad_input', 'QuickCal cannot make an approved appointment pending again.' );
        }
    }
    if ( ! $updates && ! $approve ) {
        return new WP_Error( 'att_mcp_bad_input', 'Nothing to change: pass date/start/slot, title, fields, the guest\'s name/surname/email, or "status": "approved".' );
    }

    foreach ( $updates as $key => $value ) {
        update_post_meta( $id, $key, wp_slash( $value ) );
    }
    $emailed = array();
    if ( $approve ) {
        // As QuickCal's Approve button: email, publish, then its hook.
        if ( ( ! isset( $input['notify'] ) || ! empty( $input['notify'] ) ) && att_mcp_quickcal_email( 'approved', $id ) ) {
            $emailed[] = 'customer_approval';
        }
        wp_publish_post( $id );
        do_action( 'booked_appointment_approved', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- QuickCal's own hook, fired as its Approve button does.
        $changed[] = 'approved';
    }
    return array(
        'appointment' => att_mcp_quickcal_appointment_out( get_post( $id ) ),
        'changed'     => $changed,
        'emailed'     => $emailed,
        'change_id'   => att_mcp_record_change( array( $before ), 'QuickCal appointment #' . $id . ': ' . implode( ', ', $changed ) ),
        'notes'       => $notes,
    );
}

function att_mcp_execute_quickcal_cancel_appointments( $input ) {
    $ready = att_mcp_quickcal_ready();
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $ids = ( isset( $input['ids'] ) && is_array( $input['ids'] ) ) ? array_values( array_unique( array_filter( array_map( 'intval', $input['ids'] ) ) ) ) : array();
    if ( ! $ids ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "ids" (from att/quickcal-get-appointments).' );
    }
    if ( count( $ids ) > 50 ) {
        return new WP_Error( 'att_mcp_bad_input', 'At most 50 appointments per call.' );
    }
    $found     = array();
    $not_found = array();
    foreach ( $ids as $id ) {
        if ( 'booked_appointments' !== get_post_type( $id ) ) {
            $not_found[] = $id;
        } elseif ( ! att_mcp_quickcal_can_appointment( $id ) ) {
            return new WP_Error( 'att_mcp_forbidden', sprintf( 'You cannot manage appointment #%d; nothing was cancelled.', $id ) );
        } else {
            $found[] = $id;
        }
    }
    if ( ! $found ) {
        return new WP_Error( 'att_mcp_not_found', 'None of those appointments exists.' );
    }
    $items = array();
    foreach ( $found as $id ) {
        $items[] = att_mcp_capture_quickcal_appointment( $id );
    }
    $change_id = att_mcp_record_change( $items, 'QuickCal appointments cancelled: #' . implode( ', #', $found ) );
    $notify    = ! isset( $input['notify'] ) || ! empty( $input['notify'] );
    $now       = att_mcp_quickcal_now();
    $emailed   = array();
    foreach ( $found as $id ) {
        // As QuickCal's admin Delete: the customer of an upcoming appointment is told, then it is deleted.
        if ( $notify && (int) get_post_meta( $id, '_appointment_timestamp', true ) >= $now && att_mcp_quickcal_email( 'cancellation', $id ) ) {
            $emailed[] = $id;
        }
        do_action( 'booked_appointment_cancelled', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- QuickCal's own hook, fired as its admin screen does.
        wp_delete_post( $id, true );
    }
    return array(
        'cancelled' => $found,
        'emailed'   => $emailed,
        'not_found' => $not_found,
        'change_id' => $change_id,
    );
}
