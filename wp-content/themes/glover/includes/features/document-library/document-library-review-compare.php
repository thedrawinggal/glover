<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Replacement review on Document Library > Documents.
 * - The Pending tab lists each published document that has pending
 *   replacements as one row; Compare opens the comparison in a dialog.
 * - The comparison shows the published file and each replacement, the
 *   details that change with a Keep current / Use proposed choice for each,
 *   an editable title, and the details that stay the same.
 * - One form approves the chosen replacement (returning any others with a
 *   reason) or returns them all. Without JavaScript, Compare opens the same
 *   comparison as a page.
 */

/**
 * Details an approver can keep or replace, in display order.
 *
 * @return array<string,string> Key => label.
 */
function glover_document_library_compare_keys()
{
    return array(
        'title' => 'Title',
        'date' => 'Document date',
        'minutes_status' => 'Minutes status',
        'boards' => 'Boards',
        'category' => 'Category',
        'topics' => 'Topics',
    );
}

/**
 * A document's comparable details.
 *
 * @param int $post_id Document ID.
 * @return array<string,array{value:string,items:string[]|null}>
 */
function glover_document_library_compare_details($post_id)
{
    $terms = function ($taxonomy) use ($post_id) {
        $list = get_the_terms($post_id, $taxonomy);
        return ($list && !is_wp_error($list)) ? wp_list_pluck($list, 'name') : array();
    };
    $status = get_field('document_minutes_status', $post_id, false);
    $boards = array_map('get_the_title', glover_document_library_document_board_ids($post_id));
    $topics = $terms(GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY);
    $categories = $terms(GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY);

    return array(
        'title' => array('value' => get_post_field('post_title', $post_id, 'raw'), 'items' => null),
        'date' => array('value' => glover_document_library_display_date($post_id, 'F j, Y'), 'items' => null),
        'minutes_status' => array('value' => $status === 'draft' ? 'Draft' : ($status === 'approved' ? 'Approved by the board' : ''), 'items' => null),
        'boards' => array('value' => implode(', ', $boards), 'items' => $boards),
        'category' => array('value' => implode(', ', $categories), 'items' => $categories),
        'topics' => array('value' => implode(', ', $topics), 'items' => $topics),
    );
}

/**
 * The details that differ between a published document and a replacement.
 *
 * @param array $current  From glover_document_library_compare_details().
 * @param array $proposed From glover_document_library_compare_details().
 * @return array{changed:array<string,array>,same:array<string,string>}
 */
function glover_document_library_compare_changes($current, $proposed)
{
    $changed = array();
    $same = array();

    foreach (glover_document_library_compare_keys() as $key => $label) {
        if ($current[$key]['value'] === $proposed[$key]['value']) {
            $same[$key] = $current[$key]['value'];
            continue;
        }

        $note = array();

        if (is_array($current[$key]['items'])) {
            $added = array_diff($proposed[$key]['items'], $current[$key]['items']);
            $removed = array_diff($current[$key]['items'], $proposed[$key]['items']);

            if ($added) {
                $note[] = 'Added: ' . implode(', ', $added);
            }

            if ($removed) {
                $note[] = 'Removed: ' . implode(', ', $removed);
            }
        }

        $changed[$key] = array(
            'current' => $current[$key]['value'],
            'proposed' => $proposed[$key]['value'],
            'note' => implode('. ', $note),
        );
    }

    return array('changed' => $changed, 'same' => $same);
}

/**
 * Who submitted a document and when, as separate parts.
 *
 * @param int $post_id Document ID.
 * @return array{user:WP_User|false,name:string,date:string}
 */
function glover_document_library_review_submitter($post_id)
{
    $user = get_userdata((int) get_post_meta($post_id, '_glover_submitted_by', true));
    $user = $user ? $user : get_userdata((int) get_post_field('post_author', $post_id));

    return array(
        'user' => $user,
        'name' => $user ? $user->display_name : 'Unknown',
        'date' => get_the_date('M j, Y, g:i a', $post_id),
    );
}

/**
 * Join names as "A", "A and B" or "A, B and C".
 *
 * @param string[] $names Names.
 */
function glover_document_library_join_names($names)
{
    $names = array_values($names);

    if (count($names) < 2) {
        return implode('', $names);
    }

    return implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
}

/**
 * The footer sentence for a replacement before any choices are changed.
 * assets/review-admin.js rebuilds the same sentence as choices change.
 *
 * @param int      $changed     Details that change.
 * @param string   $name        "the new file" or "Replacement 2's file".
 * @param string[] $other_names Replacements that would be returned.
 */
function glover_document_library_compare_summary($changed, $name, $other_names)
{
    $text = 'Approving publishes ' . $name;

    if ($changed) {
        $text .= sprintf(_n(' and %d proposed detail', ' and %d proposed details', $changed), $changed);
    }

    $text .= '. The page keeps its address, and the current file moves to Previous versions.';

    if ($other_names) {
        $text .= ' ' . glover_document_library_join_names($other_names) . (count($other_names) === 1 ? ' goes back to its submitter' : ' go back to their submitters') . ' with the reason below.';
    }

    return $text;
}

