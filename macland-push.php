<?php
/**
 * Plugin Name: Macland – Tilkynningar (app)
 * Description: Macland iPhone-appið: push-tilkynningar og Live Activity (pöntunarstaða) beint í gegnum Apple (APNs), auk gagna fyrir appið sem eru lesin af vefnum sjálfum.
 * Version: 1.5.5
 * Author: Macland
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Macland_Push
{
    const VERSION = '1.5.5';
    const OPTION = 'macland_push_settings';
    const LOG_OPTION = 'macland_push_log';
    const TABLE = 'macland_push_devices';
    const LA_TABLE = 'macland_push_activities';
    const LA_POLL_HOOK = 'macland_push_la_poll';
    const JWT_TRANSIENT = 'macland_push_apns_jwt';
    const CRON_HOOK = 'macland_push_send_batch';

    /** Skilaboð fyrir pöntunarstöður. %s = pöntunarnúmer. */
    const ORDER_MESSAGES = [
        'processing' => ['Pöntun #%s móttekin', 'Takk fyrir viðskiptin. Við látum þig vita þegar pöntunin er tilbúin.'],
        'on-hold'    => ['Pöntun #%s bíður greiðslu', 'Pöntunin verður afgreidd um leið og greiðsla berst.'],
        'completed'  => ['Pöntun #%s afgreidd', 'Pöntunin þín er tilbúin. Sjáðu nánar í Mínar síður.'],
        'cancelled'  => ['Pöntun #%s afturkölluð', 'Pöntunin hefur verið afturkölluð. Hafðu samband á sala@macland.is ef þetta er ekki rétt.'],
        'refunded'   => ['Pöntun #%s endurgreidd', 'Endurgreiðsla hefur verið framkvæmd.'],
    ];

    public static function init(): void
    {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('woocommerce_init', [__CLASS__, 'register_store_api_data']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_admin_post']);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_order_status'], 10, 4);
        add_action('transition_post_status', [__CLASS__, 'on_post_publish'], 10, 3);
        add_action(self::CRON_HOOK, [__CLASS__, 'send_batch'], 10, 1);
        add_action(self::LA_POLL_HOOK, [__CLASS__, 'la_poll']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        // Sjálfvirkar uppfærslur úr private GitHub-geymslu
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'gh_check_update']);
        add_filter('plugins_api', [__CLASS__, 'gh_plugin_info'], 20, 3);
        add_filter('http_request_args', [__CLASS__, 'gh_request_args'], 10, 2);
        add_filter('upgrader_source_selection', [__CLASS__, 'gh_source_selection'], 10, 4);
        add_filter('auto_update_plugin', [__CLASS__, 'gh_auto_update'], 10, 2);
    }

    public static function cron_schedules(array $schedules): array
    {
        $schedules['macland_15min'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Á 15 mínútna fresti (Macland)'];
        return $schedules;
    }

    // ---------------------------------------------------------------------
    // Uppsetning
    // ---------------------------------------------------------------------

    public static function activate(): void
    {
        self::create_table();
        self::schedule_poll();
        update_option('macland_push_db_version', self::VERSION);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('macland_push_db_version') !== self::VERSION) {
            self::create_table();
            self::schedule_poll();
            update_option('macland_push_db_version', self::VERSION);
        }
    }

    private static function schedule_poll(): void
    {
        if (!wp_next_scheduled(self::LA_POLL_HOOK)) {
            wp_schedule_event(time() + 60, 'macland_15min', self::LA_POLL_HOOK);
        }
    }

    private static function la_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::LA_TABLE;
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    private static function create_table(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(200) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            orders tinyint(1) NOT NULL DEFAULT 1,
            marketing tinyint(1) NOT NULL DEFAULT 1,
            news tinyint(1) NOT NULL DEFAULT 1,
            sandbox tinyint(1) NOT NULL DEFAULT 0,
            app_version varchar(32) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id)
        ) {$charset};");
        // Live Activity: push-to-start-tókar (kind=start, order_id=0) og uppfærslutókar hverrar virkni (kind=update).
        $la = self::la_table();
        dbDelta("CREATE TABLE {$la} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(200) NOT NULL,
            kind varchar(10) NOT NULL DEFAULT 'update',
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sandbox tinyint(1) NOT NULL DEFAULT 0,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            ended tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY order_id (order_id),
            KEY user_id (user_id)
        ) {$charset};");
    }

    public static function settings(): array
    {
        $defaults = [
            'team_id' => '',
            'key_id' => '',
            'private_key' => '',
            'bundle_id' => 'is.macland.ios',
            'auto_news' => 1,
            'auto_orders' => 1,
            'auto_la' => 1,
            'github_repo' => '',
            'github_token' => '',
        ];
        return array_merge($defaults, (array) get_option(self::OPTION, []));
    }

    // ---------------------------------------------------------------------
    // REST: appið skráir tæki
    // ---------------------------------------------------------------------

    public static function register_routes(): void
    {
        register_rest_route('macland/v1', '/push/register', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_register'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => ['required' => true, 'type' => 'string'],
                'orders' => ['required' => false, 'type' => 'boolean'],
                'marketing' => ['required' => false, 'type' => 'boolean'],
                'news' => ['required' => false, 'type' => 'boolean'],
                'sandbox' => ['required' => false, 'type' => 'boolean'],
                'app_version' => ['required' => false, 'type' => 'string'],
            ],
        ]);
        register_rest_route('macland/v1', '/home', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_home'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('macland/v1', '/account/delete', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_delete_account'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('macland/v1', '/push/unregister', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_unregister'],
            'permission_callback' => '__return_true',
            'args' => ['token' => ['required' => true, 'type' => 'string']],
        ]);
        register_rest_route('macland/v1', '/me', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_me'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('macland/v1', '/nav', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_nav'],
            'permission_callback' => '__return_true',
            'args' => ['path' => ['required' => true, 'type' => 'string']],
        ]);
        register_rest_route('macland/v1', '/addons/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_addons'],
            'permission_callback' => '__return_true',
        ]);
        // Live Activity (pöntunarstaða)
        register_rest_route('macland/v1', '/liveactivity/pushtostart', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_la_pushtostart'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => ['required' => true, 'type' => 'string'],
                'sandbox' => ['required' => false, 'type' => 'boolean'],
                'orders' => ['required' => false, 'type' => 'boolean'],
            ],
        ]);
        register_rest_route('macland/v1', '/liveactivity/token', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_la_token'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => ['required' => true, 'type' => 'string'],
                'order_id' => ['required' => true, 'type' => 'integer'],
                'sandbox' => ['required' => false, 'type' => 'boolean'],
            ],
        ]);
        register_rest_route('macland/v1', '/liveactivity/order/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_la_order'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ---------------------------------------------------------------------
    // Store API: aukagögn með hverri vöru (forsala) + valkostir (YITH add-ons) fyrir appið
    // ---------------------------------------------------------------------

    /** Bætir "macland" við extensions á /wc/store/v1/products: forsölutexti eins og á vefnum. */
    public static function register_store_api_data(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'product',
            'namespace' => 'macland',
            'data_callback' => function ($product) {
                $id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
                $parent = $product instanceof WC_Product && $product->get_parent_id() ? $product->get_parent_id() : $id;
                return ['forsala' => self::forsala_text($parent) ?: self::forsala_text($id)];
            },
            'schema_callback' => function () {
                return ['forsala' => ['description' => 'Forsölutexti', 'type' => ['string', 'null'], 'readonly' => true]];
            },
            'schema_type' => ARRAY_A,
        ]);
    }

    /** "Forsala hefst 16. október kl. 12.00" ef _ml_forsala er í framtíðinni, annars null. Sama orðalag og á vefnum. */
    public static function forsala_text(int $product_id): ?string
    {
        $raw = get_post_meta($product_id, '_ml_forsala', true);
        if ($raw === '' || $raw === null) {
            return null;
        }
        try {
            $ts = is_numeric($raw) ? (int) $raw : (new DateTime((string) $raw, wp_timezone()))->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
        if (!$ts || $ts <= time()) {
            return null;
        }
        // Orðalagið sjálft er tekið af vörusíðunni svo það sé nákvæmlega eins og á vefnum (10 mín skyndiminni).
        $key = 'macland_forsala_txt_' . $product_id;
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $text = '';
        $url = get_permalink($product_id);
        if ($url) {
            $res = wp_remote_get($url, ['timeout' => 15, 'headers' => ['User-Agent' => 'Macland-App-Forsala/1.0']]);
            if (!is_wp_error($res) && preg_match_all('/Forsala hefst[^<"]{0,60}/u', wp_remote_retrieve_body($res), $m)) {
                // Fyrsta tilvikið getur verið í skriftu með \u00f3-kóðun; tökum hreinan texta ef hann finnst, annars afkóðum.
                $plain = array_values(array_filter($m[0], function ($t) { return strpos($t, '\\u') === false; }));
                $raw = $plain ? $plain[0] : $m[0][0];
                $decoded = json_decode('"' . str_replace('"', '\\"', $raw) . '"');
                $text = trim(html_entity_decode(is_string($decoded) ? $decoded : $raw, ENT_QUOTES, 'UTF-8'));
            }
        }
        if ($text === '') {
            $months = ['', 'janúar', 'febrúar', 'mars', 'apríl', 'maí', 'júní', 'júlí', 'ágúst', 'september', 'október', 'nóvember', 'desember'];
            $text = sprintf('Forsala hefst %d. %s kl. %s', (int) wp_date('j', $ts), $months[(int) wp_date('n', $ts)], wp_date('H.i', $ts));
        }
        set_transient($key, $text, 10 * MINUTE_IN_SECONDS);
        return $text;
    }

    /**
     * Valkostir vöru (YITH WooCommerce Product Add-ons) nákvæmlega eins og vefurinn birtir þá:
     * vörusíðan er sótt og .yith-wapo-addon lesin (heiti, valkostir, verð, sjálfgefið val). 10 mín skyndiminni.
     */
    public static function rest_addons(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        $key = 'macland_app_addons_' . $id;
        $cached = get_transient($key);
        if (is_array($cached) && $request->get_param('fresh') === null) {
            return $cached;
        }
        $data = ['product_id' => $id, 'addons' => self::parse_addons($id)];
        set_transient($key, $data, 10 * MINUTE_IN_SECONDS);
        return $data;
    }


    /**
     * Flakkröndin (mlnav) á vöruflokka- og vörusíðum, eins og vefurinn birtir hana: heiti, mynd og tengill.
     * ?path=/voruflokkur/iphone/ (bara slóðir á þessum vef). 10 mín skyndiminni.
     */
    public static function rest_nav(WP_REST_Request $request)
    {
        $path = '/' . ltrim((string) $request->get_param('path'), '/');
        $path = preg_replace('#[^A-Za-z0-9\-_/%.]#', '', $path);
        $key = 'macland_app_nav_' . md5($path);
        $cached = get_transient($key);
        if (is_array($cached) && $request->get_param('fresh') === null) {
            return $cached;
        }
        $data = ['path' => $path, 'tiles' => self::parse_nav(home_url($path))];
        set_transient($key, $data, 10 * MINUTE_IN_SECONDS);
        return $data;
    }

    private static function parse_nav(string $url): array
    {
        $res = wp_remote_get($url, ['timeout' => 20, 'headers' => ['User-Agent' => 'Macland-App-Nav/1.0']]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200 || !class_exists('DOMDocument')) {
            return [];
        }
        $html = wp_remote_retrieve_body($res);
        if (strpos($html, 'mlnav') === false) {
            return [];
        }
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xp = new DOMXPath($doc);
        $nav = $xp->query('//nav[contains(concat(" ", normalize-space(@class), " "), " mlnav ")]')->item(0);
        if (!$nav) {
            return [];
        }
        $tiles = [];
        foreach ($xp->query('.//a[contains(concat(" ", normalize-space(@class), " "), " mlnav__item ")]', $nav) as $a) {
            $label = $xp->query('.//*[contains(@class, "mlnav__label")]', $a)->item(0);
            $img = $xp->query('.//img', $a)->item(0);
            $title = $label ? trim($label->textContent) : trim($a->textContent);
            $src = $img ? (string) ($img->getAttribute('data-src') ?: $img->getAttribute('src')) : '';
            $href = (string) $a->getAttribute('href');
            if ($title === '' || $href === '') {
                continue;
            }
            $tiles[] = [
                'id' => sanitize_title($title) . '-' . substr(md5($href), 0, 6),
                'title' => $title,
                'image' => $src !== '' ? $src : null,
                'link' => $href,
            ];
        }
        return $tiles;
    }

    private static function parse_addons(int $product_id): array
    {
        $url = get_permalink($product_id);
        if (!$url) {
            return [];
        }
        $res = wp_remote_get($url, ['timeout' => 20, 'headers' => ['User-Agent' => 'Macland-App-Addons/1.0']]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200 || !class_exists('DOMDocument')) {
            return [];
        }
        $html = wp_remote_retrieve_body($res);
        if (strpos($html, 'yith-wapo-addon') === false) {
            return [];
        }
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xp = new DOMXPath($doc);
        $addons = [];
        foreach ($xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " yith-wapo-addon ")]') as $node) {
            $addon_id = (int) preg_replace('/\D/', '', (string) $node->getAttribute('id'));
            if (!$addon_id) {
                continue;
            }
            $title_node = $xp->query('.//*[contains(@class, "wapo-addon-title")]', $node)->item(0);
            $title = $title_node ? trim($title_node->textContent) : '';
            $type = (string) $node->getAttribute('data-addon-type');
            $options = [];
            $single = true;
            foreach ($xp->query('.//input[contains(@name, "yith_wapo[")]', $node) as $input) {
                $name = (string) $input->getAttribute('name');
                if (!preg_match('/\[(\d+)-(\d+)\]/', $name, $m)) {
                    continue;
                }
                $wrapper = $input->parentNode;
                if ($wrapper instanceof DOMElement && strpos((string) $wrapper->getAttribute('class'), 'selection-multiple') !== false) {
                    $single = false;
                }
                $options[] = [
                    'index' => (int) $m[2],
                    'field' => $name,
                    'value' => (string) $input->getAttribute('value'),
                    'price' => (int) round((float) $input->getAttribute('data-price')),
                    'price_type' => (string) $input->getAttribute('data-price-type'),
                    'default' => $input->hasAttribute('checked'),
                ];
            }
            if ($title === '' || !$options) {
                continue;
            }
            $addons[] = ['id' => $addon_id, 'title' => $title, 'type' => $type, 'single' => $single, 'options' => $options];
        }
        return $addons;
    }

    public static function rest_register(WP_REST_Request $request)
    {
        global $wpdb;
        $token = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) $request->get_param('token')));
        if (strlen($token) < 32) {
            return new WP_Error('macland_push_token', 'Ógilt tæki.', ['status' => 400]);
        }
        // Innskráður notandi tengist tækinu; annars nafnlaust tæki (bara tilboð/fréttir).
        $user_id = self::user_from_cookie($request);
        $now = current_time('mysql', true);
        $row = [
            'token' => $token,
            'user_id' => $user_id,
            'orders' => $request->get_param('orders') === null ? 1 : (int) (bool) $request->get_param('orders'),
            'marketing' => $request->get_param('marketing') === null ? 1 : (int) (bool) $request->get_param('marketing'),
            'news' => $request->get_param('news') === null ? 1 : (int) (bool) $request->get_param('news'),
            'sandbox' => (int) (bool) $request->get_param('sandbox'),
            'app_version' => sanitize_text_field((string) $request->get_param('app_version')),
            'updated_at' => $now,
        ];
        $table = self::table();
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE token = %s", $token));
        if ($exists) {
            $wpdb->update($table, $row, ['id' => (int) $exists]);
        } else {
            $row['created_at'] = $now;
            $wpdb->insert($table, $row);
        }
        return ['ok' => true, 'user_id' => $user_id];
    }

    /**
     * Auðkennir notanda út frá wordpress_logged_in-kökunni (kjarninn núllstillir REST-notanda þegar ekkert
     * wp_rest-nonce fylgir) og staðfestir Store API-nonce appsins (haus X-Macland-Nonce) gegn CSRF.
     */
    private static function user_from_cookie(WP_REST_Request $request): int
    {
        $user_id = (int) wp_validate_auth_cookie('', 'logged_in');
        if ($user_id <= 0) {
            return 0;
        }
        $nonce = (string) $request->get_header('X-Macland-Nonce');
        wp_set_current_user($user_id);
        if ($nonce === '' || !wp_verify_nonce($nonce, 'wc_store_api')) {
            wp_set_current_user(0);
            return 0;
        }
        return $user_id;
    }

    // ---------------------------------------------------------------------
    // Forsíða appsins: lesin úr forsíðu macland.is svo appið fylgi vefnum sjálfkrafa
    // ---------------------------------------------------------------------

    public static function rest_home(WP_REST_Request $request)
    {
        $cached = get_transient('macland_app_home');
        if (is_array($cached) && $request->get_param('fresh') === null) {
            return $cached;
        }
        $data = self::parse_home();
        set_transient('macland_app_home', $data, 10 * MINUTE_IN_SECONDS);
        return $data;
    }

    /**
     * Sækir forsíðuna eins og gestur sér hana og les út flokkaflísar (tenglar með mynd + stuttum texta)
     * og kynningarspjöld (dálkar með fyrirsögn, mynd, texta og „Skoða…"-hnappi). Litur spjalds kemur úr CSS.
     *
     * @return array{tiles: array, cards: array, source: string}
     */
    private static function parse_home(): array
    {
        $url = apply_filters('macland_app_home_url', home_url('/'));
        $empty = ['tiles' => [], 'cards' => [], 'source' => $url];
        $res = wp_remote_get($url, ['timeout' => 15, 'headers' => ['User-Agent' => 'Macland-App-Home/1.0']]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            return $empty;
        }
        $html = wp_remote_retrieve_body($res);
        if (!class_exists('DOMDocument')) {
            return $empty;
        }

        // CSS: inline <style> + Breakdance-skrár, til að lesa bakgrunnslit spjalda.
        $css = '';
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $html, $m)) {
            $css .= implode("\n", $m[1]);
        }
        if (preg_match_all('/<link[^>]+href=["\']([^"\']*breakdance[^"\']*\.css[^"\']*)["\']/i', $html, $m)) {
            foreach (array_unique($m[1]) as $href) {
                $r = wp_remote_get(html_entity_decode($href), ['timeout' => 10]);
                if (!is_wp_error($r)) {
                    $css .= "\n" . wp_remote_retrieve_body($r);
                }
            }
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $text = static fn(DOMNode $n) => trim(preg_replace('/\s+/u', ' ', $n->textContent));
        $img = static function (DOMNode $n) use ($xp) {
            $i = $xp->query('.//img', $n)->item(0);
            if (!$i) {
                return null;
            }
            foreach (['data-src', 'src'] as $a) {
                $v = $i->getAttribute($a);
                if ($v !== '' && strpos($v, 'data:') !== 0) {
                    return $v;
                }
            }
            return null;
        };
        $bg = static function (string $classes) use ($css): string {
            foreach (preg_split('/\s+/', $classes) as $c) {
                if ($c === '' || strpos($c, 'bde-') !== 0) {
                    continue;
                }
                if (preg_match('/\.' . preg_quote($c, '/') . '(?![\w-])[^{]*\{[^}]*?background(?:-color)?\s*:\s*([^;}]+)/i', $css, $mm)) {
                    return trim($mm[1]);
                }
            }
            return '';
        };
        $isCyan = static function (string $color): bool {
            $c = strtolower(str_replace(' ', '', $color));
            return $c !== '' && (strpos($c, '#00a9cc') !== false || strpos($c, '#00a6ce') !== false || strpos($c, 'rgb(0,169,204') !== false || strpos($c, 'rgb(0,166,206') !== false);
        };

        // Flísar: fyrsta section með a.m.k. 4 tengla sem innihalda mynd og stutt heiti.
        $tiles = [];
        foreach ($xp->query('//section') as $section) {
            $found = [];
            $withImage = 0;
            foreach ($xp->query('.//a', $section) as $a) {
                $t = $text($a);
                if ($t === '' || mb_strlen($t) > 20 || preg_match('/^(Skoða|Sjá|Kaupa|Lesa)/u', $t)) {
                    continue;
                }
                $image = $img($a);
                if ($image !== null) {
                    $withImage++;
                }
                $found[] = ['id' => sanitize_title($t), 'title' => $t, 'image' => $image, 'link' => $a->getAttribute('href')];
            }
            // Flokkaröðin: a.m.k. 4 tenglar með mynd (Þjónusta má vera myndlaus).
            if ($withImage >= 4) {
                $tiles = $found;
                break;
            }
        }

        // Spjöld: dálkar með fyrirsögn + mynd + hnapp.
        $cards = [];
        $seen = [];
        foreach ($xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " bde-column ")]') as $col) {
            $h = $xp->query('.//h1|.//h2|.//h3|.//h4', $col)->item(0);
            $btn = null;
            foreach ($xp->query('.//a', $col) as $a) {
                if (preg_match('/^(Skoða|Sjá|Kaupa|Lesa)/u', $text($a))) {
                    $btn = $a;
                    break;
                }
            }
            $image = $img($col);
            if (!$h || !$btn || !$image) {
                continue;
            }
            $title = $text($h);
            if ($title === '' || isset($seen[$title])) {
                continue;
            }
            $seen[$title] = true;
            $sub = '';
            foreach ($xp->query('.//p|.//*[contains(@class,"bde-text")]', $col) as $p) {
                $pt = $text($p);
                if ($pt !== '' && $pt !== $title && !preg_match('/^(Skoða|Sjá|Kaupa|Lesa)/u', $pt)) {
                    $sub = $pt;
                    break;
                }
            }
            // Forsölulína á spjaldi fylgir vörunni sjálfri (_ml_forsala), ekki föstum texta á forsíðunni:
            // í forsölu → "Forsala hefst 16. október", annars engin forsölulína. Sama regla og á vörusíðum.
            $link = $btn->getAttribute('href');
            if (preg_match('#/vara/([^/?\#]+)/?#u', $link, $mm)) {
                $post = get_page_by_path(urldecode($mm[1]), OBJECT, 'product');
                if ($post) {
                    $fs = self::forsala_text((int) $post->ID);
                    if ($fs) {
                        $sub = preg_replace('/\s+kl\.\s.*$/u', '', $fs);
                    } elseif (preg_match('/^Forsala/u', $sub)) {
                        $sub = '';
                    }
                }
            }
            $cards[] = [
                'id' => sanitize_title($title),
                'title' => $title,
                'subtitle' => $sub,
                'image' => $image,
                'button' => $text($btn),
                'link' => $btn->getAttribute('href'),
                'style' => $isCyan($bg($col->getAttribute('class'))) ? 'cyan' : 'grey',
            ];
        }

        return ['tiles' => $tiles, 'cards' => $cards, 'source' => $url];
    }

    /**
     * Eyðir aðgangi innskráðs notanda (App Store-krafa 5.1.1(v) fyrir öpp með innskráningu).
     * Pantanir haldast sem gestapantanir (bókhald); notandi, heimilisföng, Apple-tenging og tæki eyðast.
     */
    /** Innskráði notandinn (kökur + Store API-nonce í X-Macland-Nonce); kjarninn hafnar wc_store_api-nonce á /wp/v2/users/me. */
    public static function rest_me(WP_REST_Request $request)
    {
        $user_id = self::user_from_cookie($request);
        if ($user_id <= 0) {
            return new WP_Error('macland_not_logged_in', 'Þú ert ekki innskráð(ur).', ['status' => 401]);
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('macland_no_user', 'Notandi fannst ekki.', ['status' => 404]);
        }
        return [
            'id' => $user_id,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'username' => $user->user_login,
            'slug' => $user->user_nicename,
        ];
    }

    public static function rest_delete_account(WP_REST_Request $request)
    {
        global $wpdb;
        $user_id = self::user_from_cookie($request);
        if ($user_id <= 0) {
            return new WP_Error('macland_not_logged_in', 'Þú ert ekki innskráð(ur).', ['status' => 401]);
        }
        if (user_can($user_id, 'manage_options') || user_can($user_id, 'manage_woocommerce')) {
            return new WP_Error('macland_admin', 'Stjórnendaaðgangi er ekki hægt að eyða úr appinu.', ['status' => 403]);
        }
        $wpdb->delete(self::table(), ['user_id' => $user_id]);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (is_multisite()) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
            $ok = wpmu_delete_user($user_id);
        } else {
            $ok = wp_delete_user($user_id);
        }
        if (!$ok) {
            return new WP_Error('macland_delete_failed', 'Gat ekki eytt aðganginum. Hafðu samband á sala@macland.is.', ['status' => 500]);
        }
        wp_destroy_current_session();
        wp_clear_auth_cookie();
        return ['ok' => true];
    }

    public static function rest_unregister(WP_REST_Request $request)
    {
        global $wpdb;
        $token = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) $request->get_param('token')));
        $wpdb->delete(self::table(), ['token' => $token]);
        return ['ok' => true];
    }

    // ---------------------------------------------------------------------
    // Sjálfvirkar tilkynningar
    // ---------------------------------------------------------------------

    public static function on_order_status($order_id, $from, $to, $order): void
    {
        $settings = self::settings();
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }
        // Ekki tilkynna þegar pöntun er sett í ruslið (WooCommerce keyrir stöðubreytingu í leiðinni)
        // og aldrei sömu stöðu tvisvar fyrir sömu pöntun.
        if ((string) $from === (string) $to
            || doing_action('wp_trash_post') || doing_action('trashed_post') || doing_action('woocommerce_trash_order')
            || (function_exists('get_post_status') && get_post_status((int) $order_id) === 'trash')) {
            return;
        }
        if ((string) $order->get_meta('_ml_push_status') === (string) $to) {
            return;
        }
        $order->update_meta_data('_ml_push_status', (string) $to);
        $order->save_meta_data();
        $la_delivered = false;
        if (!empty($settings['auto_la'])) {
            try {
                $la_delivered = self::la_on_status($order, (string) $to);
            } catch (Throwable $e) {
                self::log(['time' => current_time('mysql'), 'label' => 'Live Activity #' . $order->get_order_number(), 'title' => $to, 'sent' => 0, 'failed' => 1, 'removed' => 0, 'error' => $e->getMessage()]);
            }
        }
        // Live Activity náði símanum: hún sýnir stöðuna sjálf, venjuleg tilkynning yrði tvítekning.
        if ($la_delivered || empty($settings['auto_orders']) || !isset(self::ORDER_MESSAGES[$to])) {
            return;
        }
        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0) {
            return;
        }
        [$title, $body] = self::ORDER_MESSAGES[$to];
        $title = sprintf($title, $order->get_order_number());
        $devices = self::devices(['user_id' => $user_id, 'topic' => 'orders']);
        if (!$devices) {
            return;
        }
        self::queue($devices, [
            'title' => $title,
            'body' => $body,
            'data' => ['type' => 'order', 'id' => (int) $order->get_id(), 'url' => $order->get_view_order_url()],
            'thread' => 'order-' . $order->get_id(),
        ], 'Pöntun #' . $order->get_order_number() . ' → ' . $to);
    }

    public static function on_post_publish($new_status, $old_status, $post): void
    {
        $settings = self::settings();
        if (empty($settings['auto_news']) || $new_status !== 'publish' || $old_status === 'publish' || $post->post_type !== 'post') {
            return;
        }
        $title = html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8');
        $body = wp_strip_all_tags($post->post_excerpt ?: $post->post_content);
        $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
        $body = wp_trim_words($body, 22, '…');
        $devices = self::devices(['topic' => 'news']);
        if (!$devices) {
            return;
        }
        self::queue($devices, [
            'title' => $title,
            'body' => $body,
            'data' => ['type' => 'post', 'id' => (int) $post->ID, 'url' => get_permalink($post)],
            'thread' => 'news',
        ], 'Frétt: ' . $title);
    }


    // ---------------------------------------------------------------------
    // Live Activity: pöntunarstaða á læsiskjá / Dynamic Island
    //
    // Appið skráir push-to-start-tóka (iOS 17.2+) tengdan notanda; þegar pöntun hans fer í processing/on-hold
    // ræsir vefurinn virknina með APNs (event=start). Hver virkni skilar uppfærslutóka sem appið skráir hér;
    // stöðubreytingar eru sendar sem event=update og lokastaða sem event=end.
    // Tegundir og lyklar verða að passa nákvæmlega við Shared/OrderActivity.swift í appinu.
    // ---------------------------------------------------------------------

    private static function la_clean_token($raw): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) $raw));
    }

    private static function la_upsert(array $row): void
    {
        global $wpdb;
        $table = self::la_table();
        $now = current_time('mysql', true);
        $row['updated_at'] = $now;
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE token = %s", $row['token']));
        if ($exists) {
            $wpdb->update($table, $row, ['id' => (int) $exists]);
        } else {
            $row['created_at'] = $now;
            $wpdb->insert($table, $row);
        }
    }

    /** POST /liveactivity/pushtostart {token, sandbox, orders}: tóki sem leyfir vefnum að ræsa virkni á tækinu. */
    public static function rest_la_pushtostart(WP_REST_Request $request)
    {
        $token = self::la_clean_token($request->get_param('token'));
        if (strlen($token) < 32) {
            return new WP_Error('macland_la_token', 'Ógildur tóki.', ['status' => 400]);
        }
        $user_id = self::user_from_cookie($request);
        self::la_upsert([
            'token' => $token,
            'kind' => 'start',
            'order_id' => 0,
            'user_id' => $user_id,
            'sandbox' => (int) (bool) $request->get_param('sandbox'),
            'enabled' => $request->get_param('orders') === null ? 1 : (int) (bool) $request->get_param('orders'),
            'ended' => 0,
        ]);
        return ['ok' => true, 'user_id' => $user_id];
    }

    /** POST /liveactivity/token {order_id, token, sandbox}: uppfærslutóki virkni sem er í gangi. */
    public static function rest_la_token(WP_REST_Request $request)
    {
        $token = self::la_clean_token($request->get_param('token'));
        $order_id = (int) $request->get_param('order_id');
        if (strlen($token) < 32 || $order_id <= 0) {
            return new WP_Error('macland_la_token', 'Ógildur tóki eða pöntun.', ['status' => 400]);
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('macland_la_order', 'Pöntun fannst ekki.', ['status' => 404]);
        }
        $user_id = self::user_from_cookie($request);
        // Tókinn kemur frá tækinu sem sýnir virknina; hann er aðeins tekinn gildur fyrir pöntun sama notanda.
        if ($user_id <= 0 || (int) $order->get_customer_id() !== $user_id) {
            return new WP_Error('macland_la_forbidden', 'Ekki þín pöntun.', ['status' => 403]);
        }
        self::la_upsert([
            'token' => $token,
            'kind' => 'update',
            'order_id' => $order_id,
            'user_id' => $user_id,
            'sandbox' => (int) (bool) $request->get_param('sandbox'),
            'enabled' => 1,
            'ended' => self::la_is_final($order->get_status()) ? 1 : 0,
        ]);
        return ['ok' => true];
    }

    /** GET /liveactivity/order/{id}: attributes + state fyrir staðbundna ræsingu í appinu, og hvort vefurinn hafi þegar ræst. */
    public static function rest_la_order(WP_REST_Request $request)
    {
        global $wpdb;
        $order = wc_get_order((int) $request['id']);
        if (!$order) {
            return new WP_Error('macland_la_order', 'Pöntun fannst ekki.', ['status' => 404]);
        }
        $user_id = self::user_from_cookie($request);
        if ($user_id <= 0 || (int) $order->get_customer_id() !== $user_id) {
            return new WP_Error('macland_la_forbidden', 'Ekki þín pöntun.', ['status' => 403]);
        }
        $has_update = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::la_table() . " WHERE kind = 'update' AND order_id = %d", $order->get_id()
        ));
        return [
            'attributes' => self::la_attributes($order),
            'state' => self::la_state($order, $order->get_status()),
            'started' => $has_update > 0 || (string) $order->get_meta('_ml_la_started') !== '',
        ];
    }

    private static function la_is_final(string $status): bool
    {
        return in_array($status, ['completed', 'cancelled', 'refunded', 'failed', 'dropp-delivered'], true);
    }

    /** Fastir reitir virkninnar (OrderActivityAttributes). */
    public static function la_attributes(WC_Order $order): array
    {
        $names = [];
        $count = 0;
        foreach ($order->get_items() as $item) {
            $names[] = html_entity_decode($item->get_name(), ENT_QUOTES, 'UTF-8');
            $count += (int) $item->get_quantity();
        }
        $items = $names[0] ?? '';
        if ($count > 1) {
            $items .= ' + ' . ($count - 1) . ($count - 1 === 1 ? ' til viðbótar' : ' til viðbótar');
        }
        return [
            'orderNumber' => (string) $order->get_order_number(),
            'orderID' => (int) $order->get_id(),
            'total' => self::la_money((float) $order->get_total()),
            'items' => $items,
            'delivery' => self::la_delivery($order),
        ];
    }

    private static function la_money(float $amount): string
    {
        return number_format($amount, 0, ',', '.') . ' kr.';
    }

    /** Stytt heiti afhendingar: "Hagkaup Spöngin (skápur)", "Afhent á pósthúsi Reykjavík Síðumúla", "Heimsending". */
    public static function la_delivery(WC_Order $order): string
    {
        foreach ($order->get_shipping_methods() as $ship) {
            $title = html_entity_decode((string) $ship->get_name(), ENT_QUOTES, 'UTF-8');
            $method = (string) $ship->get_method_id();
            if (stripos($title, 'heimsending') !== false || stripos($method, 'home') !== false) {
                return 'Heimsending';
            }
            $part = $title;
            if (strpos($part, '|') !== false) {
                $part = trim(substr($part, strrpos($part, '|') + 1));
            }
            if (strpos($part, ' - ') !== false) {
                $part = trim(substr($part, strrpos($part, ' - ') + 3));
            }
            // "N1 Húsavík (N1 Húsavík)" → "N1 Húsavík"
            $part = preg_replace('/^(.+?) \(\1\)$/u', '$1', $part);
            return $part !== '' ? $part : $title;
        }
        return '';
    }

    private static function la_is_pickup(WC_Order $order): bool
    {
        return self::la_delivery($order) !== 'Heimsending';
    }

    /**
     * Staða virkninnar (ContentState) út frá WooCommerce-stöðu eða Dropp-stöðu.
     * $status: pending|on-hold|processing|completed|cancelled|refunded|failed|dropp-booked|dropp-transit|dropp-delivered
     */
    public static function la_state(WC_Order $order, string $status): array
    {
        $place = self::la_delivery($order);
        $pickup = self::la_is_pickup($order);
        $ended = false;
        switch ($status) {
            case 'pending':
            case 'on-hold':
                $step = 0;
                $title = 'Bíður greiðslu';
                $detail = 'Afgreidd um leið og greiðsla berst.';
                break;
            case 'processing':
                $step = 1;
                $title = 'Pöntun móttekin';
                $detail = 'Við erum að taka pöntunina til.';
                break;
            case 'dropp-booked':
                $step = 2;
                $title = 'Pöntunin er send';
                $detail = $pickup ? 'Dropp kemur henni í ' . $place . '.' : 'Dropp keyrir hana heim til þín.';
                break;
            case 'dropp-transit':
                $step = 2;
                $title = 'Pöntunin er á leiðinni';
                $detail = $pickup ? 'Á leið í ' . $place . '.' : 'Bílstjóri Dropp er á leiðinni til þín.';
                break;
            case 'dropp-delivered':
                $step = 3;
                $ended = true;
                $title = $pickup ? 'Tilbúin til afhendingar' : 'Pöntunin er afhent';
                $detail = $pickup ? 'Bíður þín í ' . $place . '.' : 'Takk fyrir viðskiptin.';
                break;
            case 'completed':
                $step = 3;
                $ended = true;
                $title = 'Pöntun afgreidd';
                $detail = 'Takk fyrir viðskiptin.';
                break;
            case 'cancelled':
                $step = self::la_last_step($order);
                $ended = true;
                $title = 'Pöntun afturkölluð';
                $detail = 'Ekki rétt? Hafðu samband á sala@macland.is.';
                break;
            case 'refunded':
                $step = self::la_last_step($order);
                $ended = true;
                $title = 'Pöntun endurgreidd';
                $detail = 'Endurgreiðsla hefur verið framkvæmd.';
                break;
            case 'failed':
                $step = 0;
                $ended = true;
                $title = 'Greiðsla mistókst';
                $detail = 'Reyndu aftur eða hafðu samband á sala@macland.is.';
                break;
            default:
                $step = 1;
                $title = 'Pöntun í vinnslu';
                $detail = 'Við látum þig vita þegar staðan breytist.';
        }
        return [
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
            'step' => $step,
            'updatedAt' => (float) time(),
            'ended' => $ended,
        ];
    }

    private static function la_last_step(WC_Order $order): int
    {
        $last = (string) $order->get_meta('_ml_la_step');
        return $last !== '' ? (int) $last : 1;
    }

    /** Uppfærslutókar virkni fyrir pöntun (ekki lokið). */
    private static function la_update_tokens(int $order_id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT token, sandbox FROM " . self::la_table() . " WHERE kind = 'update' AND ended = 0 AND order_id = %d", $order_id
        ));
    }

    /** Push-to-start-tókar notanda (með pöntunartilkynningar kveiktar). */
    private static function la_start_tokens(int $user_id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT token, sandbox FROM " . self::la_table() . " WHERE kind = 'start' AND enabled = 1 AND user_id = %d", $user_id
        ));
    }

    /** Stöðubreyting á pöntun: ræsir, uppfærir eða lýkur virkninni. Skilar true ef Live Activity-push náði tæki. */
    public static function la_on_status(WC_Order $order, string $status): bool
    {
        $delivered = false;
        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0) {
            return false;
        }
        $state = self::la_state($order, $status);
        $label = 'Live Activity #' . $order->get_order_number();
        $updates = self::la_update_tokens($order->get_id());
        if ($updates) {
            $event = $state['ended'] ? 'end' : 'update';
            $result = self::la_send($updates, $event, $state, null, $state['title'], $state['detail'], $state['ended'] ? self::la_dismissal($status) : null);
            self::log(['time' => current_time('mysql'), 'label' => $label . ' → ' . $status, 'title' => $state['title'] . ' (' . $event . ')', 'sent' => $result['sent'], 'failed' => $result['failed'], 'removed' => $result['removed'], 'error' => $result['error']]);
            $delivered = $result['sent'] > 0;
            if ($state['ended']) {
                global $wpdb;
                $wpdb->update(self::la_table(), ['ended' => 1], ['order_id' => $order->get_id(), 'kind' => 'update']);
            }
        } elseif (!$state['ended'] && in_array($status, ['processing', 'on-hold'], true) && (string) $order->get_meta('_ml_la_started') === '') {
            $starts = self::la_start_tokens($user_id);
            if ($starts) {
                $result = self::la_send($starts, 'start', $state, self::la_attributes($order), 'Pöntun #' . $order->get_order_number() . ' móttekin', $state['detail'], null);
                self::log(['time' => current_time('mysql'), 'label' => $label . ' → ' . $status, 'title' => $state['title'] . ' (start)', 'sent' => $result['sent'], 'failed' => $result['failed'], 'removed' => $result['removed'], 'error' => $result['error']]);
                if ($result['sent'] > 0) {
                    $delivered = true;
                    $order->update_meta_data('_ml_la_started', (string) time());
                    $order->save_meta_data();
                }
            }
        }
        if (!$state['ended']) {
            $order->update_meta_data('_ml_la_step', (string) $state['step']);
            $order->save_meta_data();
        }
        return $delivered;
    }

    /** Hvenær kerfið má fjarlægja lokna virkni af læsiskjánum (mest 4 klst. fram í tímann). */
    private static function la_dismissal(string $status): int
    {
        return time() + (in_array($status, ['completed', 'dropp-delivered'], true) ? 3 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS);
    }

    /**
     * Dropp: virknin fylgir sendingunni. Keyrt á 15 mín. fresti fyrir pantanir með virka Live Activity:
     * bókun → "send", transit → "á leiðinni", delivered → "tilbúin til afhendingar" (lýkur virkninni).
     */
    public static function la_poll(): void
    {
        global $wpdb;
        if (!class_exists('\Dropp\Models\Dropp_Consignment')) {
            return;
        }
        $order_ids = (array) $wpdb->get_col("SELECT DISTINCT order_id FROM " . self::la_table() . " WHERE kind = 'update' AND ended = 0 AND order_id > 0");
        foreach ($order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            if (!$order || $order->get_status() !== 'processing') {
                continue;
            }
            try {
                $consignments = \Dropp\Models\Dropp_Consignment::from_order($order);
            } catch (Throwable $e) {
                continue;
            }
            $dropp = '';
            foreach ($consignments as $c) {
                try {
                    $c->maybe_update();
                } catch (Throwable $e) {
                }
                $st = (string) $c->status;
                if ($st === 'delivered') {
                    $dropp = 'dropp-delivered';
                } elseif ($st === 'transit' && $dropp !== 'dropp-delivered') {
                    $dropp = 'dropp-transit';
                } elseif (in_array($st, ['ready', 'initial', 'consignment'], true) && $dropp === '') {
                    $dropp = 'dropp-booked';
                }
            }
            if ($dropp === '' || $dropp === (string) $order->get_meta('_ml_la_dropp')) {
                continue;
            }
            $order->update_meta_data('_ml_la_dropp', $dropp);
            $order->save_meta_data();
            try {
                self::la_on_status($order, $dropp);
            } catch (Throwable $e) {
                self::log(['time' => current_time('mysql'), 'label' => 'Live Activity #' . $order->get_order_number(), 'title' => $dropp, 'sent' => 0, 'failed' => 1, 'removed' => 0, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Sendir Live Activity-push (apns-push-type: liveactivity) á tóka.
     * $event: start|update|end. $attributes aðeins með start.
     * @return array{sent:int, failed:int, removed:int, error:string}
     */
    public static function la_send(array $tokens, string $event, array $state, ?array $attributes, string $alert_title, string $alert_body, ?int $dismissal): array
    {
        $settings = self::settings();
        $aps = [
            'timestamp' => time(),
            'event' => $event,
            'content-state' => $state,
            'alert' => ['title' => $alert_title, 'body' => $alert_body],
        ];
        if ($event === 'start') {
            $aps['attributes-type'] = 'OrderActivityAttributes';
            $aps['attributes'] = (array) $attributes;
            $aps['alert']['sound'] = 'default';
        }
        if ($event === 'end' && $dismissal) {
            $aps['dismissal-date'] = $dismissal;
        }
        $json = wp_json_encode(['aps' => $aps], JSON_UNESCAPED_UNICODE);
        $headers = [
            'apns-topic: ' . $settings['bundle_id'] . '.push-type.liveactivity',
            'apns-push-type: liveactivity',
            'apns-priority: 10',
            'apns-expiration: ' . (time() + 3600),
        ];
        return self::apns_post($tokens, $json, $headers, self::la_table());
    }

    // ---------------------------------------------------------------------
    // Tæki
    // ---------------------------------------------------------------------

    /**
     * @param array{user_id?:int, topic?:string} $args
     * @return array<int, object>
     */
    public static function devices(array $args = []): array
    {
        global $wpdb;
        $table = self::table();
        $where = ['1=1'];
        $params = [];
        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if (!empty($args['topic']) && in_array($args['topic'], ['orders', 'marketing', 'news'], true)) {
            $where[] = $args['topic'] . ' = 1';
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where);
        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        return (array) $wpdb->get_results($sql);
    }

    public static function device_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table());
    }

    // ---------------------------------------------------------------------
    // Sending
    // ---------------------------------------------------------------------

    /**
     * Setur sendingu í biðröð (WP-Cron) svo pöntunarvinnsla og vistun frétta tefjist ekki.
     * Fáar tilkynningar (≤ 20 tæki) fara strax.
     */
    public static function queue(array $devices, array $message, string $label = ''): void
    {
        $tokens = array_map(static fn($d) => ['token' => $d->token, 'sandbox' => (int) $d->sandbox], $devices);
        if (count($tokens) <= 20) {
            self::send_batch(['tokens' => $tokens, 'message' => $message, 'label' => $label]);
            return;
        }
        foreach (array_chunk($tokens, 200) as $i => $chunk) {
            wp_schedule_single_event(time() + $i, self::CRON_HOOK, [['tokens' => $chunk, 'message' => $message, 'label' => $label]]);
        }
    }

    public static function send_batch($job): void
    {
        if (!is_array($job) || empty($job['tokens']) || empty($job['message'])) {
            return;
        }
        $result = self::send_to_tokens($job['tokens'], $job['message']);
        self::log([
            'time' => current_time('mysql'),
            'label' => (string) ($job['label'] ?? ''),
            'title' => (string) ($job['message']['title'] ?? ''),
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'removed' => $result['removed'],
            'error' => $result['error'],
        ]);
    }

    /**
     * Sendir eina tilkynningu á lista af tækjum með HTTP/2 + JWT (ES256).
     * Ógild tæki (410 Unregistered / 400 BadDeviceToken) eru fjarlægð úr töflunni.
     *
     * @return array{sent:int, failed:int, removed:int, error:string}
     */
    public static function send_to_tokens(array $tokens, array $message): array
    {
        $settings = self::settings();
        $payload = [
            'aps' => [
                'alert' => ['title' => (string) $message['title'], 'body' => (string) $message['body']],
                'sound' => 'default',
            ],
            'macland' => (array) ($message['data'] ?? []),
        ];
        if (!empty($message['thread'])) {
            $payload['aps']['thread-id'] = (string) $message['thread'];
        }
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = [
            'apns-topic: ' . $settings['bundle_id'],
            'apns-push-type: alert',
            'apns-priority: 10',
            'apns-expiration: ' . (time() + 86400),
        ];
        return self::apns_post($tokens, $json, $headers, self::table());
    }

    /**
     * Sendir eitt JSON-skeyti á lista af tókum með HTTP/2 + JWT (ES256).
     * Ógildir tókar (410 Unregistered / 400 BadDeviceToken) eru fjarlægðir úr $table.
     *
     * @param array<int, array{token:string, sandbox:int}|object|string> $tokens
     * @return array{sent:int, failed:int, removed:int, error:string}
     */
    private static function apns_post(array $tokens, string $json, array $extra_headers, string $table): array
    {
        $settings = self::settings();
        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'error' => ''];
        if (!$tokens) {
            return $result;
        }
        $jwt = self::apns_jwt($settings);
        if (is_wp_error($jwt)) {
            $result['error'] = $jwt->get_error_message();
            $result['failed'] = count($tokens);
            return $result;
        }
        $headers = array_merge(['authorization: bearer ' . $jwt, 'content-type: application/json'], $extra_headers);

        $multi = curl_multi_init();
        $handles = [];
        foreach ($tokens as $entry) {
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            $token = is_array($entry) ? (string) $entry['token'] : (string) $entry;
            $sandbox = is_array($entry) ? !empty($entry['sandbox']) : false;
            $host = $sandbox ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
            $ch = curl_init($host . '/3/device/' . $token);
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = [$ch, $token];
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        global $wpdb;
        foreach ($handles as [$ch, $token]) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $body = (string) curl_multi_getcontent($ch);
            if ($code === 200) {
                $result['sent']++;
            } else {
                $result['failed']++;
                $reason = '';
                if ($body !== '') {
                    $decoded = json_decode($body, true);
                    $reason = (string) ($decoded['reason'] ?? '');
                }
                if ($code === 0) {
                    $reason = curl_error($ch) ?: 'Engin svörun (curl án HTTP/2?)';
                }
                if ($code === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic', 'ExpiredToken'], true)) {
                    $wpdb->delete($table, ['token' => $token]);
                    $result['removed']++;
                }
                if ($result['error'] === '' && $reason !== '') {
                    $result['error'] = $code . ' ' . $reason;
                }
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $result;
    }

    /** JWT fyrir APNs (ES256), endurnýtt í 50 mínútur (Apple leyfir allt að 60). */
    private static function apns_jwt(array $settings)
    {
        $cached = get_transient(self::JWT_TRANSIENT);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if ($settings['team_id'] === '' || $settings['key_id'] === '' || $settings['private_key'] === '') {
            return new WP_Error('macland_push_config', 'Vantar Team ID, Key ID eða APNs-lykil í stillingum.');
        }
        $key = openssl_pkey_get_private($settings['private_key']);
        if (!$key) {
            return new WP_Error('macland_push_key', 'APNs-lykillinn (.p8) er ekki gildur: ' . openssl_error_string());
        }
        $header = self::b64url(wp_json_encode(['alg' => 'ES256', 'kid' => $settings['key_id']]));
        $claims = self::b64url(wp_json_encode(['iss' => $settings['team_id'], 'iat' => time()]));
        $signing_input = $header . '.' . $claims;
        $der = '';
        if (!openssl_sign($signing_input, $der, $key, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('macland_push_sign', 'Gat ekki undirritað JWT.');
        }
        $raw = self::der_to_raw_signature($der);
        if ($raw === null) {
            return new WP_Error('macland_push_sign', 'Undirskrift á röngu sniði.');
        }
        $jwt = $signing_input . '.' . self::b64url($raw);
        set_transient(self::JWT_TRANSIENT, $jwt, 50 * MINUTE_IN_SECONDS);
        return $jwt;
    }

    /** DER (SEQUENCE { INTEGER r, INTEGER s }) → 64 bæta r||s eins og JWT ES256 krefst. */
    private static function der_to_raw_signature(string $der): ?string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) {
            return null;
        }
        $seq_len = ord($der[$offset++]);
        if ($seq_len & 0x80) {
            $offset += $seq_len & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset++]) !== 0x02) {
                return null;
            }
            $len = ord($der[$offset++]);
            $int = substr($der, $offset, $len);
            $offset += $len;
            $int = ltrim($int, "\x00");
            $parts[] = str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }
        return $parts[0] . $parts[1];
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function log(array $entry): void
    {
        $log = (array) get_option(self::LOG_OPTION, []);
        array_unshift($log, $entry);
        update_option(self::LOG_OPTION, array_slice($log, 0, 30), false);
    }


    // ---------------------------------------------------------------------
    // Uppfærslur úr GitHub (private geymsla, fine-grained token með Contents: read)
    //
    // Nýjasta útgáfan = nýjasta "release" í geymslunni (tag t.d. 1.5.0). WordPress sýnir hana undir
    // Updates / Plugins eins og hvert annað plugin og setur hana sjálfkrafa inn (auto_update_plugin = true).
    // ---------------------------------------------------------------------

    const GH_TRANSIENT = 'macland_push_gh_release';

    private static function gh_repo(): string
    {
        return trim((string) self::settings()['github_repo'], "/ ");
    }

    /** Sækir nýjasta release (tag + zip-slóð), geymt í 1 klst. */
    public static function gh_latest(bool $force = false)
    {
        $repo = self::gh_repo();
        if ($repo === '') {
            return null;
        }
        $cached = get_site_transient(self::GH_TRANSIENT);
        if (!$force && is_array($cached) && !empty($cached['version'])) {
            return $cached;
        }
        $res = wp_remote_get('https://api.github.com/repos/' . $repo . '/releases/latest', ['timeout' => 15]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            set_site_transient(self::GH_TRANSIENT, ['version' => '', 'error' => is_wp_error($res) ? $res->get_error_message() : (string) wp_remote_retrieve_response_code($res)], 15 * MINUTE_IN_SECONDS);
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        $tag = (string) ($data['tag_name'] ?? '');
        $version = ltrim($tag, 'vV');
        if ($version === '') {
            return null;
        }
        $release = [
            'version' => $version,
            'tag' => $tag,
            'package' => 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode($tag),
            'notes' => (string) ($data['body'] ?? ''),
            'published' => (string) ($data['published_at'] ?? ''),
            'url' => (string) ($data['html_url'] ?? 'https://github.com/' . $repo),
        ];
        set_site_transient(self::GH_TRANSIENT, $release, HOUR_IN_SECONDS);
        return $release;
    }

    public static function gh_check_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $release = self::gh_latest();
        $basename = plugin_basename(__FILE__);
        if ($release && version_compare($release['version'], self::VERSION, '>')) {
            $transient->response[$basename] = (object) [
                'id' => 'github.com/' . self::gh_repo(),
                'slug' => 'macland-push',
                'plugin' => $basename,
                'new_version' => $release['version'],
                'url' => $release['url'],
                'package' => $release['package'],
                'icons' => [],
                'banners' => [],
                'tested' => '',
                'requires_php' => '7.4',
            ];
        } else {
            unset($transient->response[$basename]);
            $transient->no_update[$basename] = (object) [
                'id' => 'github.com/' . self::gh_repo(),
                'slug' => 'macland-push',
                'plugin' => $basename,
                'new_version' => self::VERSION,
                'url' => 'https://github.com/' . self::gh_repo(),
                'package' => '',
            ];
        }
        return $transient;
    }

    /** "View details" í plugin-listanum. */
    public static function gh_plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'macland-push') {
            return $result;
        }
        $release = self::gh_latest();
        return (object) [
            'name' => 'Macland – Tilkynningar (app)',
            'slug' => 'macland-push',
            'version' => $release['version'] ?? self::VERSION,
            'author' => 'Macland',
            'homepage' => 'https://github.com/' . self::gh_repo(),
            'download_link' => $release['package'] ?? '',
            'sections' => ['description' => 'Push-tilkynningar og Live Activity fyrir Macland-appið.', 'changelog' => nl2br(esc_html($release['notes'] ?? ''))],
            'last_updated' => $release['published'] ?? '',
        ];
    }

    /** GitHub-lykillinn fylgir öllum köllum á api.github.com (líka zip-niðurhali). */
    public static function gh_request_args($args, $url)
    {
        if (strpos((string) $url, 'https://api.github.com/') !== 0) {
            return $args;
        }
        $token = (string) self::settings()['github_token'];
        if ($token === '') {
            return $args;
        }
        $args['headers'] = (array) ($args['headers'] ?? []);
        $args['headers']['Authorization'] = 'Bearer ' . $token;
        // Sama Accept fyrir zip-slóðina: GitHub svarar 415 við application/octet-stream á /zipball/.
        $args['headers']['Accept'] = 'application/vnd.github+json';
        $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
        $args['headers']['User-Agent'] = 'macland-push-updater';
        return $args;
    }

    /** GitHub pakkar möppunni sem "eigandi-geymsla-hash"; WordPress þarf "macland-push". */
    public static function gh_source_selection($source, $remote_source, $upgrader, $hook_extra = [])
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
            return $source;
        }
        global $wp_filesystem;
        $target = trailingslashit($remote_source) . 'macland-push/';
        if (untrailingslashit($source) === untrailingslashit($target)) {
            return $source;
        }
        // Ef zip-skráin hefur "macland-push/" undirmöppu (release-zip sem við búum til sjálf) notum við hana.
        if ($wp_filesystem->exists(trailingslashit($source) . 'macland-push/macland-push.php')) {
            return trailingslashit($source) . 'macland-push/';
        }
        if ($wp_filesystem->move($source, $target, true)) {
            return $target;
        }
        return $source;
    }

    /** Þetta plugin uppfærist alltaf sjálfkrafa þegar ný útgáfa er á GitHub. */
    public static function gh_auto_update($update, $item)
    {
        if (is_object($item) && !empty($item->plugin) && $item->plugin === plugin_basename(__FILE__)) {
            return true;
        }
        return $update;
    }

    // ---------------------------------------------------------------------
    // wp-admin
    // ---------------------------------------------------------------------

    public static function admin_menu(): void
    {
        add_menu_page('Tilkynningar í app', 'App-tilkynningar', 'manage_woocommerce', 'macland-push', [__CLASS__, 'render_admin'], 'dashicons-bell', 56);
    }

    public static function handle_admin_post(): void
    {
        if (!isset($_POST['macland_push_action']) || !current_user_can('manage_woocommerce')) {
            return;
        }
        check_admin_referer('macland_push');
        $action = sanitize_key($_POST['macland_push_action']);

        if ($action === 'save') {
            $settings = self::settings();
            $settings['team_id'] = sanitize_text_field(wp_unslash($_POST['team_id'] ?? ''));
            $settings['key_id'] = sanitize_text_field(wp_unslash($_POST['key_id'] ?? ''));
            $settings['bundle_id'] = sanitize_text_field(wp_unslash($_POST['bundle_id'] ?? 'is.macland.ios'));
            $key = trim((string) wp_unslash($_POST['private_key'] ?? ''));
            if ($key !== '') {
                $settings['private_key'] = $key;
            }
            $settings['auto_news'] = empty($_POST['auto_news']) ? 0 : 1;
            $settings['auto_orders'] = empty($_POST['auto_orders']) ? 0 : 1;
            $settings['auto_la'] = empty($_POST['auto_la']) ? 0 : 1;
            $settings['github_repo'] = sanitize_text_field(wp_unslash($_POST['github_repo'] ?? ''));
            $gh_token = trim((string) wp_unslash($_POST['github_token'] ?? ''));
            if ($gh_token !== '') {
                $settings['github_token'] = $gh_token;
            }
            update_option(self::OPTION, $settings, false);
            delete_transient(self::JWT_TRANSIENT);
            delete_site_transient(self::GH_TRANSIENT);
            delete_site_transient('update_plugins');
            self::redirect('saved');
        }

        if ($action === 'send') {
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            $body = sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''));
            $target = sanitize_key($_POST['target'] ?? 'marketing');
            $link = esc_url_raw(wp_unslash($_POST['link'] ?? ''));
            $product_id = (int) ($_POST['product_id'] ?? 0);
            if ($title === '' || $body === '') {
                self::redirect('empty');
            }
            $data = ['type' => 'none'];
            if ($product_id > 0) {
                $data = ['type' => 'product', 'id' => $product_id];
            } elseif ($link !== '') {
                $data = ['type' => 'url', 'url' => $link];
            }
            if ($target === 'test') {
                $devices = self::devices(['user_id' => get_current_user_id()]);
            } elseif ($target === 'all') {
                $devices = self::devices();
            } else {
                $devices = self::devices(['topic' => in_array($target, ['marketing', 'news'], true) ? $target : 'marketing']);
            }
            if (!$devices) {
                self::redirect('nodevices');
            }
            self::queue($devices, ['title' => $title, 'body' => $body, 'data' => $data, 'thread' => $target], 'Handvirkt (' . $target . ')');
            self::redirect('sent&n=' . count($devices));
        }
    }

    private static function redirect(string $msg): void
    {
        wp_safe_redirect(admin_url('admin.php?page=macland-push&msg=' . $msg));
        exit;
    }

    public static function render_admin(): void
    {
        $s = self::settings();
        $count = self::device_count();
        $mine = count(self::devices(['user_id' => get_current_user_id()]));
        global $wpdb;
        $la_start = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::la_table() . " WHERE kind = 'start' AND enabled = 1");
        $la_active = (int) $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM " . self::la_table() . " WHERE kind = 'update' AND ended = 0");
        $log = (array) get_option(self::LOG_OPTION, []);
        $curl = function_exists('curl_version') ? curl_version() : null;
        $http2 = $curl && defined('CURL_VERSION_HTTP2') && ($curl['features'] & CURL_VERSION_HTTP2);
        $msg = sanitize_key($_GET['msg'] ?? '');
        $notices = [
            'saved' => ['success', 'Stillingar vistaðar.'],
            'sent' => ['success', 'Tilkynning send á ' . (int) ($_GET['n'] ?? 0) . ' tæki. Sjá niðurstöðu í sendingarsögu hér að neðan.'],
            'empty' => ['error', 'Fyrirsögn og texti mega ekki vera tóm.'],
            'nodevices' => ['warning', 'Engin tæki fundust fyrir þetta val.'],
        ];
        ?>
        <div class="wrap">
            <h1>Tilkynningar í Macland-appið</h1>
            <?php if (isset($notices[$msg])): ?>
                <div class="notice notice-<?php echo esc_attr($notices[$msg][0]); ?> is-dismissible"><p><?php echo esc_html($notices[$msg][1]); ?></p></div>
            <?php endif; ?>
            <?php if (!$http2): ?>
                <div class="notice notice-error"><p><strong>curl á þjóninum styður ekki HTTP/2.</strong> Apple krefst HTTP/2 fyrir tilkynningar. Biðja þarf Avista um curl með nghttp2 (curl <?php echo esc_html($curl['version'] ?? '?'); ?>).</p></div>
            <?php endif; ?>

            <p><strong><?php echo (int) $count; ?></strong> tæki skráð · þín tæki: <?php echo (int) $mine; ?> · Live Activity: <?php echo (int) $la_start; ?> tæki geta tekið við ræsingu, <?php echo (int) $la_active; ?> virkni í gangi</p>

            <h2>Senda tilkynningu</h2>
            <form method="post">
                <?php wp_nonce_field('macland_push'); ?>
                <input type="hidden" name="macland_push_action" value="send">
                <table class="form-table" role="presentation">
                    <tr><th><label for="title">Fyrirsögn</label></th><td><input class="regular-text" id="title" name="title" maxlength="60" required></td></tr>
                    <tr><th><label for="body">Texti</label></th><td><textarea class="large-text" id="body" name="body" rows="3" maxlength="180" required></textarea></td></tr>
                    <tr><th>Viðtakendur</th><td>
                        <label><input type="radio" name="target" value="test" checked> Bara mín tæki (prufa)</label><br>
                        <label><input type="radio" name="target" value="all"> Fá allar tilkynningar: öll skráð tæki (<?php echo (int) $count; ?>)</label><br>
                        <label><input type="radio" name="target" value="marketing"> Allir með „Tilboð og nýjungar" kveikt</label><br>
                        <label><input type="radio" name="target" value="news"> Allir með „Fréttir" kveikt</label>
                    </td></tr>
                    <tr><th><label for="product_id">Opna vöru (ID)</label></th><td><input type="number" id="product_id" name="product_id" min="0" class="small-text"> <span class="description">Valfrjálst: appið opnar vörusíðuna þegar ýtt er á tilkynninguna.</span></td></tr>
                    <tr><th><label for="link">Opna slóð</label></th><td><input type="url" class="regular-text" id="link" name="link" placeholder="https://macland.is/..."> <span class="description">Valfrjálst, notað ef engin vara er valin.</span></td></tr>
                </table>
                <?php submit_button('Senda tilkynningu', 'primary', 'submit', false); ?>
            </form>

            <h2>Stillingar</h2>
            <form method="post">
                <?php wp_nonce_field('macland_push'); ?>
                <input type="hidden" name="macland_push_action" value="save">
                <table class="form-table" role="presentation">
                    <tr><th><label for="team_id">Apple Team ID</label></th><td><input class="regular-text" id="team_id" name="team_id" value="<?php echo esc_attr($s['team_id']); ?>" placeholder="99X4CG77VN"></td></tr>
                    <tr><th><label for="key_id">APNs Key ID</label></th><td><input class="regular-text" id="key_id" name="key_id" value="<?php echo esc_attr($s['key_id']); ?>" placeholder="10 stafir"></td></tr>
                    <tr><th><label for="private_key">APNs-lykill (.p8)</label></th><td>
                        <textarea class="large-text code" id="private_key" name="private_key" rows="6" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
                        <p class="description"><?php echo $s['private_key'] !== '' ? 'Lykill er vistaður. Límdu nýjan hér aðeins til að skipta honum út.' : 'Límdu innihald .p8-skrárinnar úr Apple Developer → Keys.'; ?></p>
                    </td></tr>
                    <tr><th><label for="bundle_id">Bundle ID</label></th><td><input class="regular-text" id="bundle_id" name="bundle_id" value="<?php echo esc_attr($s['bundle_id']); ?>"></td></tr>
                    <tr><th><label for="github_repo">GitHub-geymsla</label></th><td><input class="regular-text" id="github_repo" name="github_repo" value="<?php echo esc_attr($s['github_repo']); ?>" placeholder="notandi/macland-push"> <span class="description">Pluginið uppfærir sig sjálfkrafa úr nýjasta release í geymslunni.</span></td></tr>
                    <tr><th><label for="github_token">GitHub-lykill</label></th><td>
                        <input class="regular-text" type="password" id="github_token" name="github_token" placeholder="github_pat_…" autocomplete="off">
                        <p class="description"><?php echo $s['github_token'] !== '' ? 'Lykill er vistaður. Límdu nýjan hér aðeins til að skipta honum út.' : 'Fine-grained token með Contents: Read á geymsluna.'; ?>
                        <?php $rel = self::gh_latest(); if ($rel && !empty($rel['version'])): ?> · Nýjasta útgáfa á GitHub: <strong><?php echo esc_html($rel['version']); ?></strong> (þessi vefur: <?php echo esc_html(self::VERSION); ?>)<?php elseif (is_array($rel = get_site_transient(self::GH_TRANSIENT)) && !empty($rel['error'])): ?> · Náði ekki í GitHub: <?php echo esc_html($rel['error']); ?><?php endif; ?></p>
                    </td></tr>
                    <tr><th>Sjálfvirkt</th><td>
                        <label><input type="checkbox" name="auto_orders" value="1" <?php checked($s['auto_orders']); ?>> Senda viðskiptavini tilkynningu þegar pöntunarstaða breytist</label><br>
                        <label><input type="checkbox" name="auto_news" value="1" <?php checked($s['auto_news']); ?>> Senda tilkynningu þegar ný frétt er birt</label><br>
                        <label><input type="checkbox" name="auto_la" value="1" <?php checked($s['auto_la']); ?>> Live Activity: pöntunarstaða á læsiskjá (ræsist við „Í vinnslu", fylgir Dropp-sendingu, lýkur við afgreiðslu)</label>
                    </td></tr>
                </table>
                <?php submit_button('Vista stillingar', 'secondary', 'submit', false); ?>
            </form>

            <h2>Sendingarsaga</h2>
            <?php if (!$log): ?>
                <p>Engar sendingar enn.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>Tími</th><th>Hvað</th><th>Fyrirsögn</th><th>Sent</th><th>Mistókst</th><th>Fjarlægð tæki</th><th>Villa</th></tr></thead>
                    <tbody>
                    <?php foreach ($log as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['time'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['label'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['title'] ?? ''); ?></td>
                            <td><?php echo (int) ($row['sent'] ?? 0); ?></td>
                            <td><?php echo (int) ($row['failed'] ?? 0); ?></td>
                            <td><?php echo (int) ($row['removed'] ?? 0); ?></td>
                            <td><?php echo esc_html($row['error'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

Macland_Push::init();
