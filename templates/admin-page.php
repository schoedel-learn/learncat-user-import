<?php
/**
 * Admin page template for LearnCAT User Import
 *
 * Variables available:
 *   $results        array|null   Import results (null if no import yet)
 *   $parse_err      string       CSV parse error message
 *   $parse_notices  array        Notices from CSV parser (e.g. description row warning)
 *   $dry_run        bool         Whether the last run was a dry run
 *   $sections       array        Field registry grouped by section
 */
defined( 'ABSPATH' ) || exit;

$section_titles = [
	'wp_core'     => 'WordPress Core',
	'buddyboss'   => 'BuddyBoss / XProfile',
	'woocommerce' => 'WooCommerce',
	'learndash'   => 'LearnDash',
];

$section_css_classes = [
	'wp_core'     => 'lcui-section-wp',
	'buddyboss'   => 'lcui-section-bb',
	'woocommerce' => 'lcui-section-wc',
	'learndash'   => 'lcui-section-ld',
];
?>
<div class="wrap lcui-wrap">
	<h1>
		<span class="dashicons dashicons-upload" style="margin-top:4px"></span>
		LearnCAT — Bulk User Import
	</h1>
	<p class="lcui-tagline">
		Import users from a CSV file. Columns that don&rsquo;t match a known field are silently ignored.
		Download the template below to see every available column with descriptions.
	</p>

	<?php if ( $parse_err ) : ?>
		<div class="notice notice-error is-dismissible"><p><strong>CSV Error:</strong> <?php echo esc_html( $parse_err ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $parse_notices ) ) : ?>
		<?php foreach ( $parse_notices as $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php
	// UX-1: Results rendered ABOVE the form so the admin sees them immediately
	if ( $results ) {
		lcui_render_results_inline( $results );
	}
	?>

	<!-- ── Import form ───────────────────────────────────────────────────── -->
	<div class="lcui-card">
		<h2>Import a CSV File</h2>

		<!-- UX-2: Dry run recommendation callout -->
		<div class="notice notice-info lcui-dry-run-callout" style="margin: 0 0 16px; padding: 10px 14px;">
			<p><strong>We recommend testing your file first.</strong> Check the &ldquo;Dry run (test only)&rdquo; box below before clicking Import &mdash; no data will be changed.</p>
		</div>

		<form method="post" enctype="multipart/form-data" action="" id="lcui-import-form">
			<?php wp_nonce_field( LCUI_Admin_Page::NONCE_ACTION, 'lcui_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lcui_csv">CSV file</label></th>
					<td>
						<input type="file" id="lcui_csv" name="lcui_csv" accept=".csv,.txt" required>
						<p class="description">Max 10 MB. Encoding: UTF-8 (with or without BOM).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Dry run</th>
					<td>
						<label>
							<input type="checkbox" name="lcui_dry_run" value="1" checked>
							Dry run (test only) &mdash; preview what <em>would</em> happen without writing any data
						</label>
					</td>
				</tr>
			</table>

			<!-- ── Email & Notification Settings (UX-4) ───────────────────────── -->
			<div class="lcui-notification-controls">
				<h3>Email &amp; Notification Settings</h3>
				<p class="description">
					By default, your site sends emails exactly as it&rsquo;s configured to.
					Check a box below to turn off specific emails for this import only &mdash;
					settings are not permanently changed.
				</p>

				<fieldset>
					<label class="lcui-suppress-label">
						<input type="checkbox" name="lcui_suppress_wp_new_user" value="1">
						Don&rsquo;t send the welcome email to newly created users
					</label>

					<label class="lcui-suppress-label">
						<input type="checkbox" name="lcui_suppress_wc_new_account" value="1">
						Don&rsquo;t send the WooCommerce account created email
					</label>

					<label class="lcui-suppress-label">
						<input type="checkbox" name="lcui_suppress_wc_processing" value="1">
						Don&rsquo;t send the WooCommerce order received email
					</label>

					<label class="lcui-suppress-label">
						<input type="checkbox" name="lcui_suppress_ld_enrollment" value="1">
						Don&rsquo;t send LearnDash course enrollment emails
					</label>

					<label class="lcui-suppress-label">
						<input type="checkbox" name="lcui_suppress_bb_notifications" value="1">
						Don&rsquo;t send BuddyBoss in-app notifications
					</label>

					<label class="lcui-suppress-label">
						<input type="checkbox" name="lcui_suppress_uo_certificate" value="1">
						Don&rsquo;t send Uncanny Owl certificate emails
					</label>
				</fieldset>
			</div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">On duplicate</th>
					<td>
						<p class="description">
							If a matching <code>user_login</code> <strong>or</strong> <code>user_email</code> is found,
							the existing user is <strong>updated</strong> with the CSV values.
							Blank cells are skipped &mdash; they do not overwrite existing data.
							If no match is found &mdash; a new user is created using the email and password from the CSV.
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="lcui_submit" class="button button-primary button-large" value="Import CSV">
			</p>
		</form>
	</div>

	<!-- ── Template downloads (UX-3) ─────────────────────────────────────── -->
	<div class="lcui-card">
		<h2>Download a Template</h2>
		<p>
			Use a template to prepare your data. Every importable column is included,
			with descriptions and (where applicable) dropdown lists of valid values.
		</p>

		<div class="lcui-download-section">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lcui-download-form lcui-download-primary">
				<input type="hidden" name="action" value="lcui_download_xlsx">
				<?php wp_nonce_field( 'lcui_download_xlsx' ); ?>
				<input type="submit" class="button button-primary button-large" value="&#11015; Download Template — Start Here">
				<p class="description">Opens in Excel or Google Sheets. Dropdown menus guide you to valid values.</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lcui-download-form lcui-download-csv-link">
				<input type="hidden" name="action" value="lcui_download_sample">
				<?php wp_nonce_field( 'lcui_download_sample' ); ?>
				<p class="description">Advanced users: <button type="submit" class="button-link">Download plain CSV sample</button> (no dropdown guidance)</p>
			</form>
		</div>
	</div>

	<!-- ── Column reference (IMPROVEMENT-3: color-coded section headers) ── -->
	<div class="lcui-card">
		<h2>Column Reference <button type="button" class="button button-small lcui-toggle-ref">Show / Hide</button></h2>
		<div id="lcui-col-ref" style="display:none">
			<?php foreach ( $section_titles as $section => $title ) : ?>
				<?php if ( empty( $sections[ $section ] ) ) : continue; endif; ?>
				<h3 class="<?php echo esc_attr( $section_css_classes[ $section ] ?? '' ); ?>"><?php echo esc_html( $title ); ?></h3>
				<table class="widefat striped lcui-ref-table">
					<thead>
						<tr>
							<th>CSV Column Header</th>
							<th>Label</th>
							<th>Required?</th>
							<th>Notes</th>
							<th>Valid Values</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sections[ $section ] as $slug => $def ) :
							$valid_vals = LCUI_Field_Registry::get_valid_values( $slug );
						?>
							<tr>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
								<td><?php echo esc_html( $def['label'] ); ?></td>
								<td><?php echo $def['required'] ? '<strong>Yes</strong>' : 'No'; ?></td>
								<td><?php echo esc_html( $def['note'] ?? '' ); ?></td>
								<td>
									<?php if ( empty( $valid_vals ) ) : ?>
										<span class="lcui-valid-freetext">Free text</span>
									<?php else :
										$display = array_slice( $valid_vals, 0, 5 );
										$remaining = count( $valid_vals ) - 5;
										foreach ( $display as $v ) : ?>
											<span class="lcui-pill"><?php echo esc_html( $v ); ?></span>
										<?php endforeach;
										if ( $remaining > 0 ) : ?>
											<span class="lcui-pill lcui-pill-more">(+ <?php echo (int) $remaining; ?> more)</span>
										<?php endif;
									endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<?php
// ── Results renderer ─────────────────────────────────────────────────────────
if ( ! function_exists( 'lcui_render_results_inline' ) ) :
function lcui_render_results_inline( array $results ): void {
	$total_warnings = (int) ( $results['warnings'] ?? 0 );
	$total_errors   = (int) ( $results['errors'] ?? 0 );
	$created        = (int) ( $results['created'] ?? 0 );
	$updated        = (int) ( $results['updated'] ?? 0 );
	?>
	<div id="lcui-results" class="lcui-card lcui-results">
		<h2><?php echo $results['dry_run'] ? 'Dry Run Preview' : 'Import Results'; ?></h2>

		<?php // UX-8: Summary banner ?>
		<?php if ( ! $results['dry_run'] ) : ?>
			<?php if ( $total_errors === 0 && $total_warnings === 0 ) : ?>
				<div class="lcui-summary-banner lcui-banner-success">
					Import complete. <?php echo $created; ?> user(s) created, <?php echo $updated; ?> updated, 0 errors.
				</div>
			<?php elseif ( $total_errors === 0 && $total_warnings > 0 ) : ?>
				<div class="lcui-summary-banner lcui-banner-warning">
					Import complete with some skipped fields. <?php echo $created; ?> user(s) created, <?php echo $updated; ?> updated, <?php echo $total_warnings; ?> warning(s). See details below.
				</div>
			<?php else : ?>
				<div class="lcui-summary-banner lcui-banner-error">
					Import finished with errors. <?php echo $created; ?> user(s) created, <?php echo $total_errors; ?> row(s) skipped due to errors. See details below.
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<ul class="lcui-summary">
			<li><strong>Total rows:</strong> <?php echo (int) $results['total']; ?></li>
			<?php if ( ! $results['dry_run'] ) : ?>
			<li><strong>Created:</strong> <?php echo $created; ?></li>
			<li><strong>Updated:</strong> <?php echo $updated; ?></li>
			<?php endif; ?>
			<?php if ( $total_warnings > 0 ) : ?>
			<li class="lcui-summary-warning"><strong>Warnings:</strong> <?php echo $total_warnings; ?></li>
			<?php endif; ?>
			<li><strong>Errors / skipped:</strong> <?php echo $total_errors; ?></li>
		</ul>

		<?php // UX-5: Color coding legend ?>
		<p class="lcui-results-legend">
			<span class="lcui-legend-error">Red rows</span> = user was not created or updated.
			<span class="lcui-legend-warning">Yellow rows</span> = user was saved, but something was skipped.
			<span class="lcui-legend-ok">Green rows</span> = all good.
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Row #</th>
					<th>User</th>
					<th>Action</th>
					<th>Log</th>
					<th>Warnings</th>
					<th>Errors</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results['rows'] as $r ) :
					$has_errors   = ! empty( $r['errors'] );
					$has_warnings = ! empty( $r['warnings'] );
					$row_class    = $has_errors ? 'lcui-row-error' : ( $has_warnings ? 'lcui-row-warning' : 'lcui-row-ok' );
				?>
					<tr class="<?php echo $row_class; ?>">
						<td><?php echo (int) $r['row_number']; ?></td>
						<td><?php echo esc_html( $r['identifier'] ); ?></td>
						<td>
							<?php if ( $results['dry_run'] ) :
								echo $r['is_new'] ? '<span class="lcui-badge lcui-badge-new">Would create</span>' : '<span class="lcui-badge lcui-badge-update">Would update</span>';
							else :
								echo $r['is_new'] ? '<span class="lcui-badge lcui-badge-new">Created</span>' : '<span class="lcui-badge lcui-badge-update">Updated</span>';
							endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $r['log'] ) ) : ?>
								<ul class="lcui-log">
									<?php foreach ( $r['log'] as $entry ) :
										$is_warning = ( strpos( $entry, '⚠️' ) === 0 );
									?>
										<li class="<?php echo $is_warning ? 'lcui-warning' : ''; ?>"><?php echo esc_html( $entry ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $r['warnings'] ) ) : ?>
								<ul class="lcui-warnings">
									<?php foreach ( $r['warnings'] as $w ) : ?>
										<li><?php echo esc_html( $w ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $r['errors'] ) ) : ?>
								<ul class="lcui-errors">
									<?php foreach ( $r['errors'] as $err ) : ?>
										<li><?php echo esc_html( $err ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		var r = document.getElementById('lcui-results');
		if (r) { r.scrollIntoView({ behavior: 'smooth' }); }
	});
	</script>
	<?php
}
endif;
