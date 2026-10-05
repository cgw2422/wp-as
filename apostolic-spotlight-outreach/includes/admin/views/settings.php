<?php
/**
 * Settings (administrators only – aso_manage_settings).
 *
 * @package ApostolicSpotlight\Outreach
 */

use ApostolicSpotlight\Outreach\Admin\Admin;
use ApostolicSpotlight\Outreach\Booking\Availability;
use ApostolicSpotlight\Outreach\Church\Church_Repository;
use ApostolicSpotlight\Outreach\Church\Field_Map;
use ApostolicSpotlight\Outreach\Cron;
use ApostolicSpotlight\Outreach\Google\Google_Oauth;
use ApostolicSpotlight\Outreach\Reply_Detector;
use ApostolicSpotlight\Outreach\Settings;
use ApostolicSpotlight\Outreach\Util;

defined( 'ABSPATH' ) || exit;

$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'google'; // phpcs:ignore WordPress.Security.NonceVerification
$tabs = array(
	'google'      => __( 'Google Workspace', 'apostolic-spotlight-outreach' ),
	'church'      => __( 'Church Directory', 'apostolic-spotlight-outreach' ),
	'sending'     => __( 'Sending', 'apostolic-spotlight-outreach' ),
	'booking'     => __( 'Booking', 'apostolic-spotlight-outreach' ),
	'replies'     => __( 'Reply Detection', 'apostolic-spotlight-outreach' ),
	'permissions' => __( 'Permissions', 'apostolic-spotlight-outreach' ),
);
if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'google';
}
Admin::header( __( 'Settings', 'apostolic-spotlight-outreach' ) );

echo '<nav class="aso-tabs">';
foreach ( $tabs as $k => $label ) {
	printf( '<a class="aso-tab %s" href="%s">%s</a>', esc_attr( $k === $tab ? 'is-active' : '' ), esc_url( Util::admin_url( 'aso-settings', array( 'tab' => $k ) ) ), esc_html( $label ) );
}
echo '</nav>';

