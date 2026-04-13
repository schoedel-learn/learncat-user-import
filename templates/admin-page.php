<?php
/**
 * Admin page template for LearnCAT User Import
 *
 * Variables available:
 *   $results   array|null   Import results (null if no import yet)
 *   $parse_err string       CSV parse error message
 *   $dry_run   bool         Whether the last run was a dry run
 *   $sections  array        Field registry grouped by section
 */
defined( 'ABSPATH' ) || exit;

$section_titles = [
	'wp_core'     => 'WordPress Core',
	'buddyboss'   => 'BuddyBoss / XProfile',
	'woocommerce' => 'WooCommerce',
	'learndash'   => 'LearnDash',
];
?>
<div class="wrap lcui-wrap">
	<h1>
		<span class="dashicons dashicons-upload" style="margin-top:4px"></span>
		LearnCAT — Bulk User Import
	</h1>
	<p class="lcui-tagline">
		Import users from a CSV file. Columns that don&rsquo;t match a known field are silently ignored.
		Download the sample CSV to see every available column with descriptions.
	</p>

	<?php if ( $parse_err ) : ?>
		<div class="notice notice-error is-dismissible"><p><strong>CSV Error:</strong> <?php echo esc_html( $parse_err ); ?></p></div>
	<?php endif; ?>

	<?php /* results rendered at bottom of template */ ?>

	<!-- ── Import form ───────────────────────────────────────────────────── -->
	<div class="lcui-card">
		<h2>Import a CSV File</h2>
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
							<input type="checkbox" name="lcui_dry_run" value="1">
							Preview what <em>would</em> happen without writing any data
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">On duplicate</th>
					<td>
						<p class="description">
							If a matching <code>user_login</code> <strong>or</strong> <code>user_email</code> is found,
							the existing user is <strong>updated</strong> with the CSV values.
							Blank cells are skipped &mdash; they do not overwrite existing data.
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="lcui_submit" class="button button-primary button-large" value="Import CSV">
			</p>
		</form>
	</div>

	<!-- ── Sample CSV download ───────────────────────────────────────────── -->
	<div class="lcui-card">
		<h2>Download Sample CSV</h2>
		<p>
			The sample file contains every importable column header, with a description row
			below the header to guide data entry. Delete the description row before importing.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lcui_download_sample">
			<?php wp_nonce_field( 'lcui_download_sample' ); ?>
			<input type="submit" class="button button-secondary" value="⬇ Download sample CSV">
		</form>
	</div>

	<!-- ── Column reference ──────────────────────────────────────────────── -->
	<div class="lcui-card">
		<h2>Column Reference <button type="button" class="button button-small lcui-toggle-ref">Show / Hide</button></h2>
		<div id="lcui-col-ref" style="display:none">
			<?php foreach ( $section_titles as $section => $title ) : ?>
				<?php if ( empty( $sections[ $section ] ) ) : continue; endif; ?>
				<h3><?php echo esc_html( $title ); ?></h3>
				<table class="widefat striped lcui-ref-table">
					<thead>
						<tr>
							<th>CSV Column Header</th>
							<th>Label</th>
							<th>Required?</th>
							<th>Notes</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sections[ $section ] as $slug => $def ) : ?>
							<tr>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
								<td><?php echo esc_html( $def['label'] ); ?></td>
								<td><?php echo $def['required'] ? '<strong>Yes</strong>' : 'No'; ?></td>
								<td><?php echo esc_html( $def['note'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<?php
// ── Results renderer (static method called inline above) ─────────────────────
// We add it as a free function inside the template to keep the template self-contained.
if ( ! function_exists( 'lcui_render_results_inline' ) ) :
function lcui_render_results_inline( array $results ): void {
	$icon = $results['dry_run'] ? '🔍' : ( $results['errors'] ? '⚠️' : '✅' );
	?>
	<div class="lcui-card lcui-results">
		<h2><?php echo $results['dry_run'] ? 'Dry Run Preview' : 'Import Results'; ?></h2>
		<ul class="lcui-summary">
			<li><strong>Total rows:</strong> <?php echo (int) $results['total']; ?></li>
			<?php if ( ! $results['dry_run'] ) : ?>
			<li><strong>Created:</strong> <?php echo (int) $results['created']; ?></li>
			<li><strong>Updated:</strong> <?php echo (int) $results['updated']; ?></li>
			<li><strong>Errors / skipped:</strong> <?php echo (int) $results['errors']; ?></li>
			<?php endif; ?>
		</ul>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Row #</th>
					<th>User</th>
					<th>Action</th>
					<th>Log</th>
					<th>Errors</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results['rows'] as $r ) : ?>
					<tr class="<?php echo ! empty( $r['errors'] ) ? 'lcui-row-error' : 'lcui-row-ok'; ?>">
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
									<?php foreach ( $r['log'] as $entry ) : ?>
										<li><?php echo esc_html( $entry ); ?></li>
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
	<?php
}
endif;

// Hook render function into the class call above
// (The class calls self::render_results() but since this is a template
//  we define the function here and the class delegates here.)
if ( $results ) {
	lcui_render_results_inline( $results );
}
