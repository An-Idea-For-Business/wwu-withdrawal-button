<?php
/**
 * WWU UI Kit — Selective enqueue loader
 *
 * Consumer plugins call `WWU_UI_Kit_Loader::enqueue( $prefix, $components )`
 * with the ONLY components they need. The loader resolves dependencies
 * (e.g. modal → auto-includes focus-trap + core-js + tokens) and enqueues
 * just the minimum CSS/JS required.
 *
 * For convenience there's also `enqueue_bundle()` which ships the whole
 * kit as two concatenated files (`dist/ui-kit.css` + `dist/ui-kit.js`) —
 * acceptable when a plugin uses many components and the HTTP-round-trip
 * savings beat the KB savings of selective loading.
 *
 * @package   WWU_UI_Kit
 * @since     0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'WWU_UI_Kit_Loader' ) ) {
    // Already loaded by another plugin — first loader wins.
    return;
}

/**
 * Static-only class.
 */
final class WWU_UI_Kit_Loader {

    /**
     * Semantic version of the kit. Must match VERSION file + CHANGELOG.
     *
     * @since 0.1.0
     * @var string
     */
    const VERSION = '0.9.2';

    /**
     * Set of component IDs already enqueued in the current request, keyed by
     * component ID. Used for idempotency (multiple plugins can request the
     * same component — loader enqueues once) and for diagnostics.
     *
     * @since 0.8.0
     * @var array<string,bool>
     */
    private static $enqueued = array();

    /**
     * Whether the tokens + core-js baseline has been enqueued yet.
     *
     * @since 0.8.0
     * @var bool
     */
    private static $baseline_loaded = false;

