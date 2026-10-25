<?php
/**
 * Sample documents for the Document Library, for building and testing the
 * site locally. Not for production.
 *
 *   wp eval-file tools/seed-documents.php          add the sample set
 *   wp eval-file tools/seed-documents.php remove   remove it again
 *
 * Titles follow the submission form's guidance: what the document is, with
 * no board or date, since those are their own fields.
 *
 * Each document gets a small file, mostly text PDFs (so full-text search
 * has something to read) with some Word, Excel and JPEG files so every
 * file-type label shows, plus boards, a category, topics and a date.
 * Seeded documents carry the `_glover_seed` meta, which is how `remove` finds them; nothing else is
 * touched. While it runs, Google Drive backups and all email are switched
 * off, and documents are published as user 2 so no "published" notice goes
 * out. Run `wp cron event run --due-now` afterwards to index them for search.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_SEED_META = '_glover_seed';

wp_set_current_user(2);
add_filter('pre_wp_mail', '__return_false', 999);
add_filter('glover_document_library/backup_handlers', '__return_empty_array', 999);
remove_all_actions('glover_document_library/backup_requested');

// ------------------------------------------------------------------ remove

if (isset($args[0]) && $args[0] === 'remove') {
    $ids = get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => array('publish', 'pending', 'draft', 'private', 'trash'),
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => GLOVER_SEED_META,
    ));

    foreach ($ids as $id) {
        $files = array((int) get_post_meta($id, 'document_file', true));

        foreach (glover_document_library_meta_list($id, '_glover_previous_files') as $version) {
            $files[] = (int) $version['attachment'];
        }

        foreach (array_filter($files) as $file) {
            wp_delete_attachment($file, true);
        }

        wp_delete_post($id, true);
    }

    WP_CLI::success(sprintf('Removed %d sample documents.', count($ids)));
    return;
}

// --------------------------------------------------------------- the data

/*
 * Title, board(s), category slug, topic slugs, date (Y-m-d), extra: status
 * ('pending' to leave in the review queue), minutes ('draft' or 'approved'),
 * a line of body text for search, and type ('docx', 'xlsx' or 'jpg'; PDF
 * when left out), so every file-type label shows.
 */