/**
 * Where Compare goes without JavaScript.
 *
 * @param int $original_id Published document.
 */
function glover_document_library_compare_url($original_id)
{
    return glover_document_library_review_screen_url('pending', array('compare' => (int) $original_id));
}

/**
 * The Replacements list on the Pending tab, plus a dialog per document.
 *
 * @param array<int,int[]> $groups Published document ID => pending replacement IDs.
 */
function glover_document_library_render_replacement_queue($groups)
{
    ?>
    <ul class="glover-queue">
        <?php foreach ($groups as $original_id => $replacement_ids) : ?>
            <?php
            $title = get_post_field('post_title', $original_id, 'raw');
            $multiple = count($replacement_ids) > 1;
            $changed = array();

            if (!$multiple) {
                $changed = glover_document_library_compare_changes(
                    glover_document_library_compare_details($original_id),
                    glover_document_library_compare_details($replacement_ids[0])
                )['changed'];
            }

            $submitters = array_map('glover_document_library_review_submitter', $replacement_ids);
            $dialog_id = 'glover-compare-dialog-' . (int) $original_id;
            ?>
            <li class="glover-queue__item">
                <div class="glover-queue__main">
                    <strong class="glover-queue__title"><?php echo esc_html($title); ?></strong>
                    <div class="glover-queue__tags">
                        <?php if ($multiple) : ?>
                            <span class="glover-review-tag glover-review-tag--warning"><?php echo (int) count($replacement_ids); ?> replacements</span>
                            <span class="glover-queue__hint">Choose one to approve</span>
                        <?php else : ?>
                            <span class="glover-review-tag glover-review-tag--changed">New file</span>
                            <?php if ($changed) : ?>
                                <?php foreach (array_intersect_key(glover_document_library_compare_keys(), $changed) as $label) : ?>
                                    <span class="glover-review-tag glover-review-tag--changed"><?php echo esc_html($label); ?></span>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <span class="glover-queue__hint">Only the file changes</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="glover-queue__who">
                    <span><?php echo esc_html(glover_document_library_join_names(array_unique(wp_list_pluck($submitters, 'name')))); ?></span>
                    <span class="glover-queue__date"><?php echo esc_html(implode('; ', wp_list_pluck($submitters, 'date'))); ?></span>
                </div>
                <div class="glover-queue__actions">
                    <a class="button<?php echo (!$multiple && !$changed) ? '' : ' button-primary'; ?>" href="<?php echo esc_url(glover_document_library_compare_url($original_id)); ?>" data-glover-open="<?php echo esc_attr($dialog_id); ?>" aria-haspopup="dialog">Compare<span class="screen-reader-text"> <?php echo esc_html($title); ?></span></a>
                    <?php if (!$multiple && !$changed) : ?>
                        <a class="button button-primary" href="<?php echo esc_url(glover_document_library_approve_url($replacement_ids[0], 'review')); ?>">Approve file<span class="screen-reader-text"> for <?php echo esc_html($title); ?></span></a>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php foreach ($groups as $original_id => $replacement_ids) : ?>
        <dialog id="<?php echo esc_attr('glover-compare-dialog-' . (int) $original_id); ?>" class="glover-compare-dialog" aria-labelledby="<?php echo esc_attr('glover-compare-title-' . (int) $original_id); ?>">
            <?php glover_document_library_render_compare($original_id, $replacement_ids, true); ?>
        </dialog>
    <?php endforeach; ?>
    <?php
}

/**
 * A file panel: type, name, size, and Preview / Check text links.
 *
 * @param int    $post_id    Document ID.
 * @param string $label      Panel label.
 * @param bool   $show_label Whether to print the label (cards have their own).
 */
function glover_document_library_render_compare_file($post_id, $label, $show_label = true)
{
    $path = get_attached_file(glover_document_library_file_id($post_id));
    $file = glover_document_library_file_details($post_id);
    $url = glover_document_library_file_url($post_id);
    ?>
    <span class="glover-compare__file-icon" aria-hidden="true"><?php echo esc_html($file['ext'] ? strtoupper($file['ext']) : 'FILE'); ?></span>
    <span class="glover-compare__file-text">
        <?php if ($show_label) : ?>
            <span class="glover-compare__label"><?php echo esc_html($label); ?></span>
        <?php endif; ?>
        <span class="glover-compare__file-name"><?php echo esc_html($path ? basename($path) : 'No file'); ?></span>
        <span class="glover-compare__muted"><?php echo esc_html(trim($file['label'] . ', ' . $file['size'], ', ')); ?></span>
        <span class="glover-compare__links">
            <?php if ($url) : ?>
                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" data-glover-view-file="<?php echo (int) $post_id; ?>" data-ext="<?php echo esc_attr($file['ext']); ?>" data-glover-check-title="<?php echo esc_attr($label); ?>">Preview<span class="screen-reader-text"> (<?php echo esc_html($label); ?>)</span></a>
            <?php endif; ?>
            <?php if (function_exists('glover_document_search_check_url')) : ?>
                <a href="<?php echo esc_url(glover_document_search_check_url($post_id)); ?>" data-glover-check="<?php echo (int) $post_id; ?>" data-glover-check-title="<?php echo esc_attr($label); ?>">Check text<span class="screen-reader-text"> (<?php echo esc_html($label); ?>)</span></a>
            <?php endif; ?>
        </span>
    </span>
    <?php
}