    /**
     * Component manifest — single source of truth.
     *
     * Each component declares:
     *   - `css`   — filename under `css/` (or null if JS-only)
     *   - `js`    — filename under `js/`  (or null if CSS-only)
     *   - `needs` — array of other component IDs this one requires
     *
     * `tokens` + `core` are the baseline — automatically included by any
     * component that declares a dependency, and always loaded first.
     *
     * @since 0.8.0
     * @return array<string,array{css:?string,js:?string,needs:string[]}>
     */
    private static function manifest() {
        return array(
            // ---------- Foundation ----------
            'tokens'          => array( 'css' => 'tokens.css',        'js' => null,                    'needs' => array() ),
            'core'            => array( 'css' => null,                'js' => 'ui-kit.js',             'needs' => array() ),

            // ---------- Primitives (CSS-only or very small) ----------
            'badge'           => array( 'css' => 'badge.css',         'js' => null,                    'needs' => array() ),
            'utilities'       => array( 'css' => 'utilities.css',     'js' => 'tips.js',               'needs' => array( 'core' ) ),
            'filter-pill'     => array( 'css' => 'filter-pill.css',   'js' => 'filter-pill.js',        'needs' => array( 'core' ) ),
            'skeleton'        => array( 'css' => 'skeleton.css',      'js' => null,                    'needs' => array() ),
            // 0.9.0 — VB-extracted primitives
            'status-chip'     => array( 'css' => 'status-chip.css',   'js' => null,                    'needs' => array() ),
            'breadcrumb'      => array( 'css' => 'breadcrumb.css',    'js' => null,                    'needs' => array() ),
            'code-block'      => array( 'css' => 'code-block.css',    'js' => null,                    'needs' => array() ),
            'segmented'       => array( 'css' => 'segmented.css',     'js' => 'segmented.js',          'needs' => array( 'core', 'aria' ) ),

            // ---------- Layout ----------
            'tabs'            => array( 'css' => 'tabs.css',          'js' => 'tabs.js',               'needs' => array( 'core' ) ),
            'accordion'       => array( 'css' => 'accordion.css',     'js' => 'accordion.js',          'needs' => array( 'core' ) ),
            'save-bar'        => array( 'css' => 'save-bar.css',      'js' => 'save-bar.js',           'needs' => array( 'core' ) ),
            'stepper'         => array( 'css' => 'stepper.css',       'js' => 'stepper.js',            'needs' => array( 'core' ) ),

            // ---------- Data display ----------
            'table'           => array( 'css' => 'table.css',         'js' => null,                    'needs' => array() ),
            'pagination'      => array( 'css' => 'pagination.css',    'js' => 'pagination.js',         'needs' => array( 'core' ) ),
            'rule-card'       => array( 'css' => 'rule-card.css',     'js' => 'rule-card.js',          'needs' => array( 'core' ) ),
            // 0.9.0 — VB-extracted data display
            'tree-row'        => array( 'css' => 'tree-row.css',      'js' => null,                    'needs' => array() ),
            'history-row'     => array( 'css' => 'history-row.css',   'js' => null,                    'needs' => array() ),

            // ---------- Form helpers ----------
            'form-field'      => array( 'css' => 'form-field.css',    'js' => null,                    'needs' => array() ),
            'switch'          => array( 'css' => 'switch.css',        'js' => null,                    'needs' => array() ),
            'token-display'   => array( 'css' => 'token-display.css', 'js' => null,                    'needs' => array() ),
            'repeater'        => array( 'css' => 'repeater.css',      'js' => 'repeater.js',           'needs' => array( 'core' ) ),

            // ---------- Input / upload ----------
            'dropzone'        => array( 'css' => 'dropzone.css',      'js' => 'dropzone.js',           'needs' => array( 'core' ) ),

            // ---------- Overlays / floating ----------
            'toast'           => array( 'css' => 'toast.css',         'js' => 'toast.js',              'needs' => array( 'core' ) ),
            'fab'             => array( 'css' => 'fab.css',           'js' => null,                    'needs' => array() ),
            'drawer'          => array( 'css' => 'drawer.css',        'js' => 'drawer.js',             'needs' => array( 'core', 'focus-trap' ) ),
            'modal'           => array( 'css' => 'modal.css',         'js' => 'modal.js',              'needs' => array( 'core', 'focus-trap' ) ),
            // 0.9.0 — VB-extracted overflow menu (built on popover)
            'overflow'        => array( 'css' => 'overflow.css',      'js' => 'overflow.js',           'needs' => array( 'core', 'popover' ) ),

            // ---------- Behaviour helpers ----------
            'ajax'            => array( 'css' => null,                'js' => 'ajax.js',               'needs' => array( 'core' ) ),
            'markdown'        => array( 'css' => null,                'js' => 'markdown.js',           'needs' => array( 'core' ) ),
            'focus-trap'      => array( 'css' => null,                'js' => 'focus-trap.js',         'needs' => array( 'core' ) ),
            'roving-tabindex' => array( 'css' => null,                'js' => 'roving-tabindex.js',    'needs' => array( 'core' ) ),
            // 0.8.4 — VB-extracted utilities
            'aria'            => array( 'css' => null,                'js' => 'aria.js',               'needs' => array( 'core' ) ),
            'popover'         => array( 'css' => null,                'js' => 'popover.js',            'needs' => array( 'core', 'aria' ) ),
            'save-state'      => array( 'css' => 'save-state.css',    'js' => 'save-state.js',         'needs' => array( 'core' ) ),

            // ---------- Frontend-only ----------
            'debug-bar'       => array( 'css' => 'debug-bar.css',     'js' => 'debug-bar.js',          'needs' => array( 'core' ) ),
        );
    }