$documents = array(
    array('Regular Meeting Minutes', array('Select Board'), 'minutes', array('roads-bridges'), '2024-01-08', array('minutes' => 'approved', 'text' => 'Discussion of winter road maintenance, sand and salt supply, and the plow route on Shadow Lake Road.')),
    array('Regular Meeting Minutes', array('Select Board'), 'minutes', array('town-meeting-elections'), '2024-02-12', array('minutes' => 'approved', 'text' => 'Final review of the warning for Town Meeting and the proposed budget articles.')),
    array('Regular Meeting Minutes', array('Select Board'), 'minutes', array('grants', 'roads-bridges'), '2025-09-09', array('minutes' => 'approved', 'text' => 'Culvert replacement grant awarded by VTrans. Bids for the Bean Hill Road culvert due October 1.')),
    array('Regular Meeting Minutes', array('Select Board'), 'minutes', array('property-taxes'), '2026-10-14', array('minutes' => 'draft', 'text' => 'Tax rate set for the year. Listers reported on the reappraisal schedule.')),
    array('Regular Meeting Agenda', array('Select Board'), 'agendas', array('roads-bridges', 'emergency-management'), '2026-10-28', array('text' => 'Call to order. Road foreman report. Emergency management plan update. Public comment.')),
    array('Budget Work Session Agenda', array('Select Board'), 'agendas', array(), '2026-11-11', array('status' => 'pending', 'text' => 'Call to order. Budget work session for the coming fiscal year.')),
    array('Regular Meeting Minutes', array('Planning Commission'), 'minutes', array('land-use-zoning'), '2023-05-02', array('minutes' => 'approved', 'text' => 'Review of the draft Town Plan land use chapter and future land use map.')),
    array('Public Hearing Agenda: Zoning Amendments', array('Planning Commission'), 'agendas', array('land-use-zoning'), '2026-12-01', array('text' => 'Public hearing on proposed amendments to the zoning bylaws.')),
    array('2023 Town Plan', array('Planning Commission', 'Select Board'), 'plans-studies', array('land-use-zoning', 'energy-weatherization'), '2023-08-15', array('text' => 'The Town Plan sets goals for housing, land use, energy, transportation and natural resources in Glover.')),
    array('Zoning and Subdivision Bylaws', array('Planning Commission'), 'bylaws', array('land-use-zoning'), '2021-04-20', array('text' => 'Districts, permitted and conditional uses, setbacks, and the subdivision review process.')),
    array('Local Hazard Mitigation Plan', array('Select Board'), 'plans-studies', array('emergency-management'), '2022-06-30', array('text' => 'Flooding along the Barton River and winter storms are the town\'s most significant hazards.')),
    array('Local Emergency Management Plan', array('Select Board', 'Fire Department'), 'plans-studies', array('emergency-management', 'fire-ambulance'), '2026-05-01', array('text' => 'Emergency shelter at the Town Hall. Contact list for the emergency management director.')),
    array('2024 Annual Town Report', array('Town Clerk & Treasurer'), 'reports', array('town-meeting-elections', 'property-taxes'), '2025-02-01', array('text' => 'Reports from town officers, the auditors, and every department and board for the year.')),
    array('2025 Annual Town Report', array('Town Clerk & Treasurer'), 'reports', array('town-meeting-elections', 'property-taxes'), '2026-02-01', array('text' => 'Reports from town officers, the auditors, and every department and board for the year.')),
    array('Fiscal Year 2026 Budget', array('Select Board'), 'budgets-finance', array('property-taxes'), '2025-03-03', array('type' => 'xlsx', 'text' => 'Highway, general fund and capital reserve appropriations approved at Town Meeting.')),
    array('Fiscal Year 2027 Proposed Budget', array('Select Board'), 'budgets-finance', array('property-taxes'), '2026-12-15', array('status' => 'pending', 'text' => 'Draft budget for review before Town Meeting.')),
    array('Fiscal Year 2025 Audit', array('Select Board', 'Town Clerk & Treasurer'), 'budgets-finance', array(), '2025-11-20', array('text' => 'The auditors found the financial statements fairly presented.')),
    array('2026 Grand List and Tax Rates', array('Listers'), 'budgets-finance', array('property-taxes'), '2026-07-10', array('type' => 'xlsx', 'text' => 'Municipal and education tax rates per hundred dollars of grand list value.')),
    array('Annual Town Meeting Warning', array('Select Board', 'Town Clerk & Treasurer'), 'notices-warnings', array('town-meeting-elections'), '2026-01-26', array('text' => 'The legal voters of the Town of Glover are hereby warned to meet at the Town Hall.')),
    array('Public Hearing Notice: Zoning Amendments', array('Planning Commission'), 'notices-warnings', array('land-use-zoning'), '2026-11-10', array('text' => 'A public hearing will be held on proposed amendments to the zoning bylaws.')),
    array('Request for Bids: Bean Hill Road Culvert', array('Road Department', 'Select Board'), 'notices-warnings', array('roads-bridges', 'grants'), '2025-09-15', array('text' => 'Sealed bids for culvert replacement are due by October 1 at the Town Clerk\'s Office.')),
    array('General Election Sample Ballot', array('Town Clerk & Treasurer', 'Board of Civil Authorities'), 'notices-warnings', array('town-meeting-elections'), '2026-10-05', array('text' => 'Sample ballot for the general election. Polls open at 10 a.m. at the Town Hall.')),
    array('Dog Control Ordinance', array('Select Board'), 'ordinances', array('animal-control'), '2019-06-11', array('text' => 'Dogs must be licensed by April 1 each year and kept under control.')),
    array('Parking Ordinance', array('Select Board'), 'ordinances', array('roads-bridges'), '2020-11-02', array('text' => 'No parking on town roads during winter plowing from November 1 to April 15.')),
    array('Conflict of Interest Policy', array('Select Board'), 'policies', array(), '2022-01-10', array('type' => 'docx', 'text' => 'Town officials must disclose any conflict of interest and recuse themselves.')),
    array('Personnel Policy', array('Select Board', 'Town Administrator'), 'policies', array(), '2024-07-01', array('type' => 'docx', 'text' => 'Hiring, hours, leave and benefits for town employees.')),
    array('Recycling Center Rules and Fees', array('Recycling Center'), 'policies', array('recycling-solid-waste'), '2025-04-01', array('text' => 'Accepted materials, punch card fees, and hazardous waste collection days.')),
    array('Dog License Application', array('Town Clerk & Treasurer'), 'forms', array('animal-control'), '2026-01-02', array('type' => 'docx', 'text' => 'Dog name, breed, rabies vaccination certificate and spay or neuter status.')),
    array('Driveway Access Permit Application', array('Road Department'), 'forms', array('roads-bridges', 'land-use-zoning'), '2024-03-18', array('type' => 'docx', 'text' => 'Apply before building a new driveway onto a town road.')),
    array('Zoning Permit Application', array('Planning Commission'), 'forms', array('land-use-zoning'), '2023-09-05', array('type' => 'docx', 'text' => 'Required before construction, additions or a change of use.')),
    array('Town Road Map', array('Road Department'), 'maps', array('roads-bridges'), '2022-10-01', array('type' => 'jpg', 'text' => 'Class 2, 3 and 4 town roads and legal trails.')),
    array('Future Land Use Map', array('Planning Commission'), 'maps', array('land-use-zoning'), '2023-08-15', array('type' => 'jpg', 'text' => 'Village, rural residential and forest districts from the Town Plan.')),
    array('2024 Shadow Lake Water Quality Report', array('Select Board'), 'reports', array('lakes-water-quality'), '2024-12-01', array('text' => 'Phosphorus and clarity readings, and the milfoil survey on Shadow Lake and Lake Parker.')),
    array('Lake Parker Milfoil Management Plan', array('Select Board'), 'plans-studies', array('lakes-water-quality', 'grants'), '2025-05-20', array('text' => 'Hand pulling and diver-assisted suction harvesting for Eurasian watermilfoil.')),
    array('Regular Meeting Minutes', array('Energy Committee'), 'minutes', array('energy-weatherization'), '2025-04-17', array('minutes' => 'approved', 'text' => 'Weatherization workshop planned. Solar on the Town Garage roof discussed.')),
    array('Municipal Energy Plan', array('Energy Committee', 'Planning Commission'), 'plans-studies', array('energy-weatherization'), '2021-09-30', array('text' => 'Goals for efficiency, renewable generation and transportation energy use.')),
    array('Regular Meeting Minutes', array('Cemetery Commission'), 'minutes', array('cemeteries'), '2025-06-05', array('minutes' => 'approved', 'text' => 'Mowing contract, headstone repair at West Glover Cemetery, and plot sales.')),
    array('Cemetery Rules and Plot Prices', array('Cemetery Commission'), 'policies', array('cemeteries'), '2024-05-01', array('text' => 'Plot prices, burial season, and rules for flowers and monuments.')),
    array('Regular Meeting Minutes', array('Recreation Trails Committee'), 'minutes', array('recreation-trails'), '2025-08-21', array('minutes' => 'approved', 'text' => 'Trail work day, new signs for the town forest loop, and the grant for a footbridge.')),
    array('2025 Resident Survey Report', array('Town Equity Committee'), 'reports', array('equity-inclusion'), '2025-10-15', array('text' => 'Survey of residents on access to town services and recommendations.')),
    array('2025 Annual Report', array('Fire Department'), 'reports', array('fire-ambulance', 'emergency-management'), '2026-01-15', array('text' => 'Calls answered, training hours and equipment purchases.')),
    array('Glover Ambulance Service Agreement', array('Glover Ambulance', 'Select Board'), 'policies', array('fire-ambulance'), '2023-07-01', array('text' => 'Terms for emergency medical services to the Town of Glover.')),
    array('Regular Meeting Minutes', array('Library Trustees'), 'minutes', array(), '2026-03-12', array('minutes' => 'approved', 'text' => 'Summer reading program, new hours, and the building fund.')),
    array('Regular Meeting Agenda', array('School Directors'), 'agendas', array(), '2026-11-18', array('text' => 'Budget presentation and enrollment report.')),
);

