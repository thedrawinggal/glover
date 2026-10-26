<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Send a Document Library email in the site's shared layout
 * (glover_email_send() in the Glover Google Workspace must-use plugin).
 *
 * @param string|string[] $to      Recipient address(es).
 * @param string          $subject Subject line.
 * @param array           $args    glover_email_render() arguments.
 * @param string[]        $headers Extra headers, e.g. Reply-To.
 * @return bool
 */
function glover_document_library_mail($to, $subject, array $args, array $headers = array())
{
    if (!function_exists('glover_email_send')) {
        return false;
    }

    return glover_email_send($to, $subject, $args, $headers);
}

/**
 * Email addresses of the approvers.
 *
 * @return string[]
 */
function glover_document_library_approver_emails()
{
    $emails = array();

    foreach (glover_document_library_approver_ids() as $user_id) {
        $user = get_userdata($user_id);

        if ($user) {
            $emails[] = $user->user_email;
        }
    }

    return $emails;
}

/**
 * "Questions? Town Clerk: …" for emails to submitters, when a Help Contact
 * is chosen. The email layout's footer holds one link, so it names the
 * first.
 *
 * @return array Footer arguments.
 */
function glover_document_library_email_clerk_footer()
{
    $emails = glover_document_library_help_contact()['emails'];

    if (!$emails) {
        return array();
    }

    $email = $emails[0];

    return array('footer' => 'Questions? Town Clerk:', 'footer_link' => array('label' => $email, 'url' => 'mailto:' . $email));
}

/**
 * A document's tag list for emails.
 *
 * @param int  $post_id  Document ID.
 * @param bool $reviewer Include the reviewer-only tags (duplicate, note).
 * @return array[]
 */
function glover_document_library_email_tags($post_id, $reviewer = false)
{
    $tags = array();
    $original = (int) get_field('document_replaces', $post_id, false);

    if ($reviewer && $original && get_post_status($original) === 'publish') {
        $tags[] = array('label' => 'Updated version', 'changed' => true);
    }

    if (get_field('document_minutes_status', $post_id, false) === 'draft') {
        $tags[] = array('label' => 'Draft minutes', 'changed' => false);
    }

    if ($reviewer && get_post_meta($post_id, '_glover_possible_duplicates', true)) {
        $tags[] = array('label' => 'Possible duplicate', 'changed' => false);
    }

    if ($reviewer && get_post_meta($post_id, 'document_reviewer_note', true)) {
        $tags[] = array('label' => 'Note from submitter', 'changed' => false);
    }

    return $tags;
}

/**
 * Email the approvers a summary of every document waiting for review,
 * newest first. Sent each time a document is submitted.
 *
 * @param int $post_id The document just submitted (unused; the summary lists all).
 */
function glover_document_library_notify_pending($post_id = 0)
{
    $waiting = get_posts(array(
        'post_type' => GLOVER_DOCUMENT_LIBRARY_POST_TYPE,
        'post_status' => 'pending',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids',
        'no_found_rows' => true,
    ));

    if (!$waiting) {
        return false;
    }

    $count = count($waiting);
    $items = array();

    foreach ($waiting as $id) {
        $submitter = glover_document_library_review_submitter($id);
        $boards = implode(', ', array_map('get_the_title', glover_document_library_document_board_ids($id)));
        $items[] = array(
            'title' => get_post_field('post_title', $id, 'raw'),
            'url' => admin_url('post.php?post=' . (int) $id . '&action=edit'),
            'tags' => glover_document_library_email_tags($id, true),
            'meta' => trim($boards . ' · ' . $submitter['name'] . ', ' . get_the_date('M j, g:i a', $id), ' ·'),
        );
    }

    return glover_document_library_mail(
        glover_document_library_approver_emails(),
        sprintf(_n('%d document waiting for review', '%d documents waiting for review', $count), $count),
        array(
            'heading' => sprintf(_n('%d document is waiting for your review', '%d documents are waiting for your review', $count), $count),
            'intro' => $count === 1 ? "It won't appear on the website until you approve it." : 'None of them appear on the website until you approve them.',
            'items' => $items,
            'button' => array('label' => 'Open the Documents screen', 'url' => glover_document_library_review_screen_url('pending')),
            'footer' => "You're an approver for the Town of Glover Document Library.",
        )
    );
}