    /**
     * Enqueue a specific subset of components. Dependencies are auto-resolved.
     *
     * Example:
     *   WWU_UI_Kit_Loader::enqueue( 'wwu-pm', array(
     *       'toast', 'accordion', 'form-field', 'switch',
     *   ) );
     *   // -> enqueues tokens, core (JS), utilities (hmm no — not needed),
     *   //    accordion CSS+JS, form-field CSS, switch CSS, toast CSS+JS
     *
     * Must be called from a hook like `admin_enqueue_scripts` (admin pages)
     * or `wp_enqueue_scripts` (frontend — only for `debug-bar`, typically).
     *
     * @since 0.8.0
     *
     * @param string   $consumer_prefix  Short plugin identifier (e.g. 'wwu-pm').
     *                                   Emitted in the `wwu_ui_kit_enqueued` action.
     * @param string[] $components       Component IDs to load. Dependencies
     *                                   auto-added. Unknown IDs log a warning.
     * @return void
     */
    public static function enqueue( $consumer_prefix, array $components ) {
        if ( empty( $components ) ) {
            self::warn( 'enqueue() called with empty components list. Nothing loaded.' );
            return;
        }

        $manifest = self::manifest();
        $resolved = self::resolve_dependencies( $components, $manifest );
        $base_url = self::base_url();
        $ver      = self::VERSION;

        // Ensure the baseline (tokens CSS + core JS) loads first.
        self::ensure_baseline( $base_url, $ver );

        foreach ( $resolved as $component_id ) {
            if ( isset( self::$enqueued[ $component_id ] ) ) {
                continue;
            }
            self::$enqueued[ $component_id ] = true;

            $entry = $manifest[ $component_id ];

            if ( $entry['css'] ) {
                wp_enqueue_style(
                    self::handle( $component_id, 'style' ),
                    $base_url . 'css/' . $entry['css'],
                    array( 'wwu-ui-kit-tokens' ),
                    $ver
                );
            }

            if ( $entry['js'] ) {
                $deps = array( 'wwu-ui-kit-core-js' );
                foreach ( $entry['needs'] as $need ) {
                    if ( 'core' === $need ) {
                        continue; // already in base deps
                    }
                    $dep_manifest = $manifest[ $need ] ?? null;
                    if ( $dep_manifest && $dep_manifest['js'] ) {
                        $deps[] = self::handle( $need, 'script' );
                    }
                }
                wp_enqueue_script(
                    self::handle( $component_id, 'script' ),
                    $base_url . 'js/' . $entry['js'],
                    $deps,
                    $ver,
                    true
                );
            }
        }

        /**
         * Fires after a batch of components has been enqueued.
         *
         * @since 0.1.0 (signature unchanged, semantics updated in 0.8.0)
         *
         * @param string   $consumer_prefix
         * @param string   $kit_version
         * @param string[] $resolved         Final list of components loaded
         *                                   (including dependencies).
         */
        do_action( 'wwu_ui_kit_enqueued', $consumer_prefix, $ver, $resolved );
    }

    /**
     * Enqueue the pre-built concatenated bundle.
     *
     * Use this when your plugin uses MANY components (e.g. 10+) and the
     * single-file HTTP round-trip is better than selective loading.
     * Loads everything — same footprint as declaring all components in
     * enqueue(), but fewer HTTP requests.
     *
     * Files served: `dist/ui-kit.css` + `dist/ui-kit.js`.
     *
     * @since 0.8.0
     *
     * @param string $consumer_prefix
     * @return void
     */
    public static function enqueue_bundle( $consumer_prefix ) {
        if ( self::$baseline_loaded ) {
            // Prevent mixing bundle + selective — consumer should pick one strategy.
            self::warn( 'enqueue_bundle() called after selective enqueue(). Bundle skipped to avoid duplicate load.' );
            return;
        }
        self::$baseline_loaded = true;

        $base_url = self::base_url();
        $ver      = self::VERSION;

        wp_enqueue_style(
            'wwu-ui-kit-bundle',
            $base_url . 'dist/ui-kit.css',
            array(),
            $ver
        );

        wp_enqueue_script(
            'wwu-ui-kit-bundle-js',
            $base_url . 'dist/ui-kit.js',
            array(),
            $ver,
            true
        );

        /** @see enqueue() */
        do_action( 'wwu_ui_kit_enqueued', $consumer_prefix, $ver, array( '__bundle__' ) );
    }

