<?php
/**
 * The right-hand column of Submit a Document when signed in: who is signed
 * in and the boards they may post for, the upload requirements panel (it
 * opens the requirements dialog) and who to ask with questions.
 */

if (!defined('ABSPATH')) {
    exit;
}

$user = wp_get_current_user();
$name = trim($user->first_name . ' ' . $user->last_name);
$name = $name !== '' ? $name : $user->display_name;
$initials = '';

foreach (array_slice(preg_split('/\s+/', $name), 0, 2) as $word) {
    $initials .= mb_strtoupper(mb_substr($word, 0, 1));
}

$boards = glover_document_library_allowed_boards();
$all_boards = count($boards) === count(glover_document_library_all_boards());
?>
<aside class="submit-document__aside" aria-label="Your Account and Help">
    <section class="submit-account" aria-labelledby="submit-account-name">
        <div class="submit-account__top">
            <span class="submit-account__avatar" aria-hidden="true"><?php echo esc_html($initials); ?></span>
            <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>">Sign Out</a>
        </div>
        <div class="submit-account__who">
            <h2 id="submit-account-name" class="submit-account__name"><?php echo esc_html($name); ?></h2>
            <span class="submit-account__email"><?php echo esc_html($user->user_email); ?></span>
        </div>
        <div class="submit-account__boards">
            <h3 class="submit-account__label">Assigned To</h3>
            <ul class="chip-list">
                <?php if ($all_boards) : ?>
                    <li class="chip chip--small">All Boards</li>
                <?php else : ?>
                    <?php foreach ($boards as $board) : ?>
                        <li class="chip chip--small"><?php echo esc_html($board); ?></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </section>

    <section class="submit-requirements on-forest" aria-labelledby="submit-requirements-heading">
        <span class="submit-requirements__icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"></path><path d="M8.5 12l2.5 2.5 4.5-5"></path></svg>
        </span>
        <h2 id="submit-requirements-heading" class="submit-requirements__heading">Upload Requirements</h2>
        <p>Each document must meet accessibility standards and hold nothing confidential.</p>
        <a class="button button--secondary" href="#glover-requirements" data-dialog-open="glover-requirements">Review the Requirements</a>
    </section>

    <section class="submit-questions" aria-labelledby="submit-questions-heading">
        <h2 id="submit-questions-heading" class="submit-questions__heading">Questions?</h2>
        <p>Town Clerk: <a href="mailto:<?php echo esc_attr(GLOVER_DOCUMENT_LIBRARY_CLERK_EMAIL); ?>"><?php echo esc_html(GLOVER_DOCUMENT_LIBRARY_CLERK_EMAIL); ?></a></p>
    </section>
</aside>