// ----------------------------------------------------------- the helpers

/**
 * A one-page PDF whose text a PDF reader can extract.
 */
$make_pdf = function ($title, $date, $text) {
    $esc = function ($s) {
        return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $s);
    };
    $lines = array_merge(array($title, 'Town of Glover, Vermont', $date, ''), explode("\n", wordwrap($text, 80)));
    $stream = "BT /F1 12 Tf 72 720 Td 16 TL\n";

    foreach ($lines as $line) {
        $stream .= '(' . $esc($line) . ") Tj T*\n";
    }

    $stream .= 'ET';
    $objects = array(
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    );
    $pdf = "%PDF-1.4\n";
    $offsets = array();

    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
};

/**
 * A Word file with the same lines, as a minimal .docx.
 */
$make_docx = function ($title, $date, $text) {
    $paragraphs = '';

    foreach (array($title, 'Town of Glover, Vermont', $date, $text) as $line) {
        $paragraphs .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1) . '</w:t></w:r></w:p>';
    }

    $file = wp_tempnam('seed.docx');
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $paragraphs . '</w:body></w:document>');
    $zip->close();
    $data = file_get_contents($file);
    unlink($file);

    return $data;
};

/**
 * An Excel file with the lines down column A, as a minimal .xlsx.
 */
$make_xlsx = function ($title, $date, $text) {
    $rows = '';

    foreach (array($title, 'Town of Glover, Vermont', $date, $text) as $i => $line) {
        $rows .= '<row r="' . ($i + 1) . '"><c r="A' . ($i + 1) . '" t="inlineStr"><is><t>' . htmlspecialchars($line, ENT_XML1) . '</t></is></c></row>';
    }

    $file = wp_tempnam('seed.xlsx');
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
    $zip->close();
    $data = file_get_contents($file);
    unlink($file);

    return $data;
};