$form_open = static function () use ( $tab ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aso-card aso-settings">';
	echo '<input type="hidden" name="action" value="aso_settings_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
	wp_nonce_field( 'aso_settings_save' );
};
$form_close = static function () {
	echo '<p><button class="button button-primary button-large">' . esc_html__( 'Save settings', 'apostolic-spotlight-outreach' ) . '</button></p></form>';
};
$tz_select = static function ( $name, $value ) {
	echo '<select name="' . esc_attr( $name ) . '">' . wp_timezone_choice( $value, get_user_locale() ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated options.
};

/* ======================================================================
 * Google
 * ==================================================================== */
if ( 'google' === $tab ) :
	$account   = Google_Oauth::account();
	$connected = Google_Oauth::is_connected();
	$scope_labels = array(
		'openid'                     => __( 'Identify the connected account', 'apostolic-spotlight-outreach' ),
		'email'                      => __( 'See the account email address', 'apostolic-spotlight-outreach' ),
		'https://www.googleapis.com/auth/userinfo.email' => __( 'See the account email address', 'apostolic-spotlight-outreach' ),
		Google_Oauth::SCOPE_SEND     => __( 'Gmail: send email only (cannot read your mailbox)', 'apostolic-spotlight-outreach' ),
		Google_Oauth::SCOPE_EVENTS   => __( 'Calendar: create & manage interview events (with Google Meet)', 'apostolic-spotlight-outreach' ),
		Google_Oauth::SCOPE_FREEBUSY => __( 'Calendar: see free/busy times only (no event details)', 'apostolic-spotlight-outreach' ),
		Google_Oauth::SCOPE_METADATA => __( 'Gmail: read message headers only, for reply detection (optional)', 'apostolic-spotlight-outreach' ),
	);
	?>
	<div class="aso-columns">
		<div class="aso-columns__main">
			<div class="aso-card">
				<div class="aso-card__head"><h3><?php esc_html_e( 'Connection', 'apostolic-spotlight-outreach' ); ?></h3>
					<?php echo $connected ? '<span class="aso-badge aso-badge--green">' . esc_html__( 'Connected', 'apostolic-spotlight-outreach' ) . '</span>' : '<span class="aso-badge aso-badge--gray">' . esc_html__( 'Not connected', 'apostolic-spotlight-outreach' ) . '</span>'; ?>
				</div>
				<?php if ( $connected ) : ?>
					<p class="aso-big"><span class="dashicons dashicons-google"></span> <strong><?php echo esc_html( $account['email'] ?? '' ); ?></strong></p>
					<p class="aso-muted">
						<?php
						if ( ! empty( $account['connected_at'] ) ) {
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Connected %s', 'apostolic-spotlight-outreach' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $account['connected_at'] ) ) );
						}
						?>
					</p>
					<h4><?php esc_html_e( 'Granted permissions', 'apostolic-spotlight-outreach' ); ?></h4>
					<ul class="aso-list aso-list--compact">
						<?php foreach ( Google_Oauth::granted_scopes() as $s ) : ?>
							<li>✓ <?php echo esc_html( $scope_labels[ $s ] ?? $s ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p>
						<a class="button" href="<?php echo esc_url( Google_Oauth::connect_url() ); ?>"><?php esc_html_e( 'Reconnect', 'apostolic-spotlight-outreach' ); ?></a>
						<a class="button aso-button-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aso_google_disconnect' ), 'aso_google_disconnect' ) ); ?>" data-aso-confirm="<?php esc_attr_e( 'Disconnect Google? Sending stops and the booking page will show no times until you reconnect.', 'apostolic-spotlight-outreach' ); ?>"><?php esc_html_e( 'Disconnect', 'apostolic-spotlight-outreach' ); ?></a>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Connect the Google Workspace account you send outreach from. Emails will be sent from that account and appear in its Gmail Sent folder; interviews are created on its calendar with Google Meet.', 'apostolic-spotlight-outreach' ); ?></p>
					<p>
						<?php if ( Google_Oauth::has_credentials() ) : ?>
							<a class="button button-primary button-hero" href="<?php echo esc_url( Google_Oauth::connect_url() ); ?>"><?php esc_html_e( 'Connect Google Workspace', 'apostolic-spotlight-outreach' ); ?></a>
						<?php else : ?>
							<em><?php esc_html_e( 'Save your OAuth Client ID and Secret below first.', 'apostolic-spotlight-outreach' ); ?></em>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>

			<?php $form_open(); ?>
				<div class="aso-card__head"><h3><?php esc_html_e( 'OAuth client & sender', 'apostolic-spotlight-outreach' ); ?></h3></div>
				<label class="aso-field"><span><?php esc_html_e( 'Sender name (shown in From and {{sender_name}})', 'apostolic-spotlight-outreach' ); ?></span>
					<input type="text" name="sender_name" class="regular-text" value="<?php echo esc_attr( Settings::get( 'sender_name' ) ); ?>">
				</label>
				<?php if ( Google_Oauth::credentials_from_constants() ) : ?>
					<p class="aso-callout aso-callout--green"><?php esc_html_e( 'OAuth credentials are defined in wp-config.php (ASO_GOOGLE_CLIENT_ID / ASO_GOOGLE_CLIENT_SECRET).', 'apostolic-spotlight-outreach' ); ?></p>
				<?php else : ?>
					<label class="aso-field"><span><?php esc_html_e( 'Client ID', 'apostolic-spotlight-outreach' ); ?></span>
						<input type="text" name="client_id" class="large-text" value="<?php echo esc_attr( Settings::get( 'google.client_id' ) ); ?>" autocomplete="off">
					</label>
					<label class="aso-field"><span><?php esc_html_e( 'Client secret', 'apostolic-spotlight-outreach' ); ?></span>
						<input type="password" name="client_secret" class="large-text" value="<?php echo Google_Oauth::client_secret() ? esc_attr( str_repeat( '•', 16 ) ) : ''; ?>" autocomplete="new-password">
						<small class="aso-muted"><?php esc_html_e( 'Stored encrypted. For best security define ASO_GOOGLE_CLIENT_ID and ASO_GOOGLE_CLIENT_SECRET in wp-config.php instead.', 'apostolic-spotlight-outreach' ); ?></small>
					</label>
				<?php endif; ?>
			<?php $form_close(); ?>
		</div>

		<aside class="aso-columns__side">
			<div class="aso-card">
				<div class="aso-card__head"><h3><?php esc_html_e( 'One-time Google setup', 'apostolic-spotlight-outreach' ); ?></h3></div>
				<ol class="aso-steps-list">
					<li><?php esc_html_e( 'In Google Cloud Console, create (or pick) a project.', 'apostolic-spotlight-outreach' ); ?></li>
					<li><?php esc_html_e( 'Enable the Gmail API and the Google Calendar API.', 'apostolic-spotlight-outreach' ); ?></li>
					<li><?php esc_html_e( 'OAuth consent screen: choose “Internal” (Workspace) so no Google verification is needed.', 'apostolic-spotlight-outreach' ); ?></li>
					<li><?php esc_html_e( 'Credentials → Create OAuth client ID → Web application.', 'apostolic-spotlight-outreach' ); ?></li>
					<li><?php esc_html_e( 'Add this Authorized redirect URI:', 'apostolic-spotlight-outreach' ); ?>
						<div class="aso-copy-field"><input type="text" readonly value="<?php echo esc_attr( Google_Oauth::redirect_uri() ); ?>" onclick="this.select()"><button type="button" class="button" data-aso-copy="<?php echo esc_attr( Google_Oauth::redirect_uri() ); ?>"><?php esc_html_e( 'Copy', 'apostolic-spotlight-outreach' ); ?></button></div>
					</li>
					<li><?php esc_html_e( 'Paste the Client ID and Secret here, save, then click Connect.', 'apostolic-spotlight-outreach' ); ?></li>
				</ol>
				<h4><?php esc_html_e( 'Permissions requested', 'apostolic-spotlight-outreach' ); ?></h4>
				<ul class="aso-list aso-list--compact">
					<?php foreach ( Google_Oauth::scopes( false ) as $s ) : ?>
						<li><?php echo esc_html( $scope_labels[ $s ] ?? $s ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="aso-help"><?php esc_html_e( 'Reading Gmail (headers only) is requested separately, and only if you turn on Reply Detection.', 'apostolic-spotlight-outreach' ); ?></p>
			</div>
		</aside>
	</div>
	<?php

/* ======================================================================
 * Church directory mapping
 * ==================================================================== */
elseif ( 'church' === $tab ) :
	$candidates = Field_Map::candidate_post_types();
	$pt         = Church_Repository::post_type();
	$configured = Church_Repository::is_configured();
	$meta_keys  = Field_Map::discover_meta_keys( $pt );
	$acf        = Field_Map::acf_labels( $pt );
	$taxes      = $pt ? get_object_taxonomies( $pt, 'objects' ) : array();
	$sample_ids = $pt ? Church_Repository::query( array( 'per_page' => 1 ) )['ids'] : array();
	$sample     = $sample_ids ? Church_Repository::find( $sample_ids[0] ) : null;
	$map        = (array) Settings::get( 'church.map', array() );
	$suggest    = $pt ? Field_Map::guess_map( $pt ) : array();
	$form_open();
	?>
	<div class="aso-card__head"><h3><?php esc_html_e( 'Where are your churches stored?', 'apostolic-spotlight-outreach' ); ?></h3></div>
	<p class="aso-help"><?php esc_html_e( 'The outreach system reads and updates your EXISTING church records. It never creates duplicate churches. Outreach-only data (statuses, timeline, campaigns) lives in separate plugin tables linked by the church’s post ID.', 'apostolic-spotlight-outreach' ); ?></p>
	<label class="aso-field"><span><?php esc_html_e( 'Church post type', 'apostolic-spotlight-outreach' ); ?></span>
		<select name="post_type">
			<?php foreach ( $candidates as $name => $info ) : ?>
				<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $pt, $name ); ?>><?php echo esc_html( sprintf( '%s (%s) – %s', $info['label'], $name, sprintf( /* translators: %d: count */ _n( '%d record', '%d records', $info['count'], 'apostolic-spotlight-outreach' ), $info['count'] ) ) . ( $info['score'] >= 10 ? ' ★' : '' ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! $configured ) : ?>
			<small class="aso-callout aso-callout--amber"><?php esc_html_e( 'Not confirmed yet – the starred post type is our best guess. Save to confirm.', 'apostolic-spotlight-outreach' ); ?></small>
		<?php endif; ?>
	</label>

	<h3><?php esc_html_e( 'Field mapping', 'apostolic-spotlight-outreach' ); ?></h3>
	<p class="aso-help"><?php esc_html_e( 'Point each outreach field at the directory field that already holds it (ACF fields appear by name). Choose “Outreach field” only when the directory has no such field – the value is then stored on the same church post as private meta.', 'apostolic-spotlight-outreach' ); ?></p>
	<table class="widefat aso-table aso-mapping">
		<thead><tr><th><?php esc_html_e( 'Outreach field', 'apostolic-spotlight-outreach' ); ?></th><th><?php esc_html_e( 'Directory source', 'apostolic-spotlight-outreach' ); ?></th><th><?php esc_html_e( 'Sample value', 'apostolic-spotlight-outreach' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( Field_Map::fields() as $field => $def ) : ?>
			<?php
			$current = $map[ $field ] ?? ( $configured ? $def['default'] : ( $suggest[ $field ] ?? $def['default'] ) );
			?>
			<tr>
				<td><strong><?php echo esc_html( $def['label'] ); ?></strong><?php echo isset( $suggest[ $field ] ) && $suggest[ $field ] !== $current && 'plugin' !== $suggest[ $field ] ? '<br><small class="aso-muted">' . esc_html( sprintf( /* translators: %s: source */ __( 'suggested: %s', 'apostolic-spotlight-outreach' ), $suggest[ $field ] ) ) . '</small>' : ''; ?></td>
				<td>
					<select name="map[<?php echo esc_attr( $field ); ?>]">
						<option value="plugin" <?php selected( $current, 'plugin' ); ?>><?php esc_html_e( '— Outreach field (not in directory) —', 'apostolic-spotlight-outreach' ); ?></option>
						<optgroup label="<?php esc_attr_e( 'Post', 'apostolic-spotlight-outreach' ); ?>">
							<option value="title" <?php selected( $current, 'title' ); ?>><?php esc_html_e( 'Post title', 'apostolic-spotlight-outreach' ); ?></option>
							<option value="permalink" <?php selected( $current, 'permalink' ); ?>><?php esc_html_e( 'Post URL (permalink)', 'apostolic-spotlight-outreach' ); ?></option>
						</optgroup>
						<?php if ( $acf ) : ?>
							<optgroup label="<?php esc_attr_e( 'ACF fields', 'apostolic-spotlight-outreach' ); ?>">
								<?php foreach ( $acf as $name => $label ) : ?>
									<option value="<?php echo esc_attr( 'meta:' . $name ); ?>" <?php selected( $current, 'meta:' . $name ); ?>><?php echo esc_html( $label . ' (' . $name . ')' ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
						<optgroup label="<?php esc_attr_e( 'Custom fields (post meta)', 'apostolic-spotlight-outreach' ); ?>">
							<?php foreach ( $meta_keys as $k ) : ?>
								<?php
								if ( isset( $acf[ $k ] ) ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( 'meta:' . $k ); ?>" <?php selected( $current, 'meta:' . $k ); ?>><?php echo esc_html( $k ); ?></option>
							<?php endforeach; ?>
							<?php if ( 0 === strpos( (string) $current, 'meta:' ) && ! in_array( substr( $current, 5 ), $meta_keys, true ) && ! isset( $acf[ substr( $current, 5 ) ] ) ) : ?>
								<option value="<?php echo esc_attr( $current ); ?>" selected><?php echo esc_html( substr( $current, 5 ) ); ?></option>
							<?php endif; ?>
						</optgroup>
						<?php if ( $taxes ) : ?>
							<optgroup label="<?php esc_attr_e( 'Taxonomies', 'apostolic-spotlight-outreach' ); ?>">
								<?php foreach ( $taxes as $tax ) : ?>
									<option value="<?php echo esc_attr( 'tax:' . $tax->name ); ?>" <?php selected( $current, 'tax:' . $tax->name ); ?>><?php echo esc_html( $tax->label . ' (' . $tax->name . ')' ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
					</select>
				</td>
				<td class="aso-muted"><?php echo $sample ? esc_html( wp_trim_words( $sample->get( $field ), 8 ) ) : ''; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $sample ) : ?>
		<p class="aso-help">
			<?php
			/* translators: %s: church name */
			echo esc_html( sprintf( __( 'Sample values are from “%s” using the saved mapping.', 'apostolic-spotlight-outreach' ), $sample->name() ) );
			?>
		</p>
	<?php endif; ?>
	<p><label><input type="checkbox" name="auto_detect" value="1"> <?php esc_html_e( 'Ignore the selections above and auto-detect the mapping from field names', 'apostolic-spotlight-outreach' ); ?></label></p>
	<p><label><input type="checkbox" name="write_back" value="1" <?php checked( Settings::get( 'church.write_back' ) ); ?>> <?php esc_html_e( 'Allow editing mapped directory fields (pastor name, emails, phone…) from outreach screens', 'apostolic-spotlight-outreach' ); ?></label></p>
	<?php
	$form_close();

/* ======================================================================
 * Sending
 * ==================================================================== */
elseif ( 'sending' === $tab ) :
	$s        = (array) Settings::get( 'sending' );
	$days_map = array( 1 => __( 'Monday', 'apostolic-spotlight-outreach' ), 2 => __( 'Tuesday', 'apostolic-spotlight-outreach' ), 3 => __( 'Wednesday', 'apostolic-spotlight-outreach' ), 4 => __( 'Thursday', 'apostolic-spotlight-outreach' ), 5 => __( 'Friday', 'apostolic-spotlight-outreach' ), 6 => __( 'Saturday', 'apostolic-spotlight-outreach' ), 7 => __( 'Sunday', 'apostolic-spotlight-outreach' ) );
	$form_open();
	?>
	<div class="aso-card__head"><h3><?php esc_html_e( 'Sending window', 'apostolic-spotlight-outreach' ); ?></h3></div>
	<p class="aso-help"><?php esc_html_e( 'Built for personal, low-volume pastor outreach. Emails are spread across the window rather than sent in a burst.', 'apostolic-spotlight-outreach' ); ?></p>
	<div class="aso-form-grid">
		<label class="aso-field"><span><?php esc_html_e( 'Maximum campaign emails per day', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="daily_limit" min="1" max="100" value="<?php echo esc_attr( $s['daily_limit'] ); ?>"><small class="aso-muted"><?php esc_html_e( 'Hard cap 100.', 'apostolic-spotlight-outreach' ); ?></small></label>
		<label class="aso-field"><span><?php esc_html_e( 'Minimum minutes between sends', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="min_spacing" min="1" max="240" value="<?php echo esc_attr( $s['min_spacing'] ); ?>"></label>
		<label class="aso-field"><span><?php esc_html_e( 'Send between', 'apostolic-spotlight-outreach' ); ?></span><span class="aso-inline"><input type="time" name="start" value="<?php echo esc_attr( $s['start'] ); ?>"> – <input type="time" name="end" value="<?php echo esc_attr( $s['end'] ); ?>"></span></label>
		<label class="aso-field"><span><?php esc_html_e( 'Timezone', 'apostolic-spotlight-outreach' ); ?></span><?php $tz_select( 'timezone', $s['timezone'] ); ?></label>
	</div>
	<fieldset class="aso-field"><span><?php esc_html_e( 'Sending days', 'apostolic-spotlight-outreach' ); ?></span>
		<div class="aso-days-check">
			<?php foreach ( $days_map as $n => $label ) : ?>
				<label><input type="checkbox" name="days[]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, array_map( 'intval', (array) $s['days'] ), true ) ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</div>
	</fieldset>
	<fieldset class="aso-field"><span><?php esc_html_e( 'Distribution', 'apostolic-spotlight-outreach' ); ?></span>
		<label class="aso-radio"><input type="radio" name="distribution" value="random" <?php checked( $s['distribution'], 'random' ); ?>> <?php esc_html_e( 'Spread evenly with natural random variation (recommended)', 'apostolic-spotlight-outreach' ); ?></label>
		<label class="aso-radio"><input type="radio" name="distribution" value="even" <?php checked( $s['distribution'], 'even' ); ?>> <?php esc_html_e( 'Spread exactly evenly', 'apostolic-spotlight-outreach' ); ?></label>
	</fieldset>

	<div class="aso-card__head aso-card__head--sub"><h3><?php esc_html_e( 'Sequence & safety defaults', 'apostolic-spotlight-outreach' ); ?></h3></div>
	<div class="aso-form-grid">
		<label class="aso-field"><span><?php esc_html_e( 'Default: Follow-Up 1 after (days)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" min="1" max="60" name="followup1_delay" value="<?php echo esc_attr( Settings::get( 'sequence.followup1_delay' ) ); ?>"></label>
		<label class="aso-field"><span><?php esc_html_e( 'Default: Follow-Up 2 after Follow-Up 1 (days)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" min="1" max="60" name="followup2_delay" value="<?php echo esc_attr( Settings::get( 'sequence.followup2_delay' ) ); ?>"></label>
		<label class="aso-field"><span><?php esc_html_e( 'Mark “No Response” this many days after the last email', 'apostolic-spotlight-outreach' ); ?></span><input type="number" min="1" max="60" name="no_response_days" value="<?php echo esc_attr( $s['no_response_days'] ); ?>"></label>
		<label class="aso-field"><span><?php esc_html_e( 'Warn if contacted within (days)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" min="0" max="365" name="recent_contact_days" value="<?php echo esc_attr( $s['recent_contact_days'] ); ?>"></label>
	</div>
	<p class="aso-help"><?php esc_html_e( 'Defaults apply to new campaigns; each campaign’s own delays can be edited on the campaign.', 'apostolic-spotlight-outreach' ); ?></p>
	<p><label><input type="checkbox" name="paused" value="1" <?php checked( ! empty( $s['paused'] ) ); ?>> <strong><?php esc_html_e( 'Pause ALL campaign sending', 'apostolic-spotlight-outreach' ); ?></strong></label></p>
	<p><label><input type="checkbox" name="rebalance" value="1"> <?php esc_html_e( 'Re-plan already scheduled emails with these settings', 'apostolic-spotlight-outreach' ); ?></label></p>

	<div class="aso-card__head aso-card__head--sub"><h3><?php esc_html_e( 'Scheduler', 'apostolic-spotlight-outreach' ); ?></h3></div>
	<p class="aso-help">
		<?php
		$last = (int) get_option( Cron::LAST_RUN, 0 );
		echo esc_html( $last ? sprintf( /* translators: %s: time */ __( 'Last run %s ago.', 'apostolic-spotlight-outreach' ), human_time_diff( $last ) ) : __( 'Has not run yet.', 'apostolic-spotlight-outreach' ) ) . ' ';
		echo esc_html( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'WP-Cron is triggered by a system cron (DISABLE_WP_CRON is set).', 'apostolic-spotlight-outreach' ) : __( 'WP-Cron currently runs on site traffic. For reliable timing, add DISABLE_WP_CRON to wp-config.php and a system cron:', 'apostolic-spotlight-outreach' ) );
		?>
	</p>
	<code class="aso-code">*/5 * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt; /dev/null</code>
	<?php
	$form_close();

/* ======================================================================
 * Booking
 * ==================================================================== */
elseif ( 'booking' === $tab ) :
	$b        = (array) Settings::get( 'booking' );
	$days_map = array( 1 => __( 'Monday', 'apostolic-spotlight-outreach' ), 2 => __( 'Tuesday', 'apostolic-spotlight-outreach' ), 3 => __( 'Wednesday', 'apostolic-spotlight-outreach' ), 4 => __( 'Thursday', 'apostolic-spotlight-outreach' ), 5 => __( 'Friday', 'apostolic-spotlight-outreach' ), 6 => __( 'Saturday', 'apostolic-spotlight-outreach' ), 7 => __( 'Sunday', 'apostolic-spotlight-outreach' ) );
	?>
	<div class="aso-columns">
		<div class="aso-columns__main">
			<?php $form_open(); ?>
			<div class="aso-card__head"><h3><?php esc_html_e( 'Scheduling page', 'apostolic-spotlight-outreach' ); ?></h3><a href="<?php echo esc_url( Settings::booking_url() ); ?>" target="_blank"><?php esc_html_e( 'Open page', 'apostolic-spotlight-outreach' ); ?> ↗</a></div>
			<div class="aso-form-grid">
				<label class="aso-field"><span><?php esc_html_e( 'Page URL path', 'apostolic-spotlight-outreach' ); ?></span><span class="aso-inline"><code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code><input type="text" name="slug" value="<?php echo esc_attr( $b['slug'] ); ?>"></span><small class="aso-muted"><?php esc_html_e( 'Or place [apostolic_spotlight_booking] on any page.', 'apostolic-spotlight-outreach' ); ?></small></label>
				<label class="aso-field"><span><?php esc_html_e( 'Meeting name', 'apostolic-spotlight-outreach' ); ?></span><input type="text" name="meeting_name" value="<?php echo esc_attr( $b['meeting_name'] ); ?>"></label>
				<label class="aso-field"><span><?php esc_html_e( 'Calendar event title', 'apostolic-spotlight-outreach' ); ?></span><input type="text" name="event_title" value="<?php echo esc_attr( $b['event_title'] ); ?>"><small class="aso-muted">{{church_name}}, {{pastor_name}}</small></label>
				<label class="aso-field"><span><?php esc_html_e( 'Timezone (your availability)', 'apostolic-spotlight-outreach' ); ?></span><?php $tz_select( 'timezone', $b['timezone'] ); ?></label>
			</div>
			<label class="aso-field"><span><?php esc_html_e( 'Intro text', 'apostolic-spotlight-outreach' ); ?></span><textarea name="intro" rows="3" class="large-text"><?php echo esc_textarea( $b['intro'] ); ?></textarea></label>

			<div class="aso-card__head aso-card__head--sub"><h3><?php esc_html_e( 'Interview length & rules', 'apostolic-spotlight-outreach' ); ?></h3></div>
			<div class="aso-form-grid aso-form-grid--3">
				<label class="aso-field"><span><?php esc_html_e( 'Duration (min)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="duration" min="5" max="240" value="<?php echo esc_attr( $b['duration'] ); ?>"></label>
				<label class="aso-field"><span><?php esc_html_e( 'Start times every (min)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="slot_interval" min="5" max="240" value="<?php echo esc_attr( $b['slot_interval'] ); ?>"></label>
				<label class="aso-field"><span><?php esc_html_e( 'Max interviews per day (0 = no limit)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="max_per_day" min="0" max="50" value="<?php echo esc_attr( $b['max_per_day'] ); ?>"></label>
				<label class="aso-field"><span><?php esc_html_e( 'Buffer before (min)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="buffer_before" min="0" max="240" value="<?php echo esc_attr( $b['buffer_before'] ); ?>"></label>
				<label class="aso-field"><span><?php esc_html_e( 'Buffer after (min)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="buffer_after" min="0" max="240" value="<?php echo esc_attr( $b['buffer_after'] ); ?>"></label>
				<label class="aso-field"><span><?php esc_html_e( 'Minimum notice (hours)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="min_notice_hours" min="0" max="720" step="0.5" value="<?php echo esc_attr( $b['min_notice_hours'] ); ?>"></label>
				<label class="aso-field"><span><?php esc_html_e( 'Booking window (days ahead)', 'apostolic-spotlight-outreach' ); ?></span><input type="number" name="window_days" min="1" max="365" value="<?php echo esc_attr( $b['window_days'] ); ?>"></label>
			</div>

			<div class="aso-card__head aso-card__head--sub"><h3><?php esc_html_e( 'Weekly availability', 'apostolic-spotlight-outreach' ); ?></h3></div>
			<table class="aso-hours">
				<?php foreach ( $days_map as $d => $label ) : ?>
					<?php $ranges = array_values( (array) ( $b['hours'][ $d ] ?? array() ) ); ?>
					<tr>
						<th><?php echo esc_html( $label ); ?></th>
						<?php for ( $i = 0; $i < 2; $i++ ) : ?>
							<td>
								<input type="time" name="hours[<?php echo esc_attr( $d ); ?>][<?php echo esc_attr( $i ); ?>][from]" value="<?php echo esc_attr( $ranges[ $i ][0] ?? '' ); ?>">
								–
								<input type="time" name="hours[<?php echo esc_attr( $d ); ?>][<?php echo esc_attr( $i ); ?>][to]" value="<?php echo esc_attr( $ranges[ $i ][1] ?? '' ); ?>">
							</td>
						<?php endfor; ?>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="aso-help"><?php esc_html_e( 'Leave a day empty to be unavailable. A second range per day is optional.', 'apostolic-spotlight-outreach' ); ?></p>

			<div class="aso-card__head aso-card__head--sub"><h3><?php esc_html_e( 'Calendars', 'apostolic-spotlight-outreach' ); ?></h3></div>
			<div class="aso-form-grid">
				<label class="aso-field"><span><?php esc_html_e( 'Check these calendars for conflicts', 'apostolic-spotlight-outreach' ); ?></span><input type="text" name="busy_calendars" value="<?php echo esc_attr( $b['busy_calendars'] ); ?>"><small class="aso-muted"><?php esc_html_e( 'Comma-separated calendar IDs. “primary” = your main calendar. Add family/church calendars you share to avoid double-booking.', 'apostolic-spotlight-outreach' ); ?></small></label>
				<label class="aso-field"><span><?php esc_html_e( 'Create interview events on', 'apostolic-spotlight-outreach' ); ?></span><input type="text" name="event_calendar" value="<?php echo esc_attr( $b['event_calendar'] ); ?>"></label>
			</div>
			<p><label><input type="checkbox" name="admin_link_in_event" value="1" <?php checked( ! empty( $b['admin_link_in_event'] ) ); ?>> <?php esc_html_e( 'Include a link to the WordPress outreach record in the event description (the pastor can see the description too)', 'apostolic-spotlight-outreach' ); ?></label></p>
			<?php $form_close(); ?>
		</div>
		<aside class="aso-columns__side">
			<div class="aso-card">
				<div class="aso-card__head"><h3><?php esc_html_e( 'Next available times', 'apostolic-spotlight-outreach' ); ?></h3></div>
				<?php
				$slots = Availability::slots();
				if ( is_wp_error( $slots ) ) {
					echo '<p class="aso-error">' . esc_html( $slots->get_error_message() ) . '</p>';
				} elseif ( ! $slots ) {
					echo '<p class="aso-muted">' . esc_html__( 'No open times in the booking window.', 'apostolic-spotlight-outreach' ) . '</p>';
				} else {
					echo '<ul class="aso-list aso-list--compact">';
					foreach ( array_slice( $slots, 0, 12 ) as $slot ) {
						echo '<li>' . esc_html( wp_date( 'D M j · g:i a T', $slot['start'], Settings::booking_timezone() ) ) . '</li>';
					}
					echo '</ul>';
					/* translators: %d: count */
					echo '<p class="aso-muted">' . esc_html( sprintf( __( '%d open times in total, after removing calendar conflicts.', 'apostolic-spotlight-outreach' ), count( $slots ) ) ) . '</p>';
				}
				?>
			</div>
		</aside>
	</div>
	<?php

/* ======================================================================
 * Reply detection
 * ==================================================================== */
elseif ( 'replies' === $tab ) :
	$status    = get_option( Reply_Detector::STATUS_OPTION, array() );
	$has_scope = Google_Oauth::has_scope( Google_Oauth::SCOPE_METADATA );
	$form_open();
	?>
	<div class="aso-card__head"><h3><?php esc_html_e( 'Automatic reply detection', 'apostolic-spotlight-outreach' ); ?></h3>
		<?php echo Reply_Detector::is_enabled() ? '<span class="aso-badge aso-badge--green">' . esc_html__( 'On', 'apostolic-spotlight-outreach' ) . '</span>' : '<span class="aso-badge aso-badge--gray">' . esc_html__( 'Off', 'apostolic-spotlight-outreach' ) . '</span>'; ?>
	</div>
	<p><?php esc_html_e( 'Follow-ups always stop the moment you click “Mark Replied”, a pastor books, or you mark them Not Interested or pause them. Automatic detection is an optional extra safety net.', 'apostolic-spotlight-outreach' ); ?></p>
	<div class="aso-callout aso-callout--blue">
		<p><strong><?php esc_html_e( 'This needs one additional Google permission:', 'apostolic-spotlight-outreach' ); ?></strong> <code>gmail.metadata</code></p>
		<ul class="aso-list aso-list--compact">
			<li><?php esc_html_e( 'Lets the plugin see message headers (From, Subject, date) and thread IDs of new Inbox messages.', 'apostolic-spotlight-outreach' ); ?></li>
			<li><?php esc_html_e( 'It can NOT read message bodies or attachments, and cannot send, delete or change anything.', 'apostolic-spotlight-outreach' ); ?></li>
			<li><?php esc_html_e( 'Only replies from addresses you emailed, or in threads the plugin started, are matched. Nothing else is stored.', 'apostolic-spotlight-outreach' ); ?></li>
			<li><?php esc_html_e( 'Out-of-office auto-replies are logged and flagged but do not stop the sequence; bounces stop it and flag the address.', 'apostolic-spotlight-outreach' ); ?></li>
		</ul>
	</div>
	<p><label><input type="checkbox" name="enabled" value="1" <?php checked( (bool) Settings::get( 'replies.enabled' ) ); ?> <?php disabled( ! Google_Oauth::is_connected() ); ?>> <strong><?php esc_html_e( 'Detect replies automatically and stop follow-ups', 'apostolic-spotlight-outreach' ); ?></strong></label></p>
	<?php if ( ! $has_scope && Google_Oauth::is_connected() ) : ?>
		<p class="aso-help"><?php esc_html_e( 'When you save with this turned on, you will be sent to Google to approve the additional header-only permission.', 'apostolic-spotlight-outreach' ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $status['last_run'] ) ) : ?>
		<p class="aso-muted">
			<?php
			/* translators: 1: time 2: count */
			echo esc_html( sprintf( __( 'Last check %1$s ago · %2$d match(es).', 'apostolic-spotlight-outreach' ), human_time_diff( (int) $status['last_run'] ), (int) ( $status['found'] ?? 0 ) ) );
			echo ! empty( $status['error'] ) ? ' ' . esc_html( $status['error'] ) : '';
			?>
		</p>
	<?php endif; ?>
	<?php
	$form_close();

/* ======================================================================
 * Permissions
 * ==================================================================== */
elseif ( 'permissions' === $tab ) :
	$form_open();
	?>
	<div class="aso-card__head"><h3><?php esc_html_e( 'Who can use outreach?', 'apostolic-spotlight-outreach' ); ?></h3></div>
	<table class="widefat aso-table">
		<thead><tr><th><?php esc_html_e( 'Capability', 'apostolic-spotlight-outreach' ); ?></th><th><?php esc_html_e( 'Allows', 'apostolic-spotlight-outreach' ); ?></th><th><?php esc_html_e( 'Administrators', 'apostolic-spotlight-outreach' ); ?></th><th><?php esc_html_e( 'Editors', 'apostolic-spotlight-outreach' ); ?></th></tr></thead>
		<tbody>
			<tr><td><code>aso_manage_outreach</code></td><td><?php esc_html_e( 'Dashboard, churches, campaigns, queue, bookings, opportunities, sending emails', 'apostolic-spotlight-outreach' ); ?></td><td>✓</td><td><?php echo Settings::get( 'permissions.editors' ) ? '✓' : '—'; ?></td></tr>
			<tr><td><code>aso_manage_templates</code></td><td><?php esc_html_e( 'Create and edit email templates', 'apostolic-spotlight-outreach' ); ?></td><td>✓</td><td><?php echo Settings::get( 'permissions.editors' ) ? '✓' : '—'; ?></td></tr>
			<tr><td><code>aso_manage_settings</code></td><td><?php esc_html_e( 'Google connection, OAuth credentials, sending limits, directory mapping', 'apostolic-spotlight-outreach' ); ?></td><td>✓</td><td>—</td></tr>
		</tbody>
	</table>
	<p><label><input type="checkbox" name="editors" value="1" <?php checked( (bool) Settings::get( 'permissions.editors' ) ); ?>> <?php esc_html_e( 'Allow Editors to run outreach and edit templates (they never see Settings or Google tokens)', 'apostolic-spotlight-outreach' ); ?></label></p>
	<p class="aso-help"><?php esc_html_e( 'Custom roles can be granted these capabilities with any role-editor plugin.', 'apostolic-spotlight-outreach' ); ?></p>
	<?php
	$form_close();
endif;
