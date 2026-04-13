<?php
/**
 * LCUI_CSV_Parser
 *
 * Reads an uploaded CSV file, normalises headers, and returns an
 * array of row arrays keyed by the normalised header slug.
 *
 * Header normalisation rules:
 *   - Trim whitespace
 *   - Lower-case
 *   - Replace spaces and hyphens with underscores
 *   - Strip non-alphanumeric characters except underscores
 *
 * Headers that do not match any registered field slug are retained as-is
 * in the row data so the importer can decide to skip them.
 */
class LCUI_CSV_Parser {

	/** Maximum file size: 10 MB */
	const MAX_FILE_SIZE = 10 * 1024 * 1024;

	/**
	 * Parse an uploaded file array ($_FILES['csv_file']).
	 *
	 * @param array $file  $_FILES entry
	 * @return array{headers: string[], rows: array[], error: string}
	 */
	public static function parse( array $file ): array {
		$result = [
			'headers' => [],
			'rows'    => [],
			'error'   => '',
			'notices' => [],
		];

		// ── Validate upload ───────────────────────────────────────────────────
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$result['error'] = 'No file was uploaded or the upload failed.';
			return $result;
		}

		if ( $file['size'] > self::MAX_FILE_SIZE ) {
			$result['error'] = 'File exceeds the 10 MB maximum size.';
			return $result;
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) {
			$result['error'] = 'Only .csv or .txt files are accepted.';
			return $result;
		}

		// ── Open and read ─────────────────────────────────────────────────────
		$handle = fopen( $file['tmp_name'], 'r' );
		if ( ! $handle ) {
			$result['error'] = 'Could not open the uploaded file.';
			return $result;
		}

		// Detect and strip BOM
		$bom = fread( $handle, 3 );
		if ( $bom !== "\xEF\xBB\xBF" ) {
			rewind( $handle );
		}

		// Read header row
		$raw_headers = fgetcsv( $handle, 0, ',' );
		if ( ! $raw_headers ) {
			fclose( $handle );
			$result['error'] = 'The file appears to be empty or has no header row.';
			return $result;
		}

		$headers = array_map( [ self::class, 'normalise_header' ], $raw_headers );
		$result['headers'] = $headers;

		// Read data rows
		$row_number = 1;
		while ( ( $raw = fgetcsv( $handle, 0, ',' ) ) !== false ) {
			$row_number++;
			if ( empty( array_filter( $raw ) ) ) {
				continue; // skip completely empty rows
			}

			// Detect and skip rows where any cell value starts with "Valid:" (description row pattern)
			$is_description_row = false;
			foreach ( $raw as $cell ) {
				if ( strpos( trim( $cell ), 'Valid:' ) === 0 ) {
					$is_description_row = true;
					break;
				}
			}
			if ( $is_description_row ) {
				if ( $row_number === 2 ) {
					$result['notices'][] = [
						'type'    => 'warning',
						'message' => 'It looks like the description row from the template is still in your file (Row 2). We skipped it automatically, but for best results delete Row 2 from your spreadsheet before exporting to CSV.',
					];
				}
				continue; // skip description rows
			}

			// Pad row to same length as headers (handles trailing missing commas)
			$raw = array_pad( $raw, count( $headers ), '' );

			$row = [
				'_row_number' => $row_number,
			];
			foreach ( $headers as $i => $header ) {
				$row[ $header ] = isset( $raw[ $i ] ) ? trim( $raw[ $i ] ) : '';
			}
			$result['rows'][] = $row;
		}

		fclose( $handle );

		if ( empty( $result['rows'] ) ) {
			$result['error'] = 'The file has a header row but no data rows.';
		}

		return $result;
	}

	/**
	 * Normalise a CSV header to a consistent slug.
	 */
	public static function normalise_header( string $raw ): string {
		$slug = strtolower( trim( $raw ) );
		$slug = str_replace( [ ' ', '-' ], '_', $slug );
		$slug = preg_replace( '/[^a-z0-9_]/', '', $slug );
		return $slug;
	}

	/**
	 * Generate a sample CSV with all known columns pre-filled as headers.
	 * Columns are grouped by section for readability.
	 *
	 * @return string  Raw CSV content (UTF-8 with BOM for Excel compatibility)
	 */
	public static function generate_sample_csv(): string {
		$sections = LCUI_Field_Registry::sections();
		$headers  = [];
		$notes    = [];

		$section_labels = [
			'wp_core'     => '# ── WordPress Core ──',
			'buddyboss'   => '# ── BuddyBoss / XProfile ──',
			'woocommerce' => '# ── WooCommerce Billing / Shipping ──',
			'learndash'   => '# ── LearnDash Courses & Groups ──',
		];

		foreach ( $section_labels as $section => $label ) {
			if ( ! isset( $sections[ $section ] ) ) {
				continue;
			}
			foreach ( $sections[ $section ] as $slug => $def ) {
				$headers[] = $slug;

				$note = $def['label'];
				if ( ! empty( $def['note'] ) ) {
					$note .= ' — ' . $def['note'];
				}

				// Append valid values for constrained fields
				$valid = LCUI_Field_Registry::get_valid_values( $slug );
				if ( ! empty( $valid ) ) {
					$note .= ' — Valid: ' . implode( ' | ', array_slice( $valid, 0, 15 ) );
					if ( count( $valid ) > 15 ) {
						$note .= ' (+ ' . ( count( $valid ) - 15 ) . ' more)';
					}
				}

				$notes[] = $note;
			}
		}

		// Build CSV: row 1 = headers, row 2 = notes (comment row), row 3 = empty example
		$lines   = [];
		$lines[] = implode( ',', array_map( [ self::class, 'csv_cell' ], $headers ) );
		$lines[] = implode( ',', array_map( [ self::class, 'csv_cell' ], $notes ) );
		$lines[] = implode( ',', array_fill( 0, count( $headers ), '' ) );

		// UTF-8 BOM for Excel
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	private static function csv_cell( string $value ): string {
		$value = str_replace( '"', '""', $value );
		return '"' . $value . '"';
	}
}