/**
 * A JPEG "scan": a plain page with the title and date. Images have no text
 * to read, so search finds these by title only, like a real scanned map.
 */
$make_jpg = function ($title, $date) {
    $image = imagecreatetruecolor(1200, 800);
    imagefill($image, 0, 0, imagecolorallocate($image, 243, 238, 226));
    $ink = imagecolorallocate($image, 20, 53, 42);
    imagerectangle($image, 40, 40, 1159, 759, $ink);
    imagestring($image, 5, 80, 80, $title, $ink);
    imagestring($image, 4, 80, 110, 'Town of Glover, Vermont', $ink);
    imagestring($image, 4, 80, 135, $date, $ink);
    ob_start();
    imagejpeg($image, null, 80);
    imagedestroy($image);

    return ob_get_clean();
};

$file_types = array(
    'pdf' => 'application/pdf',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'jpg' => 'image/jpeg',
);

$boards = array();

foreach (glover_document_library_all_boards() as $board_id => $board_name) {
    $boards[html_entity_decode($board_name, ENT_QUOTES)] = (int) $board_id;
}

$term_id = function ($slug, $taxonomy) {
    $term = get_term_by('slug', $slug, $taxonomy);

    if (!$term) {
        WP_CLI::error(sprintf('No %s term "%s".', $taxonomy, $slug));
    }

    return (int) $term->term_id;
};

// ------------------------------------------------------------------- seed

$uploads = wp_upload_dir();
$created = 0;