/**
 * The comparison form for one published document and its pending replacements.
 *
 * @param int   $original_id     Published document.
 * @param int[] $replacement_ids Pending replacements, oldest first.
 * @param bool  $in_dialog       Whether it is inside a <dialog>.
 */
function glover_document_library_render_compare($original_id, $replacement_ids, $in_dialog = false)
{
    $current = glover_document_library_compare_details($original_id);
    $keys = glover_document_library_compare_keys();
    $multiple = count($replacement_ids) > 1;
    $versions = count(glover_document_library_meta_list($original_id, '_glover_previous_files'));
    $title_id = 'glover-compare-title-' . (int) $original_id;
    $replacements = array();

    foreach (array_values($replacement_ids) as $index => $replacement_id) {
        $replacements[] = array(
            'id' => (int) $replacement_id,
            'name' => $multiple ? 'Replacement ' . ($index + 1) : 'Proposed replacement',
            'submitter' => glover_document_library_review_submitter($replacement_id),
            'note' => get_post_meta($replacement_id, 'document_reviewer_note', true),
            'changes' => glover_document_library_compare_changes($current, glover_document_library_compare_details($replacement_id)),
        );
    }

    $first = $replacements[0];
    $others = array_slice(wp_list_pluck($replacements, 'name'), 1);
    $other_people = array_unique(array_map(function ($r) {
        return $r['submitter']['name'];
    }, array_slice($replacements, 1)));
    ?>
    <form class="glover-compare" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-glover-compare data-multiple="<?php echo $multiple ? '1' : '0'; ?>"
        data-check-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
        data-check-nonce="<?php echo esc_attr(wp_create_nonce('glover_document_check_text')); ?>">
        <input type="hidden" name="action" value="glover_document_review_replacement">
        <input type="hidden" name="original" value="<?php echo (int) $original_id; ?>">
        <?php wp_nonce_field('glover_document_replacement_' . (int) $original_id); ?>

        <div class="glover-compare__header">
            <div class="glover-compare__heading">
                <span class="glover-compare__label">Replacing</span>
                <h2 id="<?php echo esc_attr($title_id); ?>" tabindex="-1"><?php echo esc_html($current['title']['value']); ?></h2>
                <span class="glover-compare__meta">
                    <span>Published <?php echo esc_html(get_the_date('M j, Y', $original_id)); ?></span>
                    <span><?php echo esc_html($versions ? sprintf(_n('Replaced %d time', 'Replaced %d times', $versions), $versions) : 'No previous versions'); ?></span>
                    <a href="<?php echo esc_url(get_permalink($original_id)); ?>" target="_blank" rel="noopener">Open published file<span class="screen-reader-text"> (opens in a new tab)</span></a>
                </span>
            </div>
            <?php if ($in_dialog) : ?>
                <button type="button" class="glover-compare__close" data-glover-close aria-label="Close">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            <?php endif; ?>
        </div>

        <div class="glover-compare__body" data-glover-compare-body>
            <?php if (!$multiple) : ?>
                <input type="hidden" name="replacement" value="<?php echo (int) $first['id']; ?>">
                <div class="glover-compare__submitter">
                    <span class="glover-compare__person">
                        <?php echo $first['submitter']['user'] ? get_avatar($first['submitter']['user']->ID, 32, '', '') : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- core output. ?>
                        <span>
                            <strong><?php echo esc_html($first['submitter']['name']); ?></strong><br>
                            <span class="glover-compare__muted">Submitted <?php echo esc_html($first['submitter']['date']); ?></span>
                        </span>
                    </span>
                    <?php if ($first['note']) : ?>
                        <div class="glover-compare__note">
                            <span class="glover-compare__note-label">Note from submitter</span>
                            <p><?php echo esc_html($first['note']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="glover-compare__section">
                <h3>File</h3>
                <?php if (!$multiple) : ?>
                    <div class="glover-compare__files">
                        <div class="glover-compare__file"><?php glover_document_library_render_compare_file($original_id, 'Currently published'); ?></div>
                        <svg class="glover-compare__arrow" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        <div class="glover-compare__file glover-compare__file--proposed"><?php glover_document_library_render_compare_file($first['id'], 'Proposed replacement'); ?></div>
                    </div>
                <?php else : ?>
                    <div class="glover-compare__file glover-compare__file--current"><?php glover_document_library_render_compare_file($original_id, 'Currently published'); ?></div>
                    <svg class="glover-compare__arrow glover-compare__arrow--down" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
                    <fieldset class="glover-compare__choices">
                        <legend>Choose the replacement to publish (<?php echo (int) count($replacements); ?> submitted)</legend>
                        <div class="glover-compare__cards">
                            <?php foreach ($replacements as $index => $replacement) : ?>
                                <label class="glover-compare__card">
                                    <span class="glover-compare__card-top">
                                        <input type="radio" name="replacement" value="<?php echo (int) $replacement['id']; ?>" <?php checked($index, 0); ?>
                                            data-name="<?php echo esc_attr($replacement['name']); ?>"
                                            data-person="<?php echo esc_attr($replacement['submitter']['name']); ?>"
                                            data-edit-url="<?php echo esc_url(get_edit_post_link($replacement['id'], 'url')); ?>">
                                        <span class="glover-compare__label glover-compare__label--blue"><?php echo esc_html($replacement['name']); ?></span>
                                    </span>
                                    <span class="glover-compare__file glover-compare__file--card"><?php glover_document_library_render_compare_file($replacement['id'], $replacement['name'], false); ?></span>
                                    <span class="glover-compare__card-who">
                                        <strong><?php echo esc_html($replacement['submitter']['name']); ?></strong>
                                        <span class="glover-compare__muted"><?php echo esc_html($replacement['submitter']['date']); ?></span>
                                    </span>
                                    <?php if ($replacement['note']) : ?>
                                        <span class="glover-compare__card-note"><?php echo esc_html($replacement['note']); ?></span>
                                    <?php endif; ?>
                                    <span class="glover-compare__card-foot">
                                        <span class="glover-review-tag glover-review-tag--changed"><?php
                                            $count = count($replacement['changes']['changed']);
                                            echo esc_html($count ? sprintf(_n('%d detail changes', '%d details change', $count), $count) : 'Only the file changes');
                                        ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>
            </div>

            <?php foreach ($replacements as $index => $replacement) : ?>
                <?php
                $changed = $replacement['changes']['changed'];
                $same = $replacement['changes']['same'];
                $column = $multiple ? $replacement['name'] : 'Proposed replacement';
                ?>
                <div class="glover-compare__section glover-compare__details" data-replacement="<?php echo (int) $replacement['id']; ?>">
                    <div class="glover-compare__section-head">
                        <h3><?php echo esc_html($multiple ? 'Details that change in ' . $replacement['name'] : 'Details that change'); ?></h3>
                        <?php if ($changed) : ?>
                            <span class="glover-compare__muted">Choose which version of each detail to publish.</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$changed) : ?>
                        <p class="glover-compare__none">Only the file changes.</p>
                    <?php else : ?>
                        <div class="glover-compare__table-wrap">
                            <table class="glover-compare__table">
                                <caption class="screen-reader-text"><?php echo esc_html(($multiple ? $replacement['name'] . ': ' : '') . 'details that change'); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="glover-compare__col-label">Detail</th>
                                        <th scope="col">Currently published</th>
                                        <th scope="col"><?php echo esc_html($column); ?></th>
                                        <th scope="col" class="glover-compare__col-choice">Publish</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($changed as $key => $change) : ?>
                                        <?php $field = 'keep[' . (int) $replacement['id'] . '][' . $key . ']'; ?>
                                        <tr data-detail="<?php echo esc_attr($key); ?>">
                                            <th scope="row"><?php echo esc_html($keys[$key]); ?></th>
                                            <td class="glover-compare__current"><?php echo esc_html($change['current'] !== '' ? $change['current'] : 'None'); ?></td>
                                            <td class="glover-compare__proposed">
                                                <?php if ($key === 'title') : ?>
                                                    <span data-glover-title-view>
                                                        <span data-glover-title-text><?php echo esc_html($change['proposed']); ?></span>
                                                        <span class="glover-review-tag glover-review-tag--changed" data-glover-title-tag>Changed</span>
                                                        <button type="button" class="button-link glover-compare__edit-title" data-glover-title-edit hidden>Edit title<span class="screen-reader-text"> to publish</span></button>
                                                    </span>
                                                    <span class="glover-compare__title-field" data-glover-title-field>
                                                        <label for="<?php echo esc_attr('glover-title-' . (int) $replacement['id']); ?>">Title to publish</label>
                                                        <input type="text" id="<?php echo esc_attr('glover-title-' . (int) $replacement['id']); ?>" name="<?php echo esc_attr('title[' . (int) $replacement['id'] . ']'); ?>" value="<?php echo esc_attr($change['proposed']); ?>" data-proposed="<?php echo esc_attr($change['proposed']); ?>">
                                                        <span class="glover-compare__title-buttons" hidden>
                                                            <button type="button" class="button button-small" data-glover-title-done>Done</button>
                                                            <button type="button" class="button-link" data-glover-title-cancel>Cancel</button>
                                                        </span>
                                                    </span>
                                                <?php else : ?>
                                                    <?php echo esc_html($change['proposed'] !== '' ? $change['proposed'] : 'None'); ?>
                                                    <span class="glover-review-tag glover-review-tag--changed">Changed</span>
                                                <?php endif; ?>
                                                <?php if ($change['note'] !== '') : ?>
                                                    <span class="glover-compare__change-note"><?php echo esc_html($change['note']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <fieldset class="glover-choice">
                                                    <legend class="screen-reader-text"><?php echo esc_html($keys[$key] . ' to publish' . ($multiple ? ' from ' . $replacement['name'] : '')); ?></legend>
                                                    <label><input type="radio" name="<?php echo esc_attr($field); ?>" value="current"><span>Keep current</span></label>
                                                    <label><input type="radio" name="<?php echo esc_attr($field); ?>" value="proposed" checked><span>Use proposed</span></label>
                                                </fieldset>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($same) : ?>
                        <details class="glover-compare__same">
                            <summary><?php echo esc_html(sprintf(_n('%d detail stays the same', '%d details stay the same', count($same)), count($same))); ?></summary>
                            <table class="glover-compare__table glover-compare__table--same">
                                <caption class="screen-reader-text">Details that stay the same</caption>
                                <tbody>
                                    <?php foreach ($same as $key => $value) : ?>
                                        <tr>
                                            <th scope="row"><?php echo esc_html($keys[$key]); ?></th>
                                            <td><?php echo esc_html($value !== '' ? $value : 'None'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="glover-compare__footer" data-glover-approve-area>
            <p class="glover-compare__summary" data-glover-summary aria-live="polite"><?php
                echo esc_html(glover_document_library_compare_summary(
                    count($first['changes']['changed']),
                    $multiple ? $first['name'] . '’s file' : 'the new file',
                    $others
                ));
            ?></p>
            <?php if ($multiple) : ?>
                <p class="glover-compare__field">
                    <label for="<?php echo esc_attr('glover-others-' . (int) $original_id); ?>">
                        <span data-glover-others-label>Reason for returning <?php echo esc_html(glover_document_library_join_names($others)); ?></span>
                        <span class="glover-compare__muted">(emailed to <span data-glover-others-people><?php echo esc_html(glover_document_library_join_names($other_people)); ?></span>)</span>
                    </label>
                    <input type="text" id="<?php echo esc_attr('glover-others-' . (int) $original_id); ?>" name="others_reason" required>
                </p>
            <?php endif; ?>
            <div class="glover-compare__actions">
                <a class="button" href="<?php echo esc_url(get_edit_post_link($first['id'], 'url')); ?>" data-glover-edit-link>Edit<span class="screen-reader-text" data-glover-edit-name> <?php echo esc_html($first['name']); ?></span></a>
                <span class="glover-review-sep" aria-hidden="true">|</span>
                <button type="button" class="button" title="<?php echo $multiple ? 'Return all replacements to their submitters' : 'Return to submitter'; ?>" data-glover-return-open hidden><?php echo $multiple ? 'Return all' : 'Return'; ?></button>
                <button type="submit" class="button button-primary" name="decision" value="approve">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                    <span data-glover-approve-label><?php echo esc_html($multiple ? 'Approve ' . $first['name'] : 'Approve Replacement'); ?></span>
                </button>
            </div>
        </div>

        <div class="glover-compare__footer glover-compare__return" data-glover-return-area>
            <p class="glover-compare__field">
                <label for="<?php echo esc_attr('glover-reason-' . (int) $original_id); ?>">
                    <?php echo $multiple ? 'Reason for returning all replacements' : 'Reason for returning it'; ?>
                    <span class="glover-compare__muted">(emailed to <?php echo esc_html(glover_document_library_join_names(array_unique(array_map(function ($r) {
                        return $r['submitter']['name'];
                    }, $replacements)))); ?>)</span>
                </label>
                <textarea id="<?php echo esc_attr('glover-reason-' . (int) $original_id); ?>" name="reason" rows="3"></textarea>
            </p>
            <div class="glover-compare__actions">
                <button type="button" class="button-link" data-glover-return-cancel hidden>Cancel</button>
                <button type="submit" class="button" name="decision" value="return" formnovalidate><?php echo $multiple ? 'Return all' : 'Return to submitter'; ?></button>
            </div>
        </div>

        <div class="glover-compare__check" data-glover-check-view hidden>
            <div class="glover-compare__check-bar">
                <button type="button" class="button" data-glover-check-back>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 6l-6 6 6 6"/></svg>
                    Back to comparison
                </button>
                <h3 tabindex="-1" data-glover-check-heading>Check text</h3>
            </div>
            <div class="glover-compare__body glover-compare__check-body" data-glover-check-body aria-live="polite"></div>
        </div>
    </form>
    <?php
}

/**
 * Copy one detail from a document onto another.
 *
 * @param string $key  A key from glover_document_library_compare_keys().
 * @param int    $from Source document.
 * @param int    $to   Target document.
 */
function glover_document_library_copy_detail($key, $from, $to)
{
    switch ($key) {
        case 'title':
            wp_update_post(array('ID' => $to, 'post_title' => get_post_field('post_title', $from, 'raw')));
            break;

        case 'date':
            update_field('field_glover_document_date', get_field('document_date', $from, false), $to);
            break;

        case 'minutes_status':
            update_field('field_glover_document_minutes_status', get_field('document_minutes_status', $from, false), $to);
            break;

        case 'boards':
            update_field('field_glover_document_boards', get_field('document_boards', $from, false), $to);
            glover_document_library_sync_document_boards($to);
            break;

        case 'category':
        case 'topics':
            $taxonomy = $key === 'category' ? GLOVER_DOCUMENT_LIBRARY_CATEGORY_TAXONOMY : GLOVER_DOCUMENT_LIBRARY_TOPIC_TAXONOMY;
            $terms = wp_get_object_terms($from, $taxonomy, array('fields' => 'ids'));

            if (!is_wp_error($terms)) {
                wp_set_object_terms($to, $terms, $taxonomy, false);
            }
            break;
    }
}

/**
 * Approve one replacement (returning the others) or return them all.
 *
 * Kept details are copied from the published document onto the replacement
 * before it is published, so the merge in document-library-lifecycle.php
 * carries them over unchanged.
 */
function glover_document_library_handle_replacement_review()
{
    $original_id = isset($_POST['original']) ? absint($_POST['original']) : 0;
    check_admin_referer('glover_document_replacement_' . $original_id);

    if (!glover_document_library_user_can_review()) {
        wp_die('You do not have permission to review documents.', 'Forbidden', array('response' => 403));
    }

    $groups = glover_document_library_pending_groups()['groups'];
    $pending = isset($groups[$original_id]) ? array_map('intval', $groups[$original_id]) : array();

    if (!$pending) {
        wp_die('These replacements have already been reviewed.', 'Nothing to review', array('back_link' => true));
    }

    $decision = isset($_POST['decision']) ? sanitize_key($_POST['decision']) : '';

    if ($decision === 'return') {
        $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        if ($reason === '') {
            wp_die('Please give a reason for returning.', 'Reason required', array('back_link' => true));
        }

        foreach ($pending as $post_id) {
            glover_document_library_return_document($post_id, $reason);
        }

        wp_safe_redirect(glover_document_library_review_screen_url('pending', array('glover_notice' => count($pending) > 1 ? 'replacements_returned' : 'returned')));
        exit;
    }

    $replacement_id = isset($_POST['replacement']) ? absint($_POST['replacement']) : 0;

    if (!in_array($replacement_id, $pending, true)) {
        wp_die('Choose a replacement to approve.', 'Replacement required', array('back_link' => true));
    }

    $others = array_diff($pending, array($replacement_id));
    $others_reason = isset($_POST['others_reason']) ? sanitize_textarea_field(wp_unslash($_POST['others_reason'])) : '';

    if ($others && $others_reason === '') {
        wp_die('Please give a reason for returning the other replacements.', 'Reason required', array('back_link' => true));
    }

    $choices = isset($_POST['keep'][$replacement_id]) && is_array($_POST['keep'][$replacement_id]) ? wp_unslash($_POST['keep'][$replacement_id]) : array();
    $kept = array();

    foreach (array_keys(glover_document_library_compare_keys()) as $key) {
        if (isset($choices[$key]) && $choices[$key] === 'current') {
            glover_document_library_copy_detail($key, $original_id, $replacement_id);
            $kept[] = $key;
        }
    }

    $title = isset($_POST['title'][$replacement_id]) ? sanitize_text_field(wp_unslash($_POST['title'][$replacement_id])) : '';

    if (!in_array('title', $kept, true) && $title !== '' && $title !== get_post_field('post_title', $replacement_id, 'raw')) {
        wp_update_post(array('ID' => $replacement_id, 'post_title' => $title));
        glover_document_library_log($replacement_id, 'edited', 'Title edited during review');
    }

    foreach ($others as $other_id) {
        glover_document_library_return_document($other_id, $others_reason);
    }

    glover_document_library_log($replacement_id, 'approved', $kept ? 'Kept current: ' . implode(', ', $kept) : '');
    wp_publish_post($replacement_id);

    wp_safe_redirect(glover_document_library_review_screen_url('pending', array('glover_notice' => 'replacement_approved')));
    exit;
}
add_action('admin_post_glover_document_review_replacement', 'glover_document_library_handle_replacement_review');

/**
 * Review: the last button on a new document's row. With JavaScript it opens
 * the preview dialog (which holds Approve); without, the file opens in a new
 * tab. A document with no file gets a plain Approve button instead.
 *
 * On a document's edit screen ($context 'edit') the same dialog opens from
 * under Document file: Review with Return and Approve for a pending
 * document, Preview alone otherwise, and no Edit button.
 *
 * $context 'check' is the edit screen's Check text button: the same dialog
 * showing the file's text, with the same buttons.
 *
 * @param int    $post_id Document.
 * @param string $context 'queue' (Documents screen), 'edit' or 'check'.
 */
function glover_document_library_preview_link($post_id, $context = 'queue')
{
    $url = glover_document_library_file_url($post_id);
    $checking = $context === 'check';
    $on_edit = $context === 'edit' || $checking;
    $reviewing = !$on_edit || (get_post_status($post_id) === 'pending' && glover_document_library_user_can_review());

    if (!$url && $on_edit) {
        return;
    }

    if (!$url) {
        printf(
            '<a class="button button-primary" href="%s" aria-label="%s">Approve</a>',
            esc_url(glover_document_library_approve_url($post_id, 'review')),
            esc_attr('Approve: ' . get_the_title($post_id))
        );
        return;
    }

    $file = glover_document_library_file_details($post_id);
    $path = get_attached_file(glover_document_library_file_id($post_id));
    $submitter = glover_document_library_review_submitter($post_id);
    printf(
        '<a class="button%12$s" href="%1$s" target="_blank" rel="noopener" aria-haspopup="dialog" data-glover-preview="%2$d" data-glover-preview-mode="%14$s" data-open-url="%15$s" data-title="%3$s" data-ext="%4$s" data-file="%5$s" data-meta="%6$s" data-approve-url="%7$s" data-return-url="%8$s" data-edit-url="%9$s" data-note="%11$s" %10$s>%13$s<span class="screen-reader-text"> %3$s</span></a>',
        esc_url($checking && function_exists('glover_document_search_check_url') ? glover_document_search_check_url($post_id) : $url),
        (int) $post_id,
        esc_attr(get_post_field('post_title', $post_id, 'raw')),
        esc_attr($file['ext']),
        esc_attr($path ? basename($path) : ''),
        esc_attr(trim($file['label'] . ', ' . $file['size'], ', ') . '. Submitted by ' . $submitter['name'] . ', ' . $submitter['date']),
        $reviewing ? esc_url(glover_document_library_approve_url($post_id, 'review')) : '',
        $reviewing ? esc_url(glover_document_library_return_url($post_id, 'review')) : '',
        $on_edit ? '' : esc_url(get_edit_post_link($post_id, 'url')),
        $reviewing ? glover_document_library_return_data($post_id, 'data-return-') : '', // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper.
        esc_attr((string) get_post_meta($post_id, 'document_reviewer_note', true)),
        $reviewing && !$checking ? ' button-primary' : '',
        $checking ? 'Check text' : ($reviewing ? 'Review' : 'Preview'),
        $checking ? 'check' : 'preview',
        esc_url($url)
    );
}

/**
 * Data attributes the Return dialog reads from the link that opens it.
 *
 * @param int    $post_id Pending document.
 * @param string $prefix  Attribute prefix.
 */
function glover_document_library_return_data($post_id, $prefix = 'data-glover-return-')
{
    $submitter = glover_document_library_review_submitter($post_id);

    return sprintf(
        '%1$sid="%2$d" %1$stitle="%3$s" %1$sperson="%4$s" %1$snonce="%5$s"',
        $prefix,
        (int) $post_id,
        esc_attr(get_post_field('post_title', $post_id, 'raw')),
        esc_attr($submitter['name']),
        esc_attr(wp_create_nonce('glover_document_return_' . (int) $post_id))
    );
}

/**
 * The one Return dialog for new documents. It posts to the same handler as
 * the Return page (glover_document_return), which emails the submitter.
 */
function glover_document_library_render_return_dialog()
{
    ?>
    <dialog id="glover-return-dialog" class="glover-compare-dialog glover-return-dialog" aria-labelledby="glover-return-title">
        <form class="glover-compare" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="glover_document_return">
            <input type="hidden" name="back" value="review">
            <input type="hidden" name="post" value="" data-glover-return-post>
            <input type="hidden" name="_wpnonce" value="" data-glover-return-nonce>
            <div class="glover-compare__header">
                <div class="glover-compare__heading">
                    <span class="glover-compare__label">Return to submitter</span>
                    <h2 id="glover-return-title" tabindex="-1" data-glover-return-heading></h2>
                </div>
                <button type="button" class="glover-compare__close" data-glover-close aria-label="Close">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div class="glover-compare__body glover-return-dialog__body">
                <p>Return the document to the submitter and state the reason it was rejected. The submitter will receive an email notifying them to resubmit their document. The document will not be posted to the website.</p>
                <p class="glover-compare__field">
                    <label for="glover-return-reason">Reason for returning it <span class="glover-compare__muted">(emailed to <span data-glover-return-person></span>)</span></label>
                    <textarea id="glover-return-reason" name="reason" rows="5" required aria-describedby="glover-return-help"></textarea>
                    <span id="glover-return-help" class="glover-compare__muted">Say what needs to change, for example "This is a scan; please upload a text-based PDF."</span>
                </p>
            </div>
            <div class="glover-compare__footer">
                <div class="glover-compare__actions">
                    <button type="button" class="button" data-glover-close>Cancel</button>
                    <button type="submit" class="button button-primary">Return to submitter</button>
                </div>
            </div>
        </form>
    </dialog>
    <?php
}

/**
 * The one preview dialog for new documents, opened by Review. PDFs show in a frame; Word and
 * Excel files, which browsers can't display, show their text instead.
 */
function glover_document_library_render_preview_dialog()
{
    ?>
    <dialog id="glover-preview-dialog" class="glover-compare-dialog glover-preview-dialog" aria-labelledby="glover-preview-title"
        data-check-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
        data-check-nonce="<?php echo esc_attr(wp_create_nonce('glover_document_check_text')); ?>">
        <div class="glover-compare">
            <div class="glover-compare__header">
                <div class="glover-compare__heading">
                    <span class="glover-compare__label" data-glover-preview-label>Preview</span>
                    <h2 id="glover-preview-title" tabindex="-1" data-glover-preview-title></h2>
                    <span class="glover-compare__meta"><span data-glover-preview-file></span><span data-glover-preview-meta></span></span>
                </div>
                <button type="button" class="glover-compare__close" data-glover-close aria-label="Close">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div class="glover-compare__note glover-preview-dialog__note" data-glover-preview-note-wrap hidden>
                <h3 class="glover-compare__note-label">Note from submitter</h3>
                <p data-glover-preview-note></p>
            </div>
            <div class="glover-compare__body glover-preview-dialog__body" data-glover-preview-body aria-live="polite"></div>
            <div class="glover-compare__footer">
                <div class="glover-compare__actions">
                    <a class="button" href="#" target="_blank" rel="noopener" data-glover-preview-open>Open in new tab</a>
                    <a class="button" href="#" data-glover-preview-edit>Edit</a>
                    <span class="glover-review-sep" aria-hidden="true" data-glover-preview-sep>|</span>
                    <a class="button" href="#" title="Return to submitter" data-glover-preview-return>Return</a>
                    <a class="button button-primary" href="#" data-glover-preview-approve>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                        Approve
                    </a>
                </div>
            </div>
        </div>
    </dialog>
    <?php
}

/**
 * Review (or Preview) under the Document file field on a document's edit screen.
 *
 * @param array $field Field being rendered.
 */
function glover_document_library_edit_screen_review_button($field)
{
    global $post;

    if (!is_admin() || !$post || $post->post_type !== GLOVER_DOCUMENT_LIBRARY_POST_TYPE || !glover_document_library_file_id($post->ID)) {
        return;
    }

    echo '<p class="glover-edit-review">';
    glover_document_library_preview_link($post->ID, 'edit');
    echo '</p>';
}
add_action('acf/render_field/key=field_glover_document_file', 'glover_document_library_edit_screen_review_button', 20);

/**
 * Whether the current screen is a document's edit screen.
 */
function glover_document_library_is_edit_screen()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    return $screen && $screen->base === 'post' && $screen->post_type === GLOVER_DOCUMENT_LIBRARY_POST_TYPE;
}

/**
 * The review dialogs on a document's edit screen: preview (also used for
 * Check text) and Return.
 */
function glover_document_library_edit_screen_dialogs()
{
    if (!glover_document_library_is_edit_screen()) {
        return;
    }

    glover_document_library_render_preview_dialog();

    if (glover_document_library_user_can_review()) {
        glover_document_library_render_return_dialog();
    }
}
add_action('admin_footer', 'glover_document_library_edit_screen_dialogs');

/**
 * The review dialog script on a document's edit screen too.
 */
function glover_document_library_edit_screen_assets()
{
    if (!glover_document_library_is_edit_screen()) {
        return;
    }

    $base = get_template_directory_uri() . '/includes/features/document-library/assets/';
    wp_enqueue_style('glover-document-review', $base . 'review-admin.css', array(), (string) filemtime(__DIR__ . '/assets/review-admin.css'));
    wp_enqueue_script('glover-document-review', $base . 'review-admin.js', array(), (string) filemtime(__DIR__ . '/assets/review-admin.js'), true);
}
add_action('admin_enqueue_scripts', 'glover_document_library_edit_screen_assets');