/**
 * The submitter of a document, or null.
 *
 * @param int $post_id Document ID.
 * @return WP_User|null
 */
function glover_document_library_email_submitter($post_id)
{
    $user = get_userdata((int) get_post_field('post_author', $post_id));

    return $user ? $user : null;
}

/**
 * Approval and waiting-for-review emails are batched. Each new one restarts
 * a quiet period; the email goes when the quiet period passes with nothing
 * new, and never later than the longest wait after the first one queued.
 * Returns are not batched: each goes at once with its own reason.
 */
const GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK = 'glover_document_library_send_approved';
const GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK = 'glover_document_library_send_pending';
const GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE = '_glover_document_approved_queue';
const GLOVER_DOCUMENT_LIBRARY_PENDING_FIRST = 'glover_document_library_pending_notice_first';

/**
 * Seconds of quiet before a batched email goes. 0 sends at once.
 *
 * @return int
 */
function glover_document_library_notify_quiet_seconds()
{
    return max(0, (int) apply_filters('glover_document_library/notify_quiet_seconds', 10 * MINUTE_IN_SECONDS));
}

/**
 * The longest a batched email waits after the first item queued.
 *
 * @return int
 */
function glover_document_library_notify_max_seconds()
{
    return max(0, (int) apply_filters('glover_document_library/notify_max_seconds', 30 * MINUTE_IN_SECONDS));
}

/**
 * (Re)schedule a batched email for the end of the quiet period.
 *
 * @param string $hook  Cron hook.
 * @param array  $args  Hook arguments.
 * @param int    $first When the first item in the batch was queued.
 */
function glover_document_library_schedule_batch($hook, array $args, $first)
{
    $next = wp_next_scheduled($hook, $args);

    if ($next) {
        wp_unschedule_event($next, $hook, $args);
    }

    $when = min(time() + glover_document_library_notify_quiet_seconds(), $first + glover_document_library_notify_max_seconds());
    wp_schedule_single_event(max(time(), $when), $hook, $args);
}

/**
 * Queue "your document is on the website" for its submitter.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_notify_published($post_id)
{
    $submitter = glover_document_library_email_submitter($post_id);

    if ($submitter) {
        glover_document_library_queue_approved($submitter->ID, $post_id, 'published');
    }
}

/**
 * Queue "your updated version is on the website" for the replacement's submitter.
 *
 * @param int $original_id    The published document, now showing the new version.
 * @param int $replacement_id The submission that brought the new version.
 */
function glover_document_library_notify_replaced($original_id, $replacement_id)
{
    $submitter = glover_document_library_email_submitter($replacement_id);

    if ($submitter) {
        glover_document_library_queue_approved($submitter->ID, $original_id, 'replaced');
    }
}

/**
 * Add an approval to a submitter's batch and schedule its email.
 *
 * @param int    $user_id Submitter.
 * @param int    $post_id The published document.
 * @param string $type    published or replaced.
 */
function glover_document_library_queue_approved($user_id, $post_id, $type)
{
    $queue = get_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, true);
    $queue = is_array($queue) && !empty($queue['items']) ? $queue : array('first' => time(), 'items' => array());

    // One entry per document: a second approval of the same one replaces the first.
    $queue['items'][(int) $post_id] = array('type' => $type, 'at' => time());
    update_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, $queue);

    if (!glover_document_library_notify_quiet_seconds()) {
        glover_document_library_send_approved($user_id);
        return;
    }

    glover_document_library_schedule_batch(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, array((int) $user_id), (int) $queue['first']);
}

/**
 * Take a document out of every submitter's batch, e.g. when its approval is undone.
 *
 * @param int $post_id Document ID.
 */
