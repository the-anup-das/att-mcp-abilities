<?php
/**
 * Ability registry — admin-facing metadata (label, description, group, access,
 * default) for every ability. The MCP-facing descriptions the agent reads live
 * with each wp_register_ability() call in includes/abilities/.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Compact registry entry builder. */
function att_mcp_registry_entry( $label, $description, $group, $access, $default = false ) {
    return array(
        'label'       => $label,
        'description' => $description,
        'group'       => $group,
        'access'      => $access,
        'default'     => (bool) $default,
    );
}

function att_mcp_ability_registry() {
    static $cache = null;
    if ( null !== $cache ) {
        return $cache;
    }

    $e = 'att_mcp_registry_entry';
    $abilities = array(
        // POSTS
        'att/get-posts'   => $e( __( 'Read Posts', 'att-mcp-abilities' ),  __( 'List published blog posts (title, URL, date, excerpt, categories, tags).', 'att-mcp-abilities' ), 'Posts', 'read', true ),
        'att/create-post' => $e( __( 'Create Post', 'att-mcp-abilities' ), __( 'Create a blog post (title, content, status, categories, tags, excerpt, slug, featured image, meta).', 'att-mcp-abilities' ), 'Posts', 'write' ),
        'att/update-post' => $e( __( 'Update Post', 'att-mcp-abilities' ), __( 'Update an existing post by ID (content, status, terms, featured image, meta…).', 'att-mcp-abilities' ), 'Posts', 'write' ),
        'att/delete-post' => $e( __( 'Delete Post', 'att-mcp-abilities' ), __( 'Move a post to trash by ID.', 'att-mcp-abilities' ), 'Posts', 'write' ),
        // PAGES
        'att/get-pages'   => $e( __( 'Read Pages', 'att-mcp-abilities' ),  __( 'List published pages (title, URL, parent, status).', 'att-mcp-abilities' ), 'Pages', 'read', true ),
        'att/create-page' => $e( __( 'Create Page', 'att-mcp-abilities' ), __( 'Create a page (title, content, status, parent, slug, template, order, featured image, meta).', 'att-mcp-abilities' ), 'Pages', 'write' ),
        'att/update-page' => $e( __( 'Update Page', 'att-mcp-abilities' ), __( 'Update an existing page by ID (content, template, parent, order, featured image, meta…).', 'att-mcp-abilities' ), 'Pages', 'write' ),
        'att/delete-page' => $e( __( 'Delete Page', 'att-mcp-abilities' ), __( 'Move a page to trash by ID.', 'att-mcp-abilities' ), 'Pages', 'write' ),
        // CONTENT (any post type)
        'att/list-content'   => $e( __( 'List Content', 'att-mcp-abilities' ),   __( 'List any content type (pages, posts, patterns, custom post types) in any status, including drafts.', 'att-mcp-abilities' ), 'Content', 'read' ),
        'att/get-content'    => $e( __( 'Read Content', 'att-mcp-abilities' ),   __( 'Read one item by ID with its full raw content (block markup), template, terms, featured image and meta.', 'att-mcp-abilities' ), 'Content', 'read' ),
        'att/create-content' => $e( __( 'Create Content', 'att-mcp-abilities' ), __( 'Create an item of any content type (e.g. a reusable pattern, a custom post type entry).', 'att-mcp-abilities' ), 'Content', 'write' ),
        'att/update-content' => $e( __( 'Update Content', 'att-mcp-abilities' ), __( 'Update any content item by ID: content, title, status, template, parent, terms, featured image, meta.', 'att-mcp-abilities' ), 'Content', 'write' ),
        'att/delete-content' => $e( __( 'Delete Content', 'att-mcp-abilities' ), __( 'Trash (or permanently delete) any content item by ID.', 'att-mcp-abilities' ), 'Content', 'write' ),
        // TAXONOMY
        'att/get-categories'  => $e( __( 'Read Categories', 'att-mcp-abilities' ), __( 'List all post categories with IDs, slugs, and post counts.', 'att-mcp-abilities' ), 'Taxonomy', 'read', true ),
        'att/create-category' => $e( __( 'Create Category', 'att-mcp-abilities' ), __( 'Create a new post category.', 'att-mcp-abilities' ), 'Taxonomy', 'write' ),
        'att/get-tags'        => $e( __( 'Read Tags', 'att-mcp-abilities' ),       __( 'List all post tags with IDs, slugs, and post counts.', 'att-mcp-abilities' ), 'Taxonomy', 'read', true ),
        'att/create-tag'      => $e( __( 'Create Tag', 'att-mcp-abilities' ),      __( 'Create a new post tag.', 'att-mcp-abilities' ), 'Taxonomy', 'write' ),
        'att/get-terms'       => $e( __( 'Read Terms', 'att-mcp-abilities' ),      __( 'List terms of any taxonomy (e.g. product categories).', 'att-mcp-abilities' ), 'Taxonomy', 'read' ),
        'att/update-term'     => $e( __( 'Update Term', 'att-mcp-abilities' ),     __( 'Rename or edit a category, tag, or other term.', 'att-mcp-abilities' ), 'Taxonomy', 'write' ),
        'att/delete-term'     => $e( __( 'Delete Term', 'att-mcp-abilities' ),     __( 'Delete a category, tag, or other term.', 'att-mcp-abilities' ), 'Taxonomy', 'write' ),
        // COMMENTS
        'att/get-comments'    => $e( __( 'Read Comments', 'att-mcp-abilities' ),   __( 'List comments with author, status, and content snippet.', 'att-mcp-abilities' ), 'Comments', 'read' ),
        'att/approve-comment' => $e( __( 'Approve Comment', 'att-mcp-abilities' ), __( 'Approve a pending comment by ID.', 'att-mcp-abilities' ), 'Comments', 'write' ),
        'att/delete-comment'  => $e( __( 'Delete Comment', 'att-mcp-abilities' ),  __( 'Move a comment to trash by ID.', 'att-mcp-abilities' ), 'Comments', 'write' ),
        // MEDIA
        'att/get-media'    => $e( __( 'Read Media', 'att-mcp-abilities' ),   __( 'List media library items (title, URL, MIME type, date).', 'att-mcp-abilities' ), 'Media', 'read' ),
        'att/upload-media' => $e( __( 'Upload Media', 'att-mcp-abilities' ), __( 'Upload a file (URL/content/base64) to the Media Library; sanitizes SVG; can set it as the site logo.', 'att-mcp-abilities' ), 'Media', 'write' ),
        'att/update-media' => $e( __( 'Update Media', 'att-mcp-abilities' ), __( "Edit a media item's title, alt text, caption, and description.", 'att-mcp-abilities' ), 'Media', 'write' ),
        // USERS
        'att/get-users' => $e( __( 'Read Users', 'att-mcp-abilities' ), __( 'List users with display name, email, and role.', 'att-mcp-abilities' ), 'Users', 'read' ),
        // SEARCH
        'att/search' => $e( __( 'Search Content', 'att-mcp-abilities' ), __( 'Search posts and pages by keyword.', 'att-mcp-abilities' ), 'Search', 'read', true ),
        // MENUS
        'att/get-menus'   => $e( __( 'Read Menus', 'att-mcp-abilities' ),  __( 'List navigation menus, their items, and assigned/available theme locations.', 'att-mcp-abilities' ), 'Menus', 'read' ),
        'att/create-menu' => $e( __( 'Create Menu', 'att-mcp-abilities' ), __( 'Create a navigation menu, optionally with (nested) items and a theme location.', 'att-mcp-abilities' ), 'Menus', 'write' ),
        'att/update-menu' => $e( __( 'Update Menu', 'att-mcp-abilities' ), __( 'Rename a menu, add/edit/remove/reorder items, and assign theme locations.', 'att-mcp-abilities' ), 'Menus', 'write' ),
        'att/delete-menu' => $e( __( 'Delete Menu', 'att-mcp-abilities' ), __( 'Delete a navigation menu by id or name.', 'att-mcp-abilities' ), 'Menus', 'write' ),
        // SITE
        'att/get-site-info'        => $e( __( 'Read Site Info', 'att-mcp-abilities' ),       __( 'Return site name, URL, tagline, WP version, and language.', 'att-mcp-abilities' ), 'Site', 'read', true ),
        'att/get-plugins'          => $e( __( 'Read Plugins', 'att-mcp-abilities' ),         __( 'List installed plugins with name, version, author, and active state.', 'att-mcp-abilities' ), 'Site', 'read' ),
        'att/get-themes'           => $e( __( 'Read Themes', 'att-mcp-abilities' ),          __( 'List installed themes and which one is active.', 'att-mcp-abilities' ), 'Site', 'read' ),
        'att/get-site-settings'    => $e( __( 'Read Site Settings', 'att-mcp-abilities' ),   __( 'Site title, tagline, homepage, posts page, permalinks, timezone, formats, icon and logo.', 'att-mcp-abilities' ), 'Site', 'read' ),
        'att/update-site-settings' => $e( __( 'Update Site Settings', 'att-mcp-abilities' ), __( 'Change site title, tagline, homepage/posts page, permalinks, timezone, formats, site icon and logo.', 'att-mcp-abilities' ), 'Site', 'write' ),
        // HISTORY (undo)
        'att/list-changes'     => $e( __( 'Read Change History', 'att-mcp-abilities' ), __( 'List recent MCP changes that can be undone (options, theme mods, CSS, settings, builder data, meta).', 'att-mcp-abilities' ), 'History', 'read' ),
        'att/undo-change'      => $e( __( 'Undo Change', 'att-mcp-abilities' ),         __( 'Restore the values an MCP change overwrote (latest change, or by change id).', 'att-mcp-abilities' ), 'History', 'write' ),
        'att/list-revisions'   => $e( __( 'Read Revisions', 'att-mcp-abilities' ),      __( 'List saved revisions of a post, page, template, or global styles.', 'att-mcp-abilities' ), 'History', 'read' ),
        'att/restore-revision' => $e( __( 'Restore Revision', 'att-mcp-abilities' ),    __( 'Roll a post, page, template, or global styles back to an earlier revision.', 'att-mcp-abilities' ), 'History', 'write' ),
        // PERFORMANCE
        'att/audit-performance'     => $e( __( 'Audit Performance', 'att-mcp-abilities' ),          __( 'Find what slows the site down (server, database, autoloaded options, cron, cache plugins and a real page fetch) with prioritised fixes.', 'att-mcp-abilities' ), 'Performance', 'read' ),
        'att/pagespeed-insights'    => $e( __( 'PageSpeed Insights', 'att-mcp-abilities' ),         __( 'Run Google PageSpeed Insights on a page: Lighthouse scores, Core Web Vitals and the biggest opportunities (sends the page URL to Google).', 'att-mcp-abilities' ), 'Performance', 'read' ),
        'att/get-cache-config'      => $e( __( 'Read Cache Configuration', 'att-mcp-abilities' ),   __( 'Read the LiteSpeed Cache / Super Page Cache performance settings with recommendations. Credentials are never exposed.', 'att-mcp-abilities' ), 'Performance', 'read' ),
        'att/update-cache-config'   => $e( __( 'Update Cache Configuration', 'att-mcp-abilities' ), __( 'Change LiteSpeed Cache / Super Page Cache settings, or apply a LiteSpeed preset, through the plugin\'s own settings API. Undoable.', 'att-mcp-abilities' ), 'Performance', 'write' ),
        'att/optimize-database'     => $e( __( 'Optimize Database', 'att-mcp-abilities' ),          __( 'Delete old revisions, auto-drafts, spam and trashed comments, expired transients and orphaned meta (dry run by default; not undoable).', 'att-mcp-abilities' ), 'Performance', 'write' ),
        'att/set-option-autoload'   => $e( __( 'Set Option Autoload', 'att-mcp-abilities' ),        __( 'Stop large options from loading on every request (or turn autoloading back on). Undoable.', 'att-mcp-abilities' ), 'Performance', 'write' ),
        'att/regenerate-thumbnails' => $e( __( 'Regenerate Thumbnails', 'att-mcp-abilities' ),      __( 'Create missing image sizes so pages can serve smaller images.', 'att-mcp-abilities' ), 'Performance', 'write' ),
        // SEO
        'att/analyze-post'    => $e( __( 'Analyze Post SEO', 'att-mcp-abilities' ), __( 'On-page SEO and readability checks for one post on its rendered page, with a score, fixes and internal-link suggestions.', 'att-mcp-abilities' ), 'SEO', 'read' ),
        'att/seo-audit'       => $e( __( 'SEO Audit', 'att-mcp-abilities' ),        __( 'Audit many posts at once (titles, descriptions, duplicates, thin content, alt text, links) plus site-wide SEO problems.', 'att-mcp-abilities' ), 'SEO', 'read' ),
        'att/update-seo-meta' => $e( __( 'Update SEO Meta', 'att-mcp-abilities' ),  __( 'Set the SEO title, meta description, focus keyword, canonical and indexing in Yoast SEO, Rank Math, All in One SEO or SEOPress. Undoable.', 'att-mcp-abilities' ), 'SEO', 'write' ),
        // DESIGN
        'att/get-active-theme'  => $e( __( 'Read Active Theme', 'att-mcp-abilities' ),     __( 'Active theme: name, version, parent/child, block-vs-classic, and supports.', 'att-mcp-abilities' ), 'Design', 'read' ),
        'att/render-page'       => $e( __( 'Render Page HTML', 'att-mcp-abilities' ),      __( 'Fetch the front-end HTML of a post/page (drafts included, as a preview) so the agent can target real CSS classes.', 'att-mcp-abilities' ), 'Design', 'read' ),
        'att/fetch-url'         => $e( __( 'Fetch Reference URL', 'att-mcp-abilities' ),   __( 'Fetch a public web page (and optionally its stylesheets) so the agent can study a reference design.', 'att-mcp-abilities' ), 'Design', 'read' ),
        'att/get-custom-css'    => $e( __( 'Read Additional CSS', 'att-mcp-abilities' ),   __( 'Read the Customizer "Additional CSS" for the active theme.', 'att-mcp-abilities' ), 'Design', 'read' ),
        'att/update-custom-css' => $e( __( 'Update Additional CSS', 'att-mcp-abilities' ), __( 'Write the Customizer "Additional CSS" (visual finishing only). Undoable.', 'att-mcp-abilities' ), 'Design', 'write' ),
        'att/get-theme-mods'    => $e( __( 'Read Theme Mods', 'att-mcp-abilities' ),       __( 'Read all Customizer theme mods (colors, fonts, layout values).', 'att-mcp-abilities' ), 'Design', 'read' ),
        'att/set-theme-mod'     => $e( __( 'Set Theme Mod', 'att-mcp-abilities' ),         __( 'Set a single Customizer theme mod the active theme reads. Undoable.', 'att-mcp-abilities' ), 'Design', 'write' ),
        'att/get-option'        => $e( __( 'Read Option', 'att-mcp-abilities' ),           __( 'Read a named plugin/theme option (core, secret-like and MCP-control options blocked).', 'att-mcp-abilities' ), 'Design', 'read' ),
        'att/update-option'     => $e( __( 'Update Option', 'att-mcp-abilities' ),         __( 'Update a named plugin/theme option (core, secret-like and MCP-control options blocked). Undoable.', 'att-mcp-abilities' ), 'Design', 'write' ),
        'att/purge-cache'       => $e( __( 'Purge Cache', 'att-mcp-abilities' ),           __( 'Flush whichever cache is active (LiteSpeed, SpeedyCache, Super Page Cache, WP Rocket, W3TC, etc.) so changes appear.', 'att-mcp-abilities' ), 'Design', 'write' ),
        // SITE EDITOR (block themes)
        'att/get-block-templates'  => $e( __( 'Read Block Templates', 'att-mcp-abilities' ), __( "List the block theme's templates and template parts (header, footer, …).", 'att-mcp-abilities' ), 'Site Editor', 'read' ),
        'att/get-block-template'   => $e( __( 'Read Block Template', 'att-mcp-abilities' ),  __( 'Read one template or template part with its block markup.', 'att-mcp-abilities' ), 'Site Editor', 'read' ),
        'att/save-block-template'  => $e( __( 'Save Block Template', 'att-mcp-abilities' ),  __( 'Edit a template/template part, create a custom template, or reset one to the theme default.', 'att-mcp-abilities' ), 'Site Editor', 'write' ),
        'att/get-global-styles'    => $e( __( 'Read Global Styles', 'att-mcp-abilities' ),   __( "Read the site's global styles (theme.json colors, typography, spacing).", 'att-mcp-abilities' ), 'Site Editor', 'read' ),
        'att/update-global-styles' => $e( __( 'Update Global Styles', 'att-mcp-abilities' ), __( 'Change global colors, typography, spacing, and block styles.', 'att-mcp-abilities' ), 'Site Editor', 'write' ),
        // ADVANCED
        'att/rest-get'      => $e( __( 'REST API Read', 'att-mcp-abilities' ),               __( 'Call any WordPress REST API GET route as the connected user (e.g. widgets, block navigation, patterns).', 'att-mcp-abilities' ), 'Advanced', 'read' ),
        'att/rest-write'    => $e( __( 'REST API Write', 'att-mcp-abilities' ),              __( 'Call a WordPress REST API POST/PUT/PATCH/DELETE route as the connected user. Users, plugins, settings, batch and credential routes are blocked.', 'att-mcp-abilities' ), 'Advanced', 'write' ),
        'att/manage-plugin' => $e( __( 'Install / Activate Plugins', 'att-mcp-abilities' ), __( 'Install plugins from WordPress.org and activate or deactivate them.', 'att-mcp-abilities' ), 'Advanced', 'write' ),
        'att/manage-theme'  => $e( __( 'Install / Activate Themes', 'att-mcp-abilities' ),  __( 'Install themes from WordPress.org and switch the active theme.', 'att-mcp-abilities' ), 'Advanced', 'write' ),
    );

    if ( function_exists( 'generate_get_defaults' ) ) {
        $abilities += array(
            // GENERATEPRESS
            'att/get-generatepress-settings'    => $e( __( 'Read GeneratePress Settings', 'att-mcp-abilities' ),   __( 'Read GeneratePress settings (layout, container width, global colors, typography, fonts) merged with defaults.', 'att-mcp-abilities' ), 'GeneratePress', 'read' ),
            'att/update-generatepress-settings' => $e( __( 'Update GeneratePress Settings', 'att-mcp-abilities' ), __( 'Change GeneratePress settings (read-merge-write) and regenerate the cached dynamic CSS so it appears. Undoable.', 'att-mcp-abilities' ), 'GeneratePress', 'write' ),
            'att/list-elements'                 => $e( __( 'List GP Elements', 'att-mcp-abilities' ),              __( 'List GeneratePress Elements (gp_elements) — requires GP Premium.', 'att-mcp-abilities' ), 'GeneratePress', 'read' ),
            'att/get-element'                   => $e( __( 'Get GP Element', 'att-mcp-abilities' ),                __( 'Read one GeneratePress Element with its full meta — requires GP Premium.', 'att-mcp-abilities' ), 'GeneratePress', 'read' ),
            'att/save-element'                  => $e( __( 'Save GP Element', 'att-mcp-abilities' ),               __( 'Create or update a GeneratePress Element (hook, block, layout, header) incl. display rules. PHP-executing hooks need the "Allow PHP" control.', 'att-mcp-abilities' ), 'GeneratePress', 'write' ),
            'att/delete-element'                => $e( __( 'Delete GP Element', 'att-mcp-abilities' ),             __( 'Move a GeneratePress Element to trash.', 'att-mcp-abilities' ), 'GeneratePress', 'write' ),
        );
    }

    if ( class_exists( '\Code_Snippets\Snippet' ) ) {
        $abilities += array(
            // CODE SNIPPETS
            'att/get-snippets'   => $e( __( 'Read Snippets', 'att-mcp-abilities' ),  __( 'List Code Snippets entries (name, scope, active state, code).', 'att-mcp-abilities' ), 'Code Snippets', 'read' ),
            'att/save-snippet'   => $e( __( 'Save Snippet', 'att-mcp-abilities' ),   __( 'Create or update a Code Snippet (CSS/JS/HTML; PHP only when the "Allow PHP" control is on).', 'att-mcp-abilities' ), 'Code Snippets', 'write' ),
            'att/delete-snippet' => $e( __( 'Delete Snippet', 'att-mcp-abilities' ), __( 'Delete a Code Snippets entry by ID.', 'att-mcp-abilities' ), 'Code Snippets', 'write' ),
        );
    }

    if ( class_exists( '\Elementor\Plugin' ) ) {
        $abilities += array(
            // ELEMENTOR
            'att/elementor-list-pages'     => $e( __( 'List Elementor Pages', 'att-mcp-abilities' ),  __( 'List pages/posts built with Elementor (title, ID, URL, status).', 'att-mcp-abilities' ), 'Elementor', 'read' ),
            'att/elementor-get-page'       => $e( __( 'Get Page Structure', 'att-mcp-abilities' ),    __( 'Get the element tree of an Elementor page by post ID.', 'att-mcp-abilities' ), 'Elementor', 'read' ),
            'att/elementor-get-element'    => $e( __( 'Get Element Settings', 'att-mcp-abilities' ),  __( 'Get all settings for a specific element by post ID and element ID.', 'att-mcp-abilities' ), 'Elementor', 'read' ),
            'att/elementor-find-element'   => $e( __( 'Find Element', 'att-mcp-abilities' ),          __( 'Find elements on a page by widget type or settings content search.', 'att-mcp-abilities' ), 'Elementor', 'read' ),
            'att/elementor-list-templates' => $e( __( 'List Templates', 'att-mcp-abilities' ),        __( 'List Elementor saved templates from the library.', 'att-mcp-abilities' ), 'Elementor', 'read' ),
            'att/elementor-get-kit'        => $e( __( 'Read Global Kit', 'att-mcp-abilities' ),       __( 'Read the Elementor Site Settings kit (global colors, fonts, layout).', 'att-mcp-abilities' ), 'Elementor', 'read' ),
            'att/elementor-update-element' => $e( __( 'Update Element', 'att-mcp-abilities' ),        __( 'Update settings for a widget or container by element ID. Undoable.', 'att-mcp-abilities' ), 'Elementor', 'write' ),
            'att/elementor-add-widget'     => $e( __( 'Add Widget', 'att-mcp-abilities' ),            __( 'Add a widget to a container or column on an Elementor page. Undoable.', 'att-mcp-abilities' ), 'Elementor', 'write' ),
            'att/elementor-add-container'  => $e( __( 'Add Container', 'att-mcp-abilities' ),         __( 'Add a layout container or section to an Elementor page. Undoable.', 'att-mcp-abilities' ), 'Elementor', 'write' ),
            'att/elementor-remove-element' => $e( __( 'Remove Element', 'att-mcp-abilities' ),        __( 'Remove a widget or container from an Elementor page by element ID. Undoable.', 'att-mcp-abilities' ), 'Elementor', 'write' ),
            'att/elementor-save-page'      => $e( __( 'Save Page Layout', 'att-mcp-abilities' ),      __( 'Write a whole Elementor element tree to a page (turns Elementor on for that page if needed). Undoable.', 'att-mcp-abilities' ), 'Elementor', 'write' ),
            'att/elementor-update-kit'     => $e( __( 'Update Global Kit', 'att-mcp-abilities' ),     __( 'Change Elementor global colors, fonts, and layout settings. Undoable.', 'att-mcp-abilities' ), 'Elementor', 'write' ),
        );
    }

    /**
     * Filter the full ability registry. Third-party addons add their ability
     * metadata here (pair with the att_mcp_addons filter + the att_mcp_register() helper).
     */
    $abilities = apply_filters( 'att_mcp_abilities', $abilities );

    // Only cache once the theme and plugins are loaded (detection above depends on them).
    if ( did_action( 'init' ) ) {
        $cache = $abilities;
    }
    return $abilities;
}

