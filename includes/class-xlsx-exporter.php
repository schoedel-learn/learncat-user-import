<?php
/**
 * LCUI_XLSX_Exporter
 *
 * Generates an .xlsx template file with dropdown validation for constrained
 * fields using PhpSpreadsheet. The template guides the admin through filling
 * in valid data before exporting to CSV for import.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\SheetView;

class LCUI_XLSX_Exporter {

	/**
	 * Generate the XLSX template and stream it to the browser.
	 */
	public static function export(): void {
		$spreadsheet = new Spreadsheet();

		// Gather field data
		$sections = LCUI_Field_Registry::sections();
		$section_order = [ 'wp_core', 'buddyboss', 'woocommerce', 'learndash' ];

		$fields = [];
		foreach ( $section_order as $section ) {
			if ( ! isset( $sections[ $section ] ) ) {
				continue;
			}
			foreach ( $sections[ $section ] as $slug => $def ) {
				$fields[] = [
					'slug'  => $slug,
					'def'   => $def,
					'values' => LCUI_Field_Registry::get_valid_values( $slug ),
				];
			}
		}

		// ── Sheet 2: Options (Do Not Edit) — build first so we can reference it ──
		$options_sheet = $spreadsheet->createSheet( 1 );
		$options_sheet->setTitle( 'Options (Do Not Edit)' );
		$options_sheet->setSheetState( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN );

		// Map: column index on Import Data → Options sheet column letter + row count
		$options_map = [];
		$opt_col_index = 0;

		foreach ( $fields as $i => $field ) {
			$values = $field['values'];
			if ( empty( $values ) ) {
				continue;
			}

			// Only use the Options sheet for fields with more than a handful of values
			// yes/no fields use inline validation instead
			if ( count( $values ) <= 3 ) {
				continue;
			}

			$opt_col_index++;
			$opt_letter = Coordinate::stringFromColumnIndex( $opt_col_index );

			// Header row
			$options_sheet->setCellValue( $opt_letter . '1', $field['slug'] );
			$options_sheet->getStyle( $opt_letter . '1' )->getFont()->setBold( true );

			// Values
			foreach ( $values as $row_idx => $val ) {
				$options_sheet->setCellValue( $opt_letter . ( $row_idx + 2 ), $val );
			}

			$options_map[ $i ] = [
				'letter'    => $opt_letter,
				'row_count' => count( $values ),
			];
		}

		// ── Sheet 1: Import Data ──────────────────────────────────────────────
		$data_sheet = $spreadsheet->getSheet( 0 );
		$data_sheet->setTitle( 'Import Data' );

		// Header style
		$header_fill  = [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'rgb' => '4472C4' ] ];
		$header_font  = [ 'bold' => true, 'color' => [ 'rgb' => 'FFFFFF' ] ];
		$desc_fill    = [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'rgb' => 'F2F2F2' ] ];
		$desc_font    = [ 'italic' => true, 'color' => [ 'rgb' => '808080' ] ];

		$data_rows = 200; // Empty data rows for the template

		foreach ( $fields as $col_idx => $field ) {
			$col_letter = Coordinate::stringFromColumnIndex( $col_idx + 1 );
			$slug  = $field['slug'];
			$def   = $field['def'];
			$values = $field['values'];

			// Row 1: Header
			$data_sheet->setCellValue( $col_letter . '1', $slug );
			$data_sheet->getStyle( $col_letter . '1' )->getFill()->applyFromArray( $header_fill );
			$data_sheet->getStyle( $col_letter . '1' )->getFont()->applyFromArray( $header_font );

			// Row 2: Description
			$note = $def['label'];
			if ( ! empty( $def['note'] ) ) {
				$note .= ' — ' . $def['note'];
			}
			if ( ! empty( $values ) ) {
				$note .= ' | Valid: ' . implode( ', ', array_slice( $values, 0, 10 ) );
				if ( count( $values ) > 10 ) {
					$note .= ' (+ ' . ( count( $values ) - 10 ) . ' more)';
				}
			}
			$data_sheet->setCellValue( $col_letter . '2', $note );
			$data_sheet->getStyle( $col_letter . '2' )->getFill()->applyFromArray( $desc_fill );
			$data_sheet->getStyle( $col_letter . '2' )->getFont()->applyFromArray( $desc_font );

			// Auto-size column width
			$data_sheet->getColumnDimension( $col_letter )->setAutoSize( true );

			// Data validation for constrained columns (rows 3+)
			if ( ! empty( $values ) ) {
				for ( $row = 3; $row <= $data_rows + 2; $row++ ) {
					$validation = $data_sheet->getCell( $col_letter . $row )->getDataValidation();
					$validation->setType( DataValidation::TYPE_LIST );
					$validation->setErrorStyle( DataValidation::STYLE_WARNING );
					$validation->setAllowBlank( true );
					$validation->setShowDropDown( true );
					$validation->setShowErrorMessage( true );
					$validation->setErrorTitle( 'Not recognized' );
					$validation->setError( 'This value isn\'t in the list of valid options. Please choose from the dropdown.' );
					$validation->setShowInputMessage( true );
					$validation->setPromptTitle( $def['label'] );
					$validation->setPrompt( 'Choose from the dropdown or leave blank.' );

					// For small lists (<=3 values): inline formula
					if ( count( $values ) <= 3 ) {
						$validation->setFormula1( '"' . implode( ',', $values ) . '"' );
					} else {
						// Reference the Options sheet
						$opt_info = $options_map[ $col_idx ];
						$opt_letter = $opt_info['letter'];
						$opt_rows   = $opt_info['row_count'];
						$validation->setFormula1( "'Options (Do Not Edit)'!\${$opt_letter}\$2:\${$opt_letter}\$" . ( $opt_rows + 1 ) );
					}
				}
			}
		}

		// Freeze row 1
		$data_sheet->freezePane( 'A2' );

		// ── Sheet 3: Instructions ─────────────────────────────────────────────
		$instructions_sheet = $spreadsheet->createSheet( 2 );
		$instructions_sheet->setTitle( 'Instructions' );

		$instructions = [
			'How to Use This Spreadsheet Template',
			'',
			'1. Fill in the "Import Data" tab starting from Row 3.',
			'2. Row 2 shows what each column means — do not delete it.',
			'3. For columns with a dropdown arrow, click the cell and choose from the list.',
			'4. Columns you don\'t need can be left blank.',
			'5. When done: File → Download → Comma Separated Values (.csv), then upload that file on the Bulk Import page.',
			'6. Do NOT import the .xlsx file itself — export to CSV first.',
			'',
			'Tips:',
			'- The "user_login" and "user_email" columns are required for every row.',
			'- To assign multiple roles or member types, separate them with a pipe character: |',
			'- For enrollment columns (ld_enroll_*), use "yes" to enroll or leave blank to skip.',
			'- If you leave the password column blank, a random password will be generated.',
			'- The description row (Row 2) will be ignored during import — you can leave it in place.',
		];

		// Title style
		$instructions_sheet->setCellValue( 'A1', $instructions[0] );
		$instructions_sheet->getStyle( 'A1' )->getFont()->setBold( true )->setSize( 14 );

		foreach ( $instructions as $row_idx => $text ) {
			if ( $row_idx === 0 ) {
				continue; // Already set
			}
			$instructions_sheet->setCellValue( 'A' . ( $row_idx + 1 ), $text );
		}

		$instructions_sheet->getColumnDimension( 'A' )->setWidth( 100 );

		// Set active sheet to Import Data
		$spreadsheet->setActiveSheetIndex( 0 );

		// ── Write to output ───────────────────────────────────────────────────
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="learncat-user-import-template.xlsx"' );
		header( 'Cache-Control: max-age=0' );
		header( 'Pragma: no-cache' );

		$writer = new Xlsx( $spreadsheet );
		$writer->save( 'php://output' );
		$spreadsheet->disconnectWorksheets();
		unset( $spreadsheet );
	}
}