function glover_document_library_unqueue_approved($post_id)
{
    $users = get_users(array('meta_key' => GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, 'fields' => 'ID'));

    foreach ($users as $user_id) {
        $queue = get_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, true);

        if (!is_array($queue) || !isset($queue['items'][(int) $post_id])) {
            continue;
        }

        unset($queue['items'][(int) $post_id]);

        if ($queue['items']) {
            update_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, $queue);
        } else {
            delete_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE);
            wp_clear_scheduled_hook(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, array((int) $user_id));
        }
    }
}
add_action('glover_document_library/replacement_undone', 'glover_document_library_unqueue_approved');

/**
 * Send a submitter's batch: the single email for one document, a summary for several.
 * Documents that are no longer published are left out.
 *
 * @param int $user_id Submitter.
 */
function glover_document_library_send_approved($user_id)
{
    $user = get_userdata((int) $user_id);
    $queue = get_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE, true);
    delete_user_meta($user_id, GLOVER_DOCUMENT_LIBRARY_APPROVED_QUEUE);

    if (!$user || !is_array($queue) || empty($queue['items'])) {
        return;
    }

    $items = array();

    foreach ($queue['items'] as $post_id => $item) {
        if (get_post_status($post_id) === 'publish') {
            $items[$post_id] = $item;
        }
    }

    if (count($items) === 1) {
        $post_id = (int) key($items);
        $item = reset($items);

        if ($item['type'] === 'replaced') {
            glover_document_library_send_replaced_email($user, $post_id, $item['at']);
        } else {
            glover_document_library_send_published_email($user, $post_id, $item['at']);
        }
    } elseif ($items) {
        glover_document_library_send_approved_summary($user, $items);
    }
}
add_action(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, 'glover_document_library_send_approved');

/**
 * "Your document is on the website."
 *
 * @param WP_User $user        Submitter.
 * @param int     $post_id     Document ID.
 * @param int     $approved_at When it was approved.
 */
function glover_document_library_send_published_email($user, $post_id, $approved_at)
{
    $details = glover_document_library_review_details($post_id);
    $title = get_post_field('post_title', $post_id, 'raw');
    $args = array(
        'heading' => 'Your document is on the website',
        'card' => array(
            'title' => $title,
            'tags' => glover_document_library_email_tags($post_id),
            'rows' => array_values(array_filter(array(
                $details['Boards'] !== '' ? array('label' => 'Board', 'value' => $details['Boards']) : null,
                $details['Category'] !== '' ? array('label' => 'Category', 'value' => $details['Category']) : null,
                $details['Document date'] !== '' ? array('label' => 'Document date', 'value' => $details['Document date']) : null,
                array('label' => 'Approved', 'value' => wp_date('M j, Y', $approved_at)),
            ))),
        ),
        'button' => array('label' => 'View the document', 'url' => get_permalink($post_id)),
    );

    if (glover_document_library_is_draft_minutes($post_id)) {
        $args['secondary'] = glover_document_library_draft_minutes_note('these minutes');
    }

    glover_document_library_mail($user->user_email, 'Published: ' . $title, $args + glover_document_library_email_clerk_footer());
}

/**
 * "Your updated version is on the website."
 *
 * @param WP_User $user        The replacement's submitter.
 * @param int     $original_id The published document, now showing the new version.
 * @param int     $approved_at When it was approved.
 */
function glover_document_library_send_replaced_email($user, $original_id, $approved_at)
{
    $details = glover_document_library_review_details($original_id);
    $title = get_post_field('post_title', $original_id, 'raw');

    glover_document_library_mail($user->user_email, 'Update published: ' . $title, array(
        'heading' => 'Your updated version is on the website',
        'intro' => 'Links to this document now open your new version.',
        'card' => array(
            'title' => $title,
            'tags' => array(array('label' => 'Updated version', 'changed' => true)),
            'rows' => array_values(array_filter(array(
                $details['Boards'] !== '' ? array('label' => 'Board', 'value' => $details['Boards']) : null,
                $details['Minutes status'] !== '' ? array('label' => 'Minutes status', 'value' => $details['Minutes status']) : null,
                $details['Document date'] !== '' ? array('label' => 'Document date', 'value' => $details['Document date']) : null,
                array('label' => 'Approved', 'value' => wp_date('M j, Y', $approved_at)),
            ))),
        ),
        'button' => array('label' => 'View the document', 'url' => get_permalink($original_id)),
    ) + glover_document_library_email_clerk_footer());
}

