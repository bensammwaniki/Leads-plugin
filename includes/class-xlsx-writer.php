<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_XLSX_Writer {
    private $sheet_name = 'Sheet1';
    private $headers = array();
    private $rows = array();
    private $col_types = array();      // 'text', 'price', 'score'
    private $col_alignments = array(); // 'left', 'center', 'right'

    /**
     * Constructor.
     *
     * @param string $sheet_name Name of the sheet.
     */
    public function __construct($sheet_name = 'Sheet1') {
        // Clean sheet name of invalid characters: \ / ? * : [ ]
        $clean_name = preg_replace('/[*?:\[\]\/\\\\]/', '', $sheet_name);
        $this->sheet_name = substr(!empty($clean_name) ? $clean_name : 'Sheet1', 0, 31);
    }

    /**
     * Set the headers for the sheet.
     *
     * @param array $headers Array of string headers.
     */
    public function set_headers($headers) {
        $this->headers = $headers;
    }

    /**
     * Set the rows for the sheet.
     *
     * @param array $rows Array of arrays of cell values.
     */
    public function set_rows($rows) {
        $this->rows = $rows;
    }

    /**
     * Set the column types.
     *
     * @param array $col_types Array of types ('text', 'price', 'score').
     */
    public function set_col_types($col_types) {
        $this->col_types = $col_types;
    }

    /**
     * Set the column alignments.
     *
     * @param array $alignments Array of alignments ('left', 'center', 'right').
     */
    public function set_col_alignments($alignments) {
        $this->col_alignments = $alignments;
    }

    /**
     * Helper to convert 0-indexed column number to Excel letter.
     *
     * @param int $col_idx Column index.
     * @return string Excel column letter.
     */
    private function get_col_letter($col_idx) {
        $letter = '';
        while ($col_idx >= 0) {
            $letter = chr(($col_idx % 26) + 65) . $letter;
            $col_idx = intval($col_idx / 26) - 1;
        }
        return $letter;
    }

    /**
     * Clean and format values for XML.
     *
     * @param string $str String value to escape.
     * @return string Escaped string.
     */
    private function escape_xml($str) {
        // Strip control characters that are invalid in XML (except tab, newline, carriage return)
        $str = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$str);
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Write output directly to browser as XLSX download.
     *
     * @param string $filename Downloadable file name.
     */
    public function write_to_output($filename) {
        if (!class_exists('ZipArchive')) {
            wp_die(esc_html__('PHP ZipArchive extension is required for Excel export.', 'mc-leads-engine'));
        }

        // Create temporary file path
        $temp_file = tempnam(sys_get_temp_dir(), 'xlsx_');
        if (!$temp_file) {
            wp_die(esc_html__('Unable to create temporary file for export.', 'mc-leads-engine'));
        }

        $zip = new ZipArchive();
        if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(esc_html__('Failed to initialize ZipArchive.', 'mc-leads-engine'));
        }

        // Build folder structure contents
        $content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n" .
            '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n" .
            '  <Default Extension="xml" ContentType="application/xml"/>' . "\n" .
            '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n" .
            '  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . "\n" .
            '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n" .
            '</Types>';

        $rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
            '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n" .
            '</Relationships>';

        $workbook_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
            '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' . "\n" .
            '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' . "\n" .
            '</Relationships>';

        $escaped_sheet_name = $this->escape_xml($this->sheet_name);
        $workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n" .
            '  <sheets>' . "\n" .
            '    <sheet name="' . $escaped_sheet_name . '" sheetId="1" r:id="rId1"/>' . "\n" .
            '  </sheets>' . "\n" .
            '</workbook>';

        // Main style sheet setting Segoe UI, blue headers, zebra rows, borders, and score colors
        // Note: Excel colors are AARRGGBB, requiring 8 hex characters.
        $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n" .
            '  <fonts count="4">' . "\n" .
            '    <font><sz val="11"/><name val="Segoe UI"/><family val="2"/></font>' . "\n" .
            '    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/><family val="2"/></font>' . "\n" .
            '    <font><b/><sz val="11"/><color rgb="FF15803D"/><name val="Segoe UI"/><family val="2"/></font>' . "\n" .
            '    <font><b/><sz val="11"/><color rgb="FFB91C1C"/><name val="Segoe UI"/><family val="2"/></font>' . "\n" .
            '  </fonts>' . "\n" .
            '  <fills count="6">' . "\n" .
            '    <fill><patternFill patternType="none"/></fill>' . "\n" .
            '    <fill><patternFill patternType="gray125"/></fill>' . "\n" .
            '    <fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/><bgColor indexed="64"/></patternFill></fill>' . "\n" . // Dark Blue Headers
            '    <fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>' . "\n" . // Zebra alternating row
            '    <fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>' . "\n" . // High score green
            '    <fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>' . "\n" . // Low score red
            '  </fills>' . "\n" .
            '  <borders count="2">' . "\n" .
            '    <border><left/><right/><top/><bottom/></border>' . "\n" .
            '    <border>' . "\n" .
            '      <left style="thin"><color rgb="FFD1D5DB"/></left>' . "\n" .
            '      <right style="thin"><color rgb="FFD1D5DB"/></right>' . "\n" .
            '      <top style="thin"><color rgb="FFD1D5DB"/></top>' . "\n" .
            '      <bottom style="thin"><color rgb="FFD1D5DB"/></bottom>' . "\n" .
            '    </border>' . "\n" .
            '  </borders>' . "\n" .
            '  <cellStyleXfs count="1">' . "\n" .
            '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . "\n" .
            '  </cellStyleXfs>' . "\n" .
            '  <cellXfs count="9">' . "\n" .
            '    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>' . "\n" . // 0: Default Left Text
            '    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' . "\n" . // 1: Header (Bold White, Dark Blue)
            '    <xf numFmtId="0" fontId="0" fillId="3" borderId="1" applyFill="1" applyBorder="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>' . "\n" . // 2: Zebra Left Text
            '    <xf numFmtId="0" fontId="2" fillId="4" borderId="1" applyFont="1" applyFill="1" applyBorder="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 3: High score green
            '    <xf numFmtId="0" fontId="3" fillId="5" borderId="1" applyFont="1" applyFill="1" applyBorder="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 4: Low score red
            '    <xf numFmtId="4" fontId="0" fillId="0" borderId="1" applyBorder="1" xfId="0"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 5: Price (Right, Decimals)
            '    <xf numFmtId="4" fontId="0" fillId="3" borderId="1" applyFill="1" applyBorder="1" xfId="0"><alignment horizontal="right" vertical="center"/></xf>' . "\n" . // 6: Price Zebra (Right, Decimals)
            '    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 7: Centered
            '    <xf numFmtId="0" fontId="0" fillId="3" borderId="1" applyFill="1" applyBorder="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>' . "\n" . // 8: Centered Zebra
            '  </cellXfs>' . "\n" .
            '</styleSheet>';

        // Calculate dynamic column widths based on maximum contents lengths
        $col_widths = array();
        foreach ($this->headers as $col_idx => $header) {
            $col_widths[$col_idx] = strlen($header);
        }
        foreach ($this->rows as $row) {
            foreach ($row as $col_idx => $val) {
                $str_val = is_array($val) ? implode(', ', $val) : (string) $val;
                // Get maximum line width in case of line breaks
                $lines = explode("\n", $str_val);
                $max_line_len = 0;
                foreach ($lines as $line) {
                    $max_line_len = max($max_line_len, strlen($line));
                }
                $col_widths[$col_idx] = max($col_widths[$col_idx] ?? 0, $max_line_len);
            }
        }

        $cols_xml = '';
        if (!empty($col_widths)) {
            $cols_xml .= '  <cols>' . "\n";
            foreach ($col_widths as $col_idx => $width) {
                $col_num = $col_idx + 1;
                // Cap column width between 10 and 60 for presentation, adding padding
                $final_width = max(10, min(60, $width + 4));
                $cols_xml .= '    <col min="' . $col_num . '" max="' . $col_num . '" width="' . $final_width . '" customWidth="1"/>' . "\n";
            }
            $cols_xml .= '  </cols>' . "\n";
        }

        // Build worksheet rows XML
        $sheet_data_xml = '  <sheetData>' . "\n";

        // Write header row (Height = 30pt)
        $row_num = 1;
        $sheet_data_xml .= '    <row r="' . $row_num . '" spans="1:' . count($this->headers) . '" ht="30" customHeight="1">' . "\n";
        foreach ($this->headers as $col_idx => $header) {
            $ref = $this->get_col_letter($col_idx) . $row_num;
            $escaped = $this->escape_xml($header);
            $sheet_data_xml .= '      <c r="' . $ref . '" s="1" t="inlineStr"><is><t>' . $escaped . '</t></is></c>' . "\n";
        }
        $sheet_data_xml .= '    </row>' . "\n";

        // Write data rows
        foreach ($this->rows as $r_idx => $row) {
            $row_num = $r_idx + 2;
            $is_even = ($row_num % 2 === 0);

            // Compute row height depending on long wrapped strings (e.g. 15pt per line + padding)
            $max_lines = 1;
            foreach ($row as $val) {
                $str_val = is_array($val) ? implode(', ', $val) : (string) $val;
                $max_lines = max($max_lines, count(explode("\n", $str_val)));
            }
            $row_height = max(22, $max_lines * 15 + 6);

            $sheet_data_xml .= '    <row r="' . $row_num . '" spans="1:' . count($row) . '" ht="' . $row_height . '" customHeight="1">' . "\n";

            foreach ($row as $col_idx => $val) {
                $ref = $this->get_col_letter($col_idx) . $row_num;
                $col_type = $this->col_types[$col_idx] ?? 'text';
                $alignment = $this->col_alignments[$col_idx] ?? 'left';

                // Default styles (alternate zebra striping)
                $style_idx = $is_even ? 2 : 0;

                if ($col_type === 'score') {
                    $score_val = (int) $val;
                    if ($score_val >= 80) {
                        $style_idx = 3; // Green highlight
                    } elseif ($score_val < 40 && $score_val > 0) {
                        $style_idx = 4; // Red highlight
                    } else {
                        $style_idx = $is_even ? 8 : 7; // Centered
                    }
                } elseif ($col_type === 'price') {
                    $style_idx = $is_even ? 6 : 5; // Right price
                } elseif ($alignment === 'center') {
                    $style_idx = $is_even ? 8 : 7; // Centered text
                } elseif ($alignment === 'right') {
                    $style_idx = $is_even ? 6 : 5; // Right text
                }

                if (is_numeric($val) && $col_type !== 'text') {
                    // Output numeric tags for Excel numerical support
                    $sheet_data_xml .= '      <c r="' . $ref . '" s="' . $style_idx . '"><v>' . $val . '</v></c>' . "\n";
                } else {
                    // Output inlineStr tags for text
                    $str_val = is_array($val) ? implode(', ', $val) : (string) $val;
                    $escaped = $this->escape_xml($str_val);
                    $sheet_data_xml .= '      <c r="' . $ref . '" s="' . $style_idx . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>' . "\n";
                }
            }
            $sheet_data_xml .= '    </row>' . "\n";
        }

        $sheet_data_xml .= '  </sheetData>';

        $sheet1_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n" .
            '  <sheetViews>' . "\n" .
            '    <sheetView tabSelected="1" workbookViewId="0"/>' . "\n" .
            '  </sheetViews>' . "\n" .
            '  <sheetFormatPr defaultRowHeight="20" customHeight="1"/>' . "\n" .
            $cols_xml .
            $sheet_data_xml . "\n" .
            '</worksheet>';

        // Add generated XML buffers into the zip archive
        $zip->addFromString('[Content_Types].xml', $content_types_xml);
        $zip->addFromString('_rels/.rels', $rels_xml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels_xml);
        $zip->addFromString('xl/workbook.xml', $workbook_xml);
        $zip->addFromString('xl/styles.xml', $styles_xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1_xml);

        $zip->close();

        // Clean any output buffers to prevent PHP notices/warnings or HTML markup from prepending/corrupting the file
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Send headers and stream file
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($temp_file));
        readfile($temp_file);
        unlink($temp_file);
        exit;
    }
}