foreach ($documents as $doc) {
    list($title, $board_names, $category, $topics, $date, $extra) = $doc;

    // Titles repeat ("Regular Meeting Minutes"), so a sample is known by its
    // title, first board and date.
    $seed_key = sanitize_title($title . '-' . $board_names[0] . '-' . $date);

    if (get_posts(array('post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE, 'post_status' => 'any', 'meta_key' => GLOVER_SEED_META, 'meta_value' => $seed_key, 'fields' => 'ids'))) {
        continue;
    }

    $board_ids = array();

    foreach ($board_names as $name) {
        if (!isset($boards[$name])) {
            WP_CLI::error(sprintf('No board "%s".', $name));
        }

        $board_ids[] = (string) $boards[$name];
    }

    $status = isset($extra['status']) ? $extra['status'] : 'publish';
    $display_date = wp_date('F j, Y', strtotime($date . ' 12:00:00'));

    // The file, first in the public documents folder; saving the document
    // moves it to the private folder if the document is pending.
    $year = substr($date, 0, 4);
    $dir = trailingslashit($uploads['basedir']) . 'documents/' . $year;
    wp_mkdir_p($dir);
    $type = isset($extra['type']) ? $extra['type'] : 'pdf';
    $byline = implode(', ', $board_names) . ', ' . $display_date;
    $filename = wp_unique_filename($dir, $seed_key . '.' . $type);

    if ($type === 'docx') {
        $data = $make_docx($title, $byline, $extra['text']);
    } elseif ($type === 'xlsx') {
        $data = $make_xlsx($title, $byline, $extra['text']);
    } elseif ($type === 'jpg') {
        $data = $make_jpg($title, $byline);
    } else {
        $data = $make_pdf($title, $byline, $extra['text']);
    }

    file_put_contents($dir . '/' . $filename, $data);

    $post_id = wp_insert_post(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_title' => $title,
        'post_status' => 'pending',
        'post_author' => 2,
    ), true);

    if (is_wp_error($post_id)) {
        WP_CLI::error($post_id->get_error_message());
    }

    $attachment_id = wp_insert_attachment(array(
        'post_mime_type' => $file_types[$type],
        'post_title' => $title,
        'post_status' => 'inherit',
    ), $dir . '/' . $filename, $post_id);
    update_post_meta($attachment_id, '_wp_attachment_metadata', array('filesize' => filesize($dir . '/' . $filename)));

    update_post_meta($post_id, GLOVER_SEED_META, $seed_key);
    update_field('field_glover_document_file', $attachment_id, $post_id);
    update_field('field_glover_document_boards', $board_ids, $post_id);
    update_field('field_glover_document_date', str_replace('-', '', $date), $post_id);
    update_field('field_glover_document_accessibility_confirmed', 1, $post_id);
    update_field('field_glover_document_legal_confirmed', 1, $post_id);
    update_post_meta($post_id, '_glover_submitted_by', 2);

    wp_set_object_terms($post_id, array($term_id($category, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY)), GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY);
    update_field('field_glover_document_category', $term_id($category, GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY), $post_id);

    $topic_ids = array_map(function ($slug) use ($term_id) {
        return $term_id($slug, GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY);
    }, $topics);
    wp_set_object_terms($post_id, $topic_ids, GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY);
    update_field('field_glover_document_topics', $topic_ids, $post_id);

    if ($category === 'minutes') {
        update_field('field_glover_document_minutes_status', isset($extra['minutes']) ? $extra['minutes'] : 'approved', $post_id);
    }

    glover_document_library_sync_document_boards($post_id);
    glover_document_library_sync_file_visibility($post_id);
    glover_document_library_apply_naming($post_id);
    glover_document_library_log($post_id, 'submitted');

    if ($status === 'publish') {
        $previous = glover_document_library_allow_publish(true);
        wp_publish_post($post_id);
        glover_document_library_allow_publish($previous);
        // Drive is off on purpose here, so say so rather than "Not configured".
        glover_document_library_set_backup_status($post_id, 'test_data');
    }

    $created++;
}

WP_CLI::success(sprintf('Added %d sample documents (%d already there).', $created, count($documents) - $created));