/**
 * One email for several approvals: a line per document, new and updated alike.
 *
 * @param WP_User                                 $user  Submitter.
 * @param array<int,array{type:string,at:int}> $items Published document ID => queue entry.
 */
function glover_document_library_send_approved_summary($user, array $items)
{
    $count = count($items);
    $lines = array();
    $draft_minutes = false;

    foreach ($items as $post_id => $item) {
        $details = glover_document_library_review_details($post_id);
        $tags = $item['type'] === 'replaced'
            ? array(array('label' => 'Updated version', 'changed' => true))
            : glover_document_library_email_tags($post_id);
        $draft_minutes = $draft_minutes || glover_document_library_is_draft_minutes($post_id);
        $lines[] = array(
            'title' => get_post_field('post_title', $post_id, 'raw'),
            'url' => get_permalink($post_id),
            'tags' => $tags,
            'meta' => trim($details['Boards'] . ' · ' . $details['Document date'], ' ·'),
        );
    }

    $args = array(
        'heading' => sprintf('%d of your documents are on the website', $count),
        'intro' => 'Updated versions replace the earlier ones, so their links open the new file.',
        'items' => $lines,
        'button' => array('label' => 'Open the Document Library', 'url' => get_post_type_archive_link(GLOVER_DOCUMENT_LIBRARY_POST_TYPE)),
    );

    if (!wp_list_filter($items, array('type' => 'replaced'))) {
        unset($args['intro']);
    }

    if ($draft_minutes) {
        $args['secondary'] = glover_document_library_draft_minutes_note('draft minutes');
    }

    glover_document_library_mail($user->user_email, sprintf('Published: %d documents', $count), $args + glover_document_library_email_clerk_footer());
}

/**
 * The reminder to send approved minutes once the board approves a draft.
 *
 * @param string $what "these minutes" or "draft minutes".
 * @return array
 */
function glover_document_library_draft_minutes_note($what)
{
    return array(
        'before' => 'When the board approves ' . $what . ', ',
        'label' => 'submit the approved version',
        'url' => glover_document_library_submission_url(),
        'after' => ' as a replacement.',
    );
}

/**
 * Queue the approvers' waiting-for-review email. It lists everything still
 * waiting when it goes, so a batch of uploads sends one email.
 */
function glover_document_library_queue_pending_notice()
{
    if (!glover_document_library_notify_quiet_seconds()) {
        glover_document_library_notify_pending();
        return;
    }

    $first = (int) get_option(GLOVER_DOCUMENT_LIBRARY_PENDING_FIRST);

    if (!$first) {
        $first = time();
        update_option(GLOVER_DOCUMENT_LIBRARY_PENDING_FIRST, $first, false);
    }

    glover_document_library_schedule_batch(GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK, array(), $first);
}

/**
 * Send the approvers' waiting-for-review email when its quiet period ends.
 */
function glover_document_library_send_pending()
{
    delete_option(GLOVER_DOCUMENT_LIBRARY_PENDING_FIRST);
    glover_document_library_notify_pending();
}
add_action(GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK, 'glover_document_library_send_pending');

/**
 * Tell the submitter their document was returned, with the reason.
 *
 * @param int    $post_id Document ID.
 * @param string $reason  The approver's reason.
 */
