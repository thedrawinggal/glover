<?php
/**
 * Site Contacts: the Webmaster and Town Clerk chosen on Settings > Site
 * Contacts. The options page and its fields are ACF Local JSON
 * (ui_options_page_glover_site_contacts, group_glover_site_contacts).
 */

if (!defined('ABSPATH')) {
    exit;
}

const GLOVER_SITE_CONTACT_ROLES = array('webmaster', 'town_clerk');

/**
 * The user chosen for a site contact role.
 *
 * @param string $role 'webmaster' or 'town_clerk'.
 * @return WP_User|null Null when nobody is chosen or the user no longer exists.
 */
function glover_site_contact($role)
{
    if (!in_array($role, GLOVER_SITE_CONTACT_ROLES, true) || !function_exists('get_field')) {
        return null;
    }

    $user_id = (int) get_field('site_contacts_' . $role, 'option');
    $user = $user_id ? get_userdata($user_id) : false;

    return $user instanceof WP_User ? $user : null;
}

/**
 * @return WP_User|null
 */
function glover_site_webmaster()
{
    return glover_site_contact('webmaster');
}

/**
 * @return WP_User|null
 */
function glover_site_town_clerk()
{
    return glover_site_contact('town_clerk');
}
