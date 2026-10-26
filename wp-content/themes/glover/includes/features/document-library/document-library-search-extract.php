<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Text extraction for full-text search. Reads a file and returns its text in
 * parts: one per PDF page, one per spreadsheet sheet, or one for a Word file
 * (Word has no fixed pages, so no page numbers are claimed for it).
 *
 * PDFs use poppler's pdftotext when the host can run it, and otherwise the
 * bundled smalot/pdfparser (includes/vendor/smalot-pdfparser/).
 */

// Bump when extraction changes in a way that should re-index everything.
const GLOVER_DOCUMENT_SEARCH_EXTRACTOR_VERSION = '1';

// The pure-PHP parser holds the whole file in memory, so very large files are skipped.
const GLOVER_DOCUMENT_SEARCH_MAX_PARSER_BYTES = 30 * MB_IN_BYTES;
const GLOVER_DOCUMENT_SEARCH_MAX_PAGES = 1000;

spl_autoload_register(function ($class) {
    if (strpos($class, 'Smalot\\PdfParser\\') !== 0) {
        return;
    }

    $file = get_template_directory() . '/includes/vendor/smalot-pdfparser/src/' . str_replace('\\', '/', $class) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

/**
 * Path to a runnable pdftotext, or '' when the host can't run it.
 */
function glover_document_search_pdftotext_path()
{
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    $path = '';
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
        return $path;
    }

    $candidates = apply_filters('glover_document_search/pdftotext_paths', array(
        '/usr/bin/pdftotext',
        '/usr/local/bin/pdftotext',
        '/opt/homebrew/bin/pdftotext',
    ));

    foreach ((array) $candidates as $candidate) {
        if (@is_file($candidate) && @is_executable($candidate)) {
            $path = $candidate;
            break;
        }
    }

    return $path;
}

/**
 * Extract a file's text.
 *
 * @param string $file Absolute path.
 * @return array{method:string,parts:array<int,array{label:string,text:string}>}|WP_Error
 */
function glover_document_search_extract($file)
{
    if (!$file || !is_readable($file)) {
        return new WP_Error('glover_search_missing', 'The file is missing.');
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    wp_raise_memory_limit('glover_document_search');

    try {
        switch ($ext) {
            case 'pdf':
                $result = glover_document_search_pdftotext_path()
                    ? glover_document_search_extract_pdftotext($file)
                    : glover_document_search_extract_pdfparser($file);
                break;

            case 'docx':
                $result = glover_document_search_extract_docx($file);
                break;

            case 'xlsx':
                $result = glover_document_search_extract_xlsx($file);
                break;

            default:
                return new WP_Error('glover_search_type', 'This file type is not searched.');
        }
    } catch (Throwable $error) {
        return new WP_Error('glover_search_failed', 'The file could not be read: ' . $error->getMessage());
    }

    if (is_wp_error($result)) {
        return $result;
    }

    foreach ($result['parts'] as $index => $part) {
        $result['parts'][$index]['text'] = glover_document_search_clean_text($part['text']);
    }

    return $result;
}

/**
 * Valid UTF-8 with whitespace collapsed to single spaces.
 *
 * @param string $text Raw text.
 */
function glover_document_search_clean_text($text)
{
    $text = (string) $text;

    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    // Soft hyphens and zero-width characters break words apart.
    $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim((string) $text);
}

/**
 * PDF through poppler's pdftotext. Pages are separated by form feeds.
 *
 * @param string $file PDF path.
 */
function glover_document_search_extract_pdftotext($file)
{
    $command = array(glover_document_search_pdftotext_path(), '-enc', 'UTF-8', '-q', $file, '-');
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

    if (!is_resource($process)) {
        return new WP_Error('glover_search_failed', 'pdftotext could not be started.');
    }

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($code !== 0) {
        return new WP_Error('glover_search_failed', trim('pdftotext could not read the file. ' . $errors));
    }

    $pages = explode("\f", (string) $output);

    // pdftotext ends the last page with a form feed too.
    if (count($pages) > 1 && trim(end($pages)) === '') {
        array_pop($pages);
    }

    $parts = array();

    foreach (array_slice($pages, 0, GLOVER_DOCUMENT_SEARCH_MAX_PAGES) as $index => $text) {
        $parts[] = array('label' => 'Page ' . ($index + 1), 'text' => $text);
    }

    return array('method' => 'pdftotext', 'parts' => $parts);
}

/**
 * PDF through the bundled smalot/pdfparser.
 *
 * @param string $file PDF path.
 */
function glover_document_search_extract_pdfparser($file)
{
    if (filesize($file) > GLOVER_DOCUMENT_SEARCH_MAX_PARSER_BYTES) {
        return new WP_Error('glover_search_too_large', sprintf('The file is larger than %s, too large to read on this server.', size_format(GLOVER_DOCUMENT_SEARCH_MAX_PARSER_BYTES)));
    }

    $config = new \Smalot\PdfParser\Config();
    $config->setRetainImageContent(false);
    $config->setIgnoreEncryption(true);

    $parser = new \Smalot\PdfParser\Parser(array(), $config);
    $pdf = $parser->parseFile($file);
    $parts = array();

    foreach (array_slice($pdf->getPages(), 0, GLOVER_DOCUMENT_SEARCH_MAX_PAGES) as $index => $page) {
        $parts[] = array('label' => 'Page ' . ($index + 1), 'text' => $page->getText());
    }

    return array('method' => 'pdfparser', 'parts' => $parts);
}

/**
 * Read one file from inside a zip (DOCX and XLSX are zip packages).
 *
 * @param ZipArchive $zip  Open archive.
 * @param string     $name Entry name.
 * @return SimpleXMLElement|null Compare with null: an element whose
 *                               children are all namespaced casts to false.
 */
function glover_document_search_zip_xml($zip, $name)
{
    $xml = $zip->getFromName($name);

    if ($xml === false || $xml === '') {
        return null;
    }

    $previous = libxml_use_internal_errors(true);
    $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $element instanceof SimpleXMLElement ? $element : null;
}

/**
 * Open a DOCX or XLSX package.
 *
 * @param string $file Path.
 * @return ZipArchive|WP_Error
 */
function glover_document_search_open_zip($file)
{
    if (!class_exists('ZipArchive')) {
        return new WP_Error('glover_search_failed', 'PHP\'s zip extension is not installed.');
    }

    $zip = new ZipArchive();

    if ($zip->open($file) !== true) {
        return new WP_Error('glover_search_failed', 'The file is not a valid Office document.');
    }

    return $zip;
}

/**
 * Word: every paragraph's text, in order, as one part.
 *
 * @param string $file DOCX path.
 */
function glover_document_search_extract_docx($file)
{
    $zip = glover_document_search_open_zip($file);

    if (is_wp_error($zip)) {
        return $zip;
    }

    $texts = array();

    // The body, then headers, footers, footnotes and endnotes.
    $names = array('word/document.xml');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (preg_match('#^word/(header|footer)\d*\.xml$|^word/(footnotes|endnotes)\.xml$#', $name)) {
            $names[] = $name;
        }
    }

    foreach ($names as $name) {
        $xml = glover_document_search_zip_xml($zip, $name);

        if ($xml === null) {
            continue;
        }

        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        foreach ($xml->xpath('//w:p') as $paragraph) {
            $paragraph->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $runs = array();

            foreach ($paragraph->xpath('.//w:t|.//w:tab|.//w:br') as $node) {
                $runs[] = $node->getName() === 't' ? (string) $node : ' ';
            }

            $texts[] = implode('', $runs);
        }
    }

    $zip->close();

    return array('method' => 'docx', 'parts' => array(array('label' => '', 'text' => implode("\n", $texts))));
}

/**
 * Excel: one part per sheet, labeled with the sheet name. Cells are joined
 * with " · " and rows with new lines. Numbers keep their stored values.
 *
 * @param string $file XLSX path.
 */
function glover_document_search_extract_xlsx($file)
{
    $zip = glover_document_search_open_zip($file);

    if (is_wp_error($zip)) {
        return $zip;
    }

    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $strings = array();
    $shared = glover_document_search_zip_xml($zip, 'xl/sharedStrings.xml');

    if ($shared !== null) {
        foreach ($shared->children($main)->si as $item) {
            $strings[] = glover_document_search_xlsx_string($item, $main);
        }
    }

    $targets = array();
    $rels = glover_document_search_zip_xml($zip, 'xl/_rels/workbook.xml.rels');

    if ($rels !== null) {
        foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $rel) {
            // Plain attributes need attributes(): after children($ns), $el['x'] looks in that namespace.
            $attributes = $rel->attributes();
            $target = ltrim((string) $attributes->Target, '/');
            $targets[(string) $attributes->Id] = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
        }
    }

    $parts = array();
    $workbook = glover_document_search_zip_xml($zip, 'xl/workbook.xml');

    if ($workbook !== null) {
        foreach ($workbook->children($main)->sheets->sheet as $sheet) {
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;

            if (!isset($targets[$id])) {
                continue;
            }

            $xml = glover_document_search_zip_xml($zip, $targets[$id]);

            if ($xml === null) {
                continue;
            }

            $rows = array();

            foreach ($xml->children($main)->sheetData->row as $row) {
                $cells = array();

                foreach ($row->children($main)->c as $cell) {
                    $type = (string) $cell->attributes()->t;
                    $children = $cell->children($main);

                    if ($type === 's') {
                        $index = (int) $children->v;
                        $value = isset($strings[$index]) ? $strings[$index] : '';
                    } elseif ($type === 'inlineStr') {
                        $value = glover_document_search_xlsx_string($children->is, $main);
                    } else {
                        $value = (string) $children->v;
                    }

                    if (trim($value) !== '') {
                        $cells[] = trim($value);
                    }
                }

                if ($cells) {
                    $rows[] = implode(' · ', $cells);
                }
            }

            $parts[] = array('label' => (string) $sheet->attributes()->name, 'text' => implode("\n", $rows));
        }
    }

    $zip->close();

    return array('method' => 'xlsx', 'parts' => $parts);
}

/**
 * Text of a shared or inline string, including rich-text runs.
 *
 * @param SimpleXMLElement|null $item <si> or <is>.
 * @param string                $main Spreadsheet namespace.
 */
function glover_document_search_xlsx_string($item, $main)
{
    if (!$item) {
        return '';
    }

    $children = $item->children($main);

    if (isset($children->t)) {
        return (string) $children->t;
    }

    $text = '';

    foreach ($children->r as $run) {
        $text .= (string) $run->children($main)->t;
    }

    return $text;
}

/**
 * Whether a PDF has redaction marks that were never applied. Acrobat's
 * "mark for redaction" leaves the text in place until Apply is clicked.
 *
 * @param string $file PDF path.
 */
function glover_document_search_has_unapplied_redactions($file)
{
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'pdf' || !is_readable($file)) {
        return false;
    }

    $handle = fopen($file, 'rb');

    if (!$handle) {
        return false;
    }

    $found = false;
    $carry = '';

    while (!feof($handle)) {
        $chunk = $carry . fread($handle, 1024 * 1024);

        if (preg_match('#/Subtype\s*/Redact\b#', $chunk)) {
            $found = true;
            break;
        }

        $carry = substr($chunk, -32);
    }

    fclose($handle);

    return $found;
}