function glover_document_library_notify_returned($post_id, $reason)
{
    $submitter = glover_document_library_email_submitter($post_id);

    if (!$submitter) {
        return;
    }

    $details = glover_document_library_review_details($post_id);
    $title = get_post_field('post_title', $post_id, 'raw');
    $args = array(
        'heading' => 'Your document needs changes',
        'intro' => "It isn't on the website. Make the changes below and submit it again.",
        'card' => array(
            'title' => $title,
            'rows' => array_values(array_filter(array(
                $details['Boards'] !== '' ? array('label' => 'Board', 'value' => $details['Boards']) : null,
                array('label' => 'Submitted', 'value' => get_the_date('M j, Y, g:i a', $post_id)),
            ))),
        ),
        'note' => array('label' => 'What to change', 'text' => (string) $reason),
        'button' => array('label' => 'Submit a corrected version', 'url' => glover_document_library_submission_url()),
    );
    $requirements = (int) glover_document_library_setting('requirements_page', 0);

    if ($requirements && get_post_status($requirements) === 'publish') {
        $args['secondary'] = array('label' => 'Document requirements', 'url' => get_permalink($requirements));
    }

    glover_document_library_mail($submitter->user_email, 'Needs changes: ' . $title, $args + glover_document_library_email_clerk_footer());
}

/**
 * Batched emails whose send time has passed, oldest first. A batched email
 * only goes when WP-Cron runs, so on a site whose scheduled tasks have
 * stopped they wait here.
 *
 * @param int $late Only those at least this many seconds past due.
 * @return array<int,array{hook:string,args:array,time:int}>
 */
function glover_document_library_overdue_emails($late = 0)
{
    $overdue = array();

    foreach ((array) _get_cron_array() as $time => $hooks) {
        if ($time > time() - $late) {
            continue;
        }

        foreach (array(GLOVER_DOCUMENT_LIBRARY_APPROVED_HOOK, GLOVER_DOCUMENT_LIBRARY_PENDING_HOOK) as $hook) {
            foreach (isset($hooks[$hook]) ? $hooks[$hook] : array() as $event) {
                $overdue[] = array('hook' => $hook, 'args' => $event['args'], 'time' => (int) $time);
            }
        }
    }

    return $overdue;
}

/**
 * Warn on the Documents screen when batched emails are 15 minutes late, with
 * a button to send them and the webmaster to tell.
 */
function glover_document_library_overdue_notice()
{
    if (!isset($_GET['page']) || $_GET['page'] !== GLOVER_DOCUMENT_LIBRARY_REVIEW_PAGE || !glover_document_library_user_can_review()) {
        return;
    }

    $count = count(glover_document_library_overdue_emails(15 * MINUTE_IN_SECONDS));

    if (!$count) {
        return;
    }

    $webmaster = function_exists('glover_site_webmaster') ? glover_site_webmaster() : null;
    $tell = $webmaster
        ? sprintf('<a href="mailto:%s">%s</a>', esc_attr($webmaster->user_email), esc_html($webmaster->display_name))
        : 'the webmaster';
    ?>
    <div class="notice notice-warning">
        <p><strong>Emails Waiting to Send</strong></p>
        <p>
            <?php echo esc_html(sprintf(_n('%d Document Library email is', '%d Document Library emails are', $count), $count)); ?>
            more than 15 minutes late. Send them now, and tell <?php echo $tell; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?> that the site's scheduled tasks may have stopped running.
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="glover_document_send_overdue">
            <?php wp_nonce_field('glover_document_send_overdue'); ?>
            <p><button type="submit" class="button button-primary">Send Them Now</button></p>
        </form>
    </div>
    <?php
}
add_action('admin_notices', 'glover_document_library_overdue_notice');

/**
 * Send Them Now: run every batched email that is past due.
 */
function glover_document_library_handle_send_overdue()
{
    check_admin_referer('glover_document_send_overdue');

    if (!glover_document_library_user_can_review()) {
        wp_die('Only approvers and administrators can send these emails.', 403);
    }

    foreach (glover_document_library_overdue_emails() as $event) {
        wp_unschedule_event($event['time'], $event['hook'], $event['args']);
        do_action_ref_array($event['hook'], $event['args']);
    }

    wp_safe_redirect(glover_document_library_review_screen_url('pending'));
    exit;
}
add_action('admin_post_glover_document_send_overdue', 'glover_document_library_handle_send_overdue');
