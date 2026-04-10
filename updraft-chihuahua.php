<?php
/**
 * Plugin Name:        Chihuahua for UpdraftPlus
 * Plugin URI:         https://github.com/littleroomstudio/updraft-chihuahua
 * GitHub Plugin URI:  https://github.com/littleroomstudio/updraft-chihuahua
 * Description:        Sends a lil bark (email) if your nightly UpdraftPlus backups haven't run.
 * Version:            1.0.0
 * Author:             Little Room
 * Author URI:         https://littleroom.studio
 * License:            GPL-3.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins:   updraftplus
 * Requires PHP:       8.3
 * Requires at least:  6.8
 * Text Domain:        updraft-chihuahua
 *
 * @package UpdraftChihuahua
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -------------------------------------------------------------------------
// Configuration -- edit these or override via wp-config.php / a must-use plugin
// -------------------------------------------------------------------------

/** Email address that receives missed-backup alerts. */
if ( ! defined( 'UPDRAFT_CHIHUAHUA_POC_EMAIL' ) ) {
	define( 'UPDRAFT_CHIHUAHUA_POC_EMAIL', defined( 'UPDRAFT_CHIHUAHUA_EMAIL' ) ? UPDRAFT_CHIHUAHUA_EMAIL : get_option( 'admin_email' ) );
}

/**
 * How many seconds old the last backup timestamp can be before an alert fires.
 * Default: 30 hours. Gives UpdraftPlus a six-hour window past the 24-hour mark.
 */
if ( ! defined( 'UPDRAFT_CHIHUAHUA_MAX_AGE' ) ) {
	define( 'UPDRAFT_CHIHUAHUA_MAX_AGE', defined( 'UPDRAFT_CHIHUAHUA_THRESHOLD' ) ? UPDRAFT_CHIHUAHUA_THRESHOLD : ( 30 * HOUR_IN_SECONDS ) );
}

/** Option key used by UpdraftPlus to store the last backup time. */
if ( ! defined( 'UPDRAFT_CHIHUAHUA_OPTION' ) ) {
	define( 'UPDRAFT_CHIHUAHUA_OPTION', 'updraft_last_backup' );
}

/** Cron hook name. */
if ( ! defined( 'UPDRAFT_CHIHUAHUA_CRON_HOOK' ) ) {
	define( 'UPDRAFT_CHIHUAHUA_CRON_HOOK', 'updraft_chihuahua_check' );
}

// -------------------------------------------------------------------------
// Activation / deactivation
// -------------------------------------------------------------------------

register_activation_hook( __FILE__, 'updraft_chihuahua_activate' );
register_deactivation_hook( __FILE__, 'updraft_chihuahua_deactivate' );

/**
 * Activates the plugin and schedules the daily cron event.
 *
 * Anchors the check to run 6 hours after UpdraftPlus backup schedules,
 * or defaults to 06:00 site time if UpdraftPlus is not yet scheduled.
 *
 * @return void
 */
function updraft_chihuahua_activate(): void {
	if ( wp_next_scheduled( UPDRAFT_CHIHUAHUA_CRON_HOOK ) ) {
		return;
	}

	// Use the later of the two UpdraftPlus backup hooks as the anchor,
	// then add six hours so the check runs after both are expected to finish.
	$updraft_files = wp_next_scheduled( 'updraft_backup' ) ?: 0;
	$updraft_db    = wp_next_scheduled( 'updraft_backup_database' ) ?: 0;
	$anchor        = max( $updraft_files, $updraft_db );

	if ( $anchor > 0 ) {
		$first_run = $anchor + ( 6 * HOUR_IN_SECONDS );
	} else {
		// UpdraftPlus is not scheduled yet. Fall back to tomorrow at 06:00 site time.
		$first_run = strtotime( 'tomorrow 06:00', current_datetime()->getTimestamp() );
	}

	wp_schedule_event( $first_run, 'daily', UPDRAFT_CHIHUAHUA_CRON_HOOK );
}

/**
 * Deactivates the plugin and unschedules the cron event.
 *
 * @return void
 */
function updraft_chihuahua_deactivate(): void {
	$timestamp = wp_next_scheduled( UPDRAFT_CHIHUAHUA_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, UPDRAFT_CHIHUAHUA_CRON_HOOK );
	}
}