    /**
     * Resolve a flat list of requested components into a topologically
     * ordered list including all transitive dependencies.
     *
     * Unknown IDs are skipped with a warning. Circular deps aren't expected
     * (manifest is hand-authored and reviewed), so no cycle detection beyond
     * a recursion depth guard.
     *
     * @since 0.8.0
     *
     * @param string[] $requested
     * @param array    $manifest
     * @return string[]
     */
    private static function resolve_dependencies( array $requested, array $manifest ) {
        $result = array();
        $seen   = array();

        $visit = function ( $id ) use ( &$visit, &$result, &$seen, $manifest ) {
            if ( isset( $seen[ $id ] ) ) {
                return;
            }
            if ( ! isset( $manifest[ $id ] ) ) {
                self::warn( sprintf( 'Unknown component "%s" requested. Skipped.', $id ) );
                return;
            }
            $seen[ $id ] = true;

            foreach ( $manifest[ $id ]['needs'] as $dep ) {
                $visit( $dep );
            }
            // `tokens` and `core` are baseline — ensure_baseline() handles them;
            // don't include in the per-component list.
            if ( 'tokens' === $id || 'core' === $id ) {
                return;
            }
            $result[] = $id;
        };

        foreach ( $requested as $id ) {
            $visit( $id );
        }

        return $result;
    }

    /**
     * Enqueue tokens + core JS. Idempotent.
     *
     * @since 0.8.0
     *
     * @param string $base_url
     * @param string $ver
     * @return void
     */
    private static function ensure_baseline( $base_url, $ver ) {
        if ( self::$baseline_loaded ) {
            return;
        }
        self::$baseline_loaded = true;

        wp_enqueue_style(
            'wwu-ui-kit-tokens',
            $base_url . 'css/tokens.css',
            array(),
            $ver
        );

        wp_enqueue_script(
            'wwu-ui-kit-core-js',
            $base_url . 'js/ui-kit.js',
            array(),
            $ver,
            true
        );
    }

    /**
     * Build the consistent WP handle for a component's style or script.
     *
     * Style handles: `wwu-ui-kit-{id}-style` (or the hardcoded tokens/bundle).
     * Script handles: `wwu-ui-kit-{id}` for JS (shorter).
     *
     * @since 0.8.0
     *
     * @param string $component_id
     * @param 'style'|'script' $type
     * @return string
     */
    private static function handle( $component_id, $type ) {
        if ( 'style' === $type ) {
            return 'wwu-ui-kit-' . $component_id . '-css';
        }
        return 'wwu-ui-kit-' . $component_id;
    }

    /**
     * Compute the base URL of the kit. Works regardless of plugin folder name.
     *
     * __FILE__ is .../wp-content/plugins/SOME-PLUGIN/assets/ui-kit/php/class-ui-kit-loader.php
     * Target is  .../wp-content/plugins/SOME-PLUGIN/assets/ui-kit/
     *
     * @since 0.1.0
     * @return string Trailing-slashed URL.
     */
    private static function base_url() {
        $php_dir = plugin_dir_url( __FILE__ );
        return trailingslashit( dirname( untrailingslashit( $php_dir ) ) );
    }

    /**
     * Log a warning via error_log when WP_DEBUG is enabled. Never throws —
     * the kit should never break the consumer plugin's render.
     *
     * @since 0.8.0
     *
     * @param string $message
     * @return void
     */
    private static function warn( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
            error_log( '[wwu-ui-kit] ' . $message );
        }
    }

    /**
     * Semver of the kit currently loaded.
     *
     * @since 0.1.0
     * @return string
     */
    public static function version() {
        return self::VERSION;
    }

    /**
     * List of component IDs that have been enqueued in this request.
     * Useful for diagnostics / debug bar integration.
     *
     * @since 0.8.0
     * @return string[]
     */
    public static function enqueued_components() {
        return array_keys( self::$enqueued );
    }

    /**
     * Full list of known component IDs. For consumer code that wants to
     * build a UI for picking components (unlikely but possible).
     *
     * @since 0.8.0
     * @return string[]
     */
    public static function available_components() {
        $ids = array_keys( self::manifest() );
        // Exclude baseline entries — users don't pick those.
        return array_values( array_diff( $ids, array( 'tokens', 'core' ) ) );
    }
}
