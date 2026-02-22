<?php
/**
 * Dashboard admin view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$accommodation_count   = wp_count_posts( 'accommodation' )->publish;
$cleaning_service_count = wp_count_posts( 'cleaning_service' )->publish;
$lease_count           = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_leases" );
$guest_count           = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_guests" );
$pending_cleaning      = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_cleaning_requests WHERE status = 'pending'" );
$unread_contacts       = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}af_owner_contacts WHERE status = 'unread'" );
?>
<div class="wrap af-dashboard">
	<h1><?php esc_html_e( 'Arriendo Fácil – Dashboard', 'arriendo-facil' ); ?></h1>

	<div class="af-stats">
		<div class="af-stat-card">
			<span class="af-stat-number"><?php echo esc_html( $accommodation_count ); ?></span>
			<span class="af-stat-label"><?php esc_html_e( 'Accommodations', 'arriendo-facil' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=accommodation' ) ); ?>"><?php esc_html_e( 'View all', 'arriendo-facil' ); ?></a>
		</div>

		<div class="af-stat-card">
			<span class="af-stat-number"><?php echo esc_html( $lease_count ); ?></span>
			<span class="af-stat-label"><?php esc_html_e( 'Leases', 'arriendo-facil' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>"><?php esc_html_e( 'View all', 'arriendo-facil' ); ?></a>
		</div>

		<div class="af-stat-card">
			<span class="af-stat-number"><?php echo esc_html( $pending_cleaning ); ?></span>
			<span class="af-stat-label"><?php esc_html_e( 'Pending Cleanings', 'arriendo-facil' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-cleaning-requests' ) ); ?>"><?php esc_html_e( 'View all', 'arriendo-facil' ); ?></a>
		</div>

		<div class="af-stat-card">
			<span class="af-stat-number"><?php echo esc_html( $unread_contacts ); ?></span>
			<span class="af-stat-label"><?php esc_html_e( 'Unread Contacts', 'arriendo-facil' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts' ) ); ?>"><?php esc_html_e( 'View all', 'arriendo-facil' ); ?></a>
		</div>

		<div class="af-stat-card">
			<span class="af-stat-number"><?php echo esc_html( $guest_count ); ?></span>
			<span class="af-stat-label"><?php esc_html_e( 'Guests', 'arriendo-facil' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-guests' ) ); ?>"><?php esc_html_e( 'View all', 'arriendo-facil' ); ?></a>
		</div>
	</div>

	<div class="af-quick-actions">
		<h2><?php esc_html_e( 'Quick Actions', 'arriendo-facil' ); ?></h2>
		<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=accommodation' ) ); ?>" class="button button-primary">
			<?php esc_html_e( '+ New Accommodation', 'arriendo-facil' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-leases' ) ); ?>" class="button">
			<?php esc_html_e( 'Manage Leases', 'arriendo-facil' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-cleaning-requests' ) ); ?>" class="button">
			<?php esc_html_e( 'Cleaning Requests', 'arriendo-facil' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-ai-settings' ) ); ?>" class="button">
			<?php esc_html_e( 'AI Settings', 'arriendo-facil' ); ?>
		</a>
	</div>
</div>