// -------------------------------------------------------------------------
// Core check
// -------------------------------------------------------------------------

add_action( UPDRAFT_CHIHUAHUA_CRON_HOOK, 'updraft_chihuahua_run_check' );

/**
 * Runs the backup freshness check and sends an alert if needed.
 *
 * Retrieves the last backup timestamp from UpdraftPlus options and
 * compares it against the configured threshold. Sends an email alert
 * if the backup is stale or missing.
 *
 * @return void
 */
function updraft_chihuahua_run_check(): void {
	$last_backup = get_option( UPDRAFT_CHIHUAHUA_OPTION, false );

	/*
	 * UpdraftPlus stores this as an array with a 'backup_time' key (Unix timestamp).
	 * Fall back to 'nonincremental_backup_time' if present (used in some versions),
	 * then plain int for older installs or edge cases.
	 */
	if ( is_array( $last_backup ) ) {
		$last_backup = (int) ( $last_backup['backup_time'] ?? $last_backup['nonincremental_backup_time'] ?? 0 );
	} else {
		$last_backup = (int) $last_backup;
	}

	$now = time();
	$age = $now - $last_backup;

	if ( 0 === $last_backup || $age > UPDRAFT_CHIHUAHUA_MAX_AGE ) {
		$last_backup_human = $last_backup > 0
			? gmdate( 'Y-m-d H:i:s', $last_backup ) . ' UTC'
			: 'never (no record found)';

		$threshold_human = human_time_diff( $now - UPDRAFT_CHIHUAHUA_MAX_AGE, $now );

		$body = sprintf(
			"The nightly UpdraftPlus backup on %s (%s) has not completed within the expected window.\n\n" .
			"Last recorded backup: %s\n" .
			"Alert threshold:      %s\n" .
			"Time of this check:   %s UTC\n\n" .
			"Log in to review backup status: %s",
			get_bloginfo( 'name' ),
			home_url(),
			$last_backup_human,
			$threshold_human,
			gmdate( 'Y-m-d H:i:s', $now ),
			admin_url( 'options-general.php?page=updraftplus' )
		);

		updraft_chihuahua_send_alert( 'Missed backup alert: ' . get_bloginfo( 'name' ), $body );
	}
}

// -------------------------------------------------------------------------
// Mailer
// -------------------------------------------------------------------------

/**
 * Sends a backup alert email.
 *
 * @param string $subject The email subject line.
 * @param string $body    The email body content.
 * @return void
 */
function updraft_chihuahua_send_alert( string $subject, string $body ): void {
	$to      = UPDRAFT_CHIHUAHUA_POC_EMAIL;
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	$sent = wp_mail( $to, $subject, $body, $headers );

	if ( ! $sent ) {
		error_log( sprintf( '[Updraft Chihuahua] Failed to send alert email to %s', $to ) );
	}
}

// -------------------------------------------------------------------------
// Optional: surface last-check info in the admin for manual verification
// -------------------------------------------------------------------------

add_action( 'admin_notices', 'updraft_chihuahua_admin_notice' );

/**
 * Displays an admin notice on the UpdraftPlus settings page.
 *
 * Shows the next scheduled check time and alert recipient, or a warning
 * if the cron event is not scheduled.
 *
 * @return void
 */
function updraft_chihuahua_admin_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'settings_page_updraftplus' !== $screen->id ) {
		return;
	}

	$next = wp_next_scheduled( UPDRAFT_CHIHUAHUA_CRON_HOOK );
	if ( ! $next ) {
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Updraft Chihuahua:', 'updraft-chihuahua' ),
			esc_html__( 'The cron event is not scheduled. Try deactivating and reactivating the plugin.', 'updraft-chihuahua' )
		);
		return;
	}

	$next_human = human_time_diff( time(), $next );
	printf(
		'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s <strong>%s</strong>.</p></div>',
		esc_html__( 'Updraft Chihuahua:', 'updraft-chihuahua' ),
		sprintf(
			/* translators: %s: human-readable time until next check */
			esc_html__( 'Next check runs in %s. Alerts go to', 'updraft-chihuahua' ),
			esc_html( $next_human )
		),
		esc_html( UPDRAFT_CHIHUAHUA_POC_EMAIL )
	);
}