function att_mcp_get_settings() {
    $saved    = get_option( ATT_MCP_OPTION, array() );
    $saved    = is_array( $saved ) ? $saved : array();
    $settings = array();
    foreach ( att_mcp_ability_registry() as $key => $cfg ) {
        $settings[ $key ] = isset( $saved[ $key ] ) ? (bool) $saved[ $key ] : $cfg['default'];
    }
    return $settings;
}

function att_mcp_is_enabled( $key ) {
    $s = att_mcp_get_settings();
    if ( empty( $s[ $key ] ) ) {
        return false;
    }
    $registry = att_mcp_ability_registry();
    // Read-only safety valve: a write ability is inert while writes are paused.
    if ( function_exists( 'att_mcp_writes_paused' ) && isset( $registry[ $key ]['access'] ) && 'write' === $registry[ $key ]['access'] && att_mcp_writes_paused() ) {
        return false;
    }
    // Addon gate (two-level): the ability's group must belong to an addon that is
    // both available (target detected) and enabled. Abilities with no addon mapping
    // fall through as enabled.
    if ( function_exists( 'att_mcp_addon_for_group' ) && isset( $registry[ $key ]['group'] ) ) {
        $addon = att_mcp_addon_for_group( $registry[ $key ]['group'] );
        if ( $addon && ! att_mcp_addon_is_enabled( $addon ) ) {
            return false;
        }
    }
    return true;
}

