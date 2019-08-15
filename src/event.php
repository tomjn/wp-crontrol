<?php

namespace Crontrol\Event;

/**
 * Executes a cron event immediately.
 *
 * Executes an event by scheduling a new single event with the same arguments.
 *
 * @param string $hookname The hook name of the cron event to run.
 * @param string $sig      The cron event signature.
 * @return bool Whether the execution was successful or not.
 */
function run( $hookname, $sig ) {
	$crons = _get_cron_array();
	foreach ( $crons as $time => $cron ) {
		if ( isset( $cron[ $hookname ][ $sig ] ) ) {
			$args = $cron[ $hookname ][ $sig ]['args'];
			delete_transient( 'doing_cron' );
			wp_schedule_single_event( time() - 1, $hookname, $args );
			spawn_cron();
			return true;
		}
	}
	return false;
}

/**
 * Adds a new cron event.
 *
 * @param string $next_run A GMT time that the event should be run at.
 * @param string $schedule The recurrence of the cron event.
 * @param string $hookname The name of the hook to execute.
 * @param array  $args     Arguments to add to the cron event.
 * @return bool Whether the additon was successful.
 */
function add( $next_run, $schedule, $hookname, $args ) {
	$next_run = strtotime( $next_run );
	if ( false === $next_run ) {
		$next_run = time();
	} else {
		$next_run = get_gmt_from_date( date( 'Y-m-d H:i:s', $next_run ), 'U' );
	}
	if ( ! is_array( $args ) ) {
		$args = array();
	}
	if ( '_oneoff' === $schedule ) {
		return wp_schedule_single_event( $next_run, $hookname, $args ) !== false;
	} else {
		return wp_schedule_event( $next_run, $schedule, $hookname, $args ) !== false;
	}
}

/**
 * Deletes a cron event.
 *
 * @param string $to_delete The hook name of the event to delete.
 * @param string $sig       The cron event signature.
 * @param string $next_run  The GMT time that the event would be run at.
 * @return bool Whether the deletion was successful.
 */
function delete( $to_delete, $sig, $next_run ) {
	$crons = _get_cron_array();
	if ( isset( $crons[ $next_run ][ $to_delete ][ $sig ] ) ) {
		$args = $crons[ $next_run ][ $to_delete ][ $sig ]['args'];
		wp_unschedule_event( $next_run, $to_delete, $args );
		return true;
	}
	return false;
}

/**
 * Returns a flattened array of cron events.
 *
 * @return array[] An array of cron event arrays.
 */
function get() {
	$crons  = _get_cron_array();
	$events = array();

	if ( empty( $crons ) ) {
		return array();
	}

	foreach ( $crons as $time => $cron ) {
		foreach ( $cron as $hook => $dings ) {
			foreach ( $dings as $sig => $data ) {

				// This is a prime candidate for a Crontrol_Event class but I'm not bothering currently.
				$events[ "$hook-$sig-$time" ] = (object) array(
					'hook'     => $hook,
					'time'     => $time,
					'sig'      => $sig,
					'args'     => $data['args'],
					'schedule' => $data['schedule'],
					'interval' => isset( $data['interval'] ) ? $data['interval'] : null,
				);

			}
		}
	}

	return $events;
}