/**
 * Sanitize callback for the abilities option. Toggles for abilities that are not
 * shown on the settings screen (their addon's plugin/theme is currently inactive,
 * or they are not in the registry right now) keep their saved value, so
 * temporarily deactivating e.g. Elementor does not wipe its configuration.
 */
function att_mcp_sanitize_settings( $input ) {
    $input = is_array( $input ) ? $input : array();
    $saved = get_option( ATT_MCP_OPTION, array() );
    $clean = array();
    foreach ( ( is_array( $saved ) ? $saved : array() ) as $key => $value ) {
        $clean[ sanitize_text_field( (string) $key ) ] = (bool) $value;
    }
    foreach ( att_mcp_ability_registry() as $key => $cfg ) {
        $addon = function_exists( 'att_mcp_addon_for_group' ) ? att_mcp_addon_for_group( $cfg['group'] ) : '';
        if ( $addon && ! att_mcp_addon_is_available( $addon ) ) {
            continue; // not rendered on the form — keep what was saved
        }
        $clean[ $key ] = ! empty( $input[ $key ] );
    }
    return $clean;
}

function att_mcp_register_settings() {
    register_setting( 'att_mcp_settings_group', ATT_MCP_OPTION, array( 'type' => 'array', 'sanitize_callback' => 'att_mcp_sanitize_settings' ) );
    register_setting( 'att_mcp_settings_group', ATT_MCP_ADDONS_OPTION, array( 'type' => 'array', 'sanitize_callback' => 'att_mcp_sanitize_addons' ) );
    register_setting( 'att_mcp_settings_group', ATT_MCP_CONTROLS_OPTION, array( 'type' => 'array', 'sanitize_callback' => 'att_mcp_sanitize_controls' ) );
}

function att_mcp_register_ability_category() {
    wp_register_ability_category( 'att', array(
        'label'       => 'ATT',
        'description' => 'ATT MCP abilities: read and (when allowed) edit this WordPress site.',
    ) );
}
