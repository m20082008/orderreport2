<?php
/**
 * Plugin Name: Woo Processing Orders Report
 * Description: نمایش سفارش‌های «در حال انجام» ووکامرس در جدول همراه با ویرایش آدرس ارسال.
 * Version: 1.0.0
 * Author: Codex Assistant
 * Text Domain: woo-processing-orders-report
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WPR_Processing_Orders_Report')) {
    class WPR_Processing_Orders_Report
    {
        const LABEL_LOG_TABLE_SUFFIX = 'wpr_label_print_logs';

        public static function activate()
        {
            global $wpdb;

            $table_name = $wpdb->prefix . self::LABEL_LOG_TABLE_SUFFIX;
            $charset_collate = $wpdb->get_charset_collate();

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                printed_at DATETIME NOT NULL,
                order_ids LONGTEXT NOT NULL,
                order_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
                package_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
                total_items_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
                product_totals LONGTEXT NOT NULL,
                address_order_groups LONGTEXT NOT NULL,
                created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (id)
            ) {$charset_collate};";

            dbDelta($sql);
        }

        private function ensure_label_log_table_exists()
        {
            global $wpdb;

            $table_name = $wpdb->prefix . self::LABEL_LOG_TABLE_SUFFIX;
            $table_found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
            if ($table_found === $table_name) {
                return;
            }

            self::activate();
        }

        private function normalize_product_name_for_sort($product_name)
        {
            $normalized = strtr((string) $product_name, [
                '۰' => '0',
                '۱' => '1',
                '۲' => '2',
                '۳' => '3',
                '۴' => '4',
                '۵' => '5',
                '۶' => '6',
                '۷' => '7',
                '۸' => '8',
                '۹' => '9',
                '٠' => '0',
                '١' => '1',
                '٢' => '2',
                '٣' => '3',
                '٤' => '4',
                '٥' => '5',
                '٦' => '6',
                '٧' => '7',
                '٨' => '8',
                '٩' => '9',
            ]);

            $normalized = preg_replace('/\s+/u', ' ', trim($normalized));

            if (function_exists('mb_strtolower')) {
                return (string) mb_strtolower((string) $normalized, 'UTF-8');
            }

            return (string) strtolower((string) $normalized);
        }

        private function get_product_display_priority($product_name)
        {
            $normalized_name = $this->normalize_product_name_for_sort($product_name);

            $contains_round_keyword = function_exists('mb_strpos')
                ? mb_strpos($normalized_name, 'گرد') !== false
                : strpos($normalized_name, 'گرد') !== false;
            if ($contains_round_keyword) {
                return 1;
            }

            if (preg_match('/\b4\s*نفره\s*مربع\b/u', $normalized_name)) {
                return 2;
            }

            if (preg_match('/\b4\s*نفره\s*مستطیل\b/u', $normalized_name)) {
                return 3;
            }

            if (preg_match('/\b6\s*نفره\b/u', $normalized_name)) {
                return 4;
            }

            if (preg_match('/\b8\s*نفره\b/u', $normalized_name)) {
                return 5;
            }

            if (preg_match('/\b12\s*نفره\b/u', $normalized_name)) {
                return 6;
            }

            return 7;
        }

        private function sort_product_totals_for_stats_report($product_totals)
        {
            uksort($product_totals, function ($left_name, $right_name) use ($product_totals) {
                $left_priority = $this->get_product_display_priority($left_name);
                $right_priority = $this->get_product_display_priority($right_name);

                if ($left_priority !== $right_priority) {
                    return $left_priority <=> $right_priority;
                }

                $left_quantity = isset($product_totals[$left_name]) ? (int) $product_totals[$left_name] : 0;
                $right_quantity = isset($product_totals[$right_name]) ? (int) $product_totals[$right_name] : 0;
                if ($left_quantity !== $right_quantity) {
                    return $right_quantity <=> $left_quantity;
                }

                return strnatcasecmp((string) $left_name, (string) $right_name);
            });

            return $product_totals;
        }

        private function format_persian_datetime($timestamp)
        {
            return wp_date('Y/m/d H:i:s', (int) $timestamp, wp_timezone());
        }

        private function parse_mysql_datetime_to_wp_timestamp($mysql_datetime)
        {
            $datetime_string = trim((string) $mysql_datetime);
            if ($datetime_string === '') {
                return 0;
            }

            $utc_timestamp = strtotime((string) $datetime_string . ' UTC');
            if ($utc_timestamp === false) {
                return 0;
            }

            return (int) $utc_timestamp;
        }

        private function get_wp_local_timestamp()
        {
            return current_datetime()->getTimestamp();
        }

        private function get_processing_stats_data()
        {
            $orders = wc_get_orders([
                'status' => ['processing'],
                'limit'  => -1,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);

            return $this->build_stats_data_from_orders($orders);
        }

        private function build_stats_data_from_orders($orders)
        {
            if (! is_array($orders)) {
                $orders = [];
            }

            $product_totals = [];
            $total_items_count = 0;
            $address_packages = [];
            $address_order_groups = [];

            foreach ($orders as $order) {
                $state = $order->get_shipping_state();
                $city = $order->get_shipping_city();
                $address_1 = $order->get_shipping_address_1();
                $address_2 = $order->get_shipping_address_2();

                if (empty($state) && empty($city) && empty($address_1) && empty($address_2)) {
                    $state = $order->get_billing_state();
                    $city = $order->get_billing_city();
                    $address_1 = $order->get_billing_address_1();
                    $address_2 = $order->get_billing_address_2();
                }

                $state = $this->get_readable_state($order, $state);
                $full_address = trim(implode(' - ', array_filter([
                    $state,
                    $city,
                    $address_1,
                    $address_2,
                ], static function ($value) {
                    return $value !== null && $value !== '';
                })));

                $address_key = preg_replace('/\s+/u', ' ', trim((string) $full_address));
                if ($address_key === '') {
                    $address_key = '__EMPTY__ORDER__' . $order->get_id();
                }
                $address_packages[$address_key] = true;
                if (! isset($address_order_groups[$address_key])) {
                    $address_order_groups[$address_key] = [];
                }
                $address_order_groups[$address_key][] = (int) $order->get_id();

                foreach ($order->get_items() as $item) {
                    $item_name = $item->get_name();
                    $item_quantity = (int) $item->get_quantity();

                    if (! isset($product_totals[$item_name])) {
                        $product_totals[$item_name] = 0;
                    }
                    $product_totals[$item_name] += $item_quantity;
                    $total_items_count += $item_quantity;
                }
            }

            $product_totals = $this->sort_product_totals_for_stats_report($product_totals);

            return [
                'orders' => $orders,
                'product_totals' => $product_totals,
                'total_items_count' => $total_items_count,
                'address_packages_count' => count($address_packages),
                'address_order_groups' => $address_order_groups,
            ];
        }

        private function get_orders_by_ids($order_ids)
        {
            $normalized_ids = array_values(array_unique(array_filter(array_map('absint', (array) $order_ids))));
            if (empty($normalized_ids)) {
                return [];
            }

            $orders = wc_get_orders([
                'limit' => -1,
                'orderby' => 'post__in',
                'include' => $normalized_ids,
            ]);

            if (! is_array($orders)) {
                return [];
            }

            $orders_by_id = [];
            foreach ($orders as $order) {
                $orders_by_id[(int) $order->get_id()] = $order;
            }

            $sorted_orders = [];
            foreach ($normalized_ids as $order_id) {
                if (isset($orders_by_id[$order_id])) {
                    $sorted_orders[] = $orders_by_id[$order_id];
                }
            }

            return $sorted_orders;
        }

        private function get_snapshot_order_ids_from_request($request_data)
        {
            if (! isset($request_data['snapshot_order_ids'])) {
                return [];
            }

            $raw_snapshot = sanitize_text_field(wp_unslash((string) $request_data['snapshot_order_ids']));
            if ($raw_snapshot === '') {
                return [];
            }

            $parts = explode(',', $raw_snapshot);

            return array_values(array_unique(array_filter(array_map('absint', $parts))));
        }

        private function create_label_print_log($stats_data, $order_ids)
        {
            global $wpdb;
            $this->ensure_label_log_table_exists();

            $table_name = $wpdb->prefix . self::LABEL_LOG_TABLE_SUFFIX;
            $current_user_id = get_current_user_id();

            $inserted = $wpdb->insert(
                $table_name,
                [
                    'printed_at' => current_time('mysql', true),
                    'order_ids' => wp_json_encode(array_values($order_ids)),
                    'order_count' => count($order_ids),
                    'package_count' => isset($stats_data['address_packages_count']) ? (int) $stats_data['address_packages_count'] : 0,
                    'total_items_count' => isset($stats_data['total_items_count']) ? (int) $stats_data['total_items_count'] : 0,
                    'product_totals' => wp_json_encode(isset($stats_data['product_totals']) ? $stats_data['product_totals'] : []),
                    'address_order_groups' => wp_json_encode(isset($stats_data['address_order_groups']) ? $stats_data['address_order_groups'] : []),
                    'created_by' => $current_user_id ? (int) $current_user_id : 0,
                ],
                ['%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d']
            );

            if ($inserted === false) {
                return 0;
            }

            return (int) $wpdb->insert_id;
        }

        private function get_label_log($log_id)
        {
            global $wpdb;
            $this->ensure_label_log_table_exists();
            $table_name = $wpdb->prefix . self::LABEL_LOG_TABLE_SUFFIX;

            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $log_id));
        }

        private function get_readable_state($order, $state_code)
        {
            if ($state_code === null || $state_code === '') {
                return '';
            }

            if (! function_exists('WC') || ! WC() || ! isset(WC()->countries)) {
                return (string) $state_code;
            }

            $country = $order->get_shipping_country();
            if (empty($country)) {
                $country = $order->get_billing_country();
            }
            if (empty($country)) {
                $country = WC()->countries->get_base_country();
            }

            $states_for_country = WC()->countries->get_states($country);
            if (is_array($states_for_country) && isset($states_for_country[$state_code])) {
                return (string) $states_for_country[$state_code];
            }

            return (string) $state_code;
        }

        private function get_full_shipping_address($order)
        {
            $state = $order->get_shipping_state();
            $city = $order->get_shipping_city();
            $address_1 = $order->get_shipping_address_1();
            $address_2 = $order->get_shipping_address_2();

            if (empty($state) && empty($city) && empty($address_1) && empty($address_2)) {
                $state = $order->get_billing_state();
                $city = $order->get_billing_city();
                $address_1 = $order->get_billing_address_1();
                $address_2 = $order->get_billing_address_2();
            }

            $state = $this->get_readable_state($order, $state);

            return trim(implode(' - ', array_filter([
                $state,
                $city,
                $address_1,
                $address_2,
            ], static function ($value) {
                return $value !== null && $value !== '';
            })));
        }

        private function normalize_iran_phone($phone)
        {
            $raw_phone = (string) $phone;
            $trimmed_phone = trim($raw_phone);
            if ($trimmed_phone === '') {
                return '';
            }

            $normalized_digits = strtr($trimmed_phone, [
                '۰' => '0',
                '۱' => '1',
                '۲' => '2',
                '۳' => '3',
                '۴' => '4',
                '۵' => '5',
                '۶' => '6',
                '۷' => '7',
                '۸' => '8',
                '۹' => '9',
                '٠' => '0',
                '١' => '1',
                '٢' => '2',
                '٣' => '3',
                '٤' => '4',
                '٥' => '5',
                '٦' => '6',
                '٧' => '7',
                '٨' => '8',
                '٩' => '9',
            ]);
            $normalized_digits = preg_replace('/[^\d+]+/u', '', (string) $normalized_digits);

            if (preg_match('/^\+98(\d+)$/', (string) $normalized_digits, $matches)) {
                return '0' . $matches[1];
            }

            if (preg_match('/^0098(\d+)$/', (string) $normalized_digits, $matches)) {
                return '0' . $matches[1];
            }

            if (preg_match('/^98(\d+)$/', (string) $normalized_digits, $matches)) {
                return '0' . $matches[1];
            }

            return (string) $trimmed_phone;
        }

        private function get_order_label_pages($order, $first_label_max_rows = 3, $other_labels_max_rows = 7)
        {
            $rows = [];
            foreach ($order->get_items() as $item) {
                $rows[] = [
                    'name' => (string) $item->get_name(),
                    'qty'  => (int) $item->get_quantity(),
                ];
            }

            if (empty($rows)) {
                return [[]];
            }

            $first_label_max_rows = max(1, (int) $first_label_max_rows);
            $other_labels_max_rows = max(1, (int) $other_labels_max_rows);

            $pages = [];
            $pages[] = array_slice($rows, 0, $first_label_max_rows);
            $remaining_rows = array_slice($rows, $first_label_max_rows);
            if (! empty($remaining_rows)) {
                $pages = array_merge($pages, array_chunk($remaining_rows, $other_labels_max_rows));
            }

            return $pages;
        }

        public function __construct()
        {
            add_action('admin_menu', [$this, 'register_menu']);
            add_action('admin_post_wpr_save_address', [$this, 'save_address']);
            add_action('admin_post_wpr_stats_report', [$this, 'render_stats_report_page']);
            add_action('admin_post_wpr_print_label', [$this, 'render_print_label_page']);
            add_action('admin_post_wpr_print_all_labels', [$this, 'render_print_all_labels_page']);
            add_action('admin_post_wpr_print_all_labels_from_log', [$this, 'render_print_all_labels_from_log']);
            add_action('admin_post_wpr_stats_report_from_log', [$this, 'render_stats_report_from_log']);
            add_action('admin_post_wpr_delete_label_log', [$this, 'delete_label_log']);
        }

        public function register_menu()
        {
            add_menu_page(
                'گزارش سفارش‌های درحال انجام',
                'گزارش سفارش‌ها',
                'manage_woocommerce',
                'wpr-processing-orders-report',
                [$this, 'render_page'],
                'dashicons-list-view',
                56
            );

            add_submenu_page(
                'wpr-processing-orders-report',
                'لاگ چاپ لیبل',
                'لاگ چاپ لیبل',
                'manage_woocommerce',
                'wpr-processing-orders-report-logs',
                [$this, 'render_logs_page']
            );
        }

        public function render_page()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            if (! class_exists('WooCommerce')) {
                echo '<div class="notice notice-error"><p>ووکامرس فعال نیست.</p></div>';
                return;
            }

            $stats_data = $this->get_processing_stats_data();
            $orders = $stats_data['orders'];

            echo '<div class="wrap">';
            echo '<h1>گزارش سفارش‌های در حال انجام</h1>';

            if (isset($_GET['wpr_saved']) && $_GET['wpr_saved'] === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>آدرس سفارش با موفقیت ذخیره شد.</p></div>';
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>شماره سفارش</th>';
            echo '<th>نام و نام خانوادگی</th>';
            echo '<th style="width:42%;">آدرس (قابل ویرایش)</th>';
            echo '<th>کد پستی</th>';
            echo '<th>تلفن</th>';
            echo '<th>یادداشت مشتری</th>';
            echo '<th>کالاها</th>';
            echo '<th>تعداد</th>';
            echo '<th>اقدام</th>';
            echo '</tr></thead><tbody>';

            if (empty($orders)) {
                echo '<tr><td colspan="9">سفارشی با وضعیت در حال انجام پیدا نشد.</td></tr>';
            } else {
                foreach ($orders as $order) {
                    $order_id = $order->get_id();
                    $full_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                    $full_address = $this->get_full_shipping_address($order);

                    $items_column = [];
                    $qty_column = [];
                    foreach ($order->get_items() as $item) {
                        $items_column[] = esc_html($item->get_name());
                        $item_quantity = (int) $item->get_quantity();
                        $qty_column[] = esc_html((string) $item_quantity);
                    }

                    echo '<tr>';
                    echo '<td>#' . esc_html((string) $order_id) . '</td>';
                    echo '<td>' . esc_html($full_name) . '</td>';

                    echo '<td>';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    wp_nonce_field('wpr_save_address_' . $order_id);
                    echo '<input type="hidden" name="action" value="wpr_save_address" />';
                    echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '" />';

                    $postcode = $order->get_shipping_postcode();
                    if (empty($postcode)) {
                        $postcode = $order->get_billing_postcode();
                    }

                    $full_address_char_count = mb_strlen((string) $full_address);
                    $address_error_threshold = empty($postcode) ? 163 : 134;
                    $address_textarea_border = $full_address_char_count > $address_error_threshold ? 'border:2px solid #d63638;' : 'border:1px solid #8c8f94;';

                    echo '<textarea name="full_shipping_address" rows="3" style="width:100%;min-width:520px;direction:rtl;' . esc_attr($address_textarea_border) . '" placeholder="نام دقیق استان - نام شهر - آدرس ۱- آدرس ۲- شماره پلاک">' . esc_textarea((string) $full_address) . '</textarea>';
                    echo '</td>';

                    $phone = $this->normalize_iran_phone($order->get_billing_phone());
                    $customer_note = $order->get_customer_note();

                    echo '<td>' . esc_html((string) $postcode) . '</td>';
                    echo '<td>' . esc_html((string) $phone) . '</td>';
                    echo '<td>' . esc_html((string) $customer_note) . '</td>';
                    echo '<td>' . wp_kses_post(implode('<br>', $items_column)) . '</td>';
                    echo '<td>' . wp_kses_post(implode('<br>', $qty_column)) . '</td>';
                    echo '<td>';
                    echo '<button type="submit" class="button button-primary">ذخیره آدرس</button>';
                    $print_label_url = add_query_arg([
                        'action' => 'wpr_print_label',
                        'order_id' => $order_id,
                        '_wpnonce' => wp_create_nonce('wpr_print_label_' . $order_id),
                    ], admin_url('admin-post.php'));
                    echo '<a class="button" style="margin-top:8px;" target="_blank" href="' . esc_url($print_label_url) . '">چاپ لیبل</a>';
                    echo '</td>';
                    echo '</form>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table>';
            echo '<div style="margin-top:16px;display:flex;gap:8px;align-items:center;">';
            echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;" target="_blank">';
            echo '<input type="hidden" name="action" value="wpr_print_all_labels" />';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('wpr_print_all_labels')) . '" />';
            $snapshot_order_ids = array_map(static function ($order) {
                return (int) $order->get_id();
            }, $orders);
            echo '<input type="hidden" name="snapshot_order_ids" value="' . esc_attr(implode(',', $snapshot_order_ids)) . '" />';
            echo '<button type="submit" class="button button-secondary">چاپ لیبل کلی</button>';
            echo '</form>';
            echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;" target="_blank">';
            echo '<input type="hidden" name="action" value="wpr_stats_report" />';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('wpr_stats_report')) . '" />';
            echo '<button type="submit" class="button button-secondary">گزارش آمار</button>';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        }

        public function render_stats_report_page()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            check_admin_referer('wpr_stats_report');

            if (! class_exists('WooCommerce')) {
                wp_die('ووکامرس فعال نیست.');
            }

            $stats_data = $this->get_processing_stats_data();
            $snapshot_order_ids = $this->get_snapshot_order_ids_from_request($_GET);
            $report_log_id = isset($_GET['log_id']) ? absint($_GET['log_id']) : 0;
            if (! empty($snapshot_order_ids)) {
                $snapshot_orders = $this->get_orders_by_ids($snapshot_order_ids);
                $stats_data = $this->build_stats_data_from_orders($snapshot_orders);
            }
            $orders = $stats_data['orders'];
            $product_totals = $stats_data['product_totals'];
            $total_items_count = $stats_data['total_items_count'];
            $address_packages_count = $stats_data['address_packages_count'];
            $address_order_groups = $stats_data['address_order_groups'];
            $total_orders_count = count($orders);
            $same_address_package_instructions = [];
            $report_log_datetime = '';

            if ($report_log_id > 0) {
                $report_log = $this->get_label_log($report_log_id);
                if ($report_log) {
                    $report_log_timestamp = $this->parse_mysql_datetime_to_wp_timestamp($report_log->printed_at);
                    if ($report_log_timestamp) {
                        $report_log_datetime = $this->format_persian_datetime($report_log_timestamp);
                    } else {
                        $report_log_datetime = (string) $report_log->printed_at;
                    }
                }
            }

            foreach ($address_order_groups as $group_order_ids) {
                if (count($group_order_ids) < 2) {
                    continue;
                }

                $order_ids_with_hash = array_map(static function ($order_id) {
                    return '#' . (string) $order_id;
                }, $group_order_ids);

                $same_address_package_instructions[] = 'سفارش ' . implode(' و ', $order_ids_with_hash) . ' باهم بسته‌بندی شوند.';
            }

            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<title>گزارش آمار سفارش‌های در حال انجام</title>';
            echo '<style>
                    body{font-family:tahoma,Arial,sans-serif;background:#f6f7f7;color:#1d2327;padding:24px;}
                    .report-wrap{max-width:1100px;margin:0 auto;background:#fff;padding:20px;border:1px solid #ccd0d4;}
                    h1,h2{margin-top:0}
                    table{width:100%;border-collapse:collapse;margin-top:16px;}
                    th,td{border:1px solid #dcdcde;padding:8px;text-align:right;}
                    th{background:#f0f0f1;}
                  </style></head><body>';

            echo '<div class="report-wrap">';
            echo '<h1>گزارش آمار سفارش‌ها</h1>';
            if ($report_log_id > 0) {
                echo '<p><strong>شماره لاگ:</strong> ' . esc_html((string) $report_log_id);
                if ($report_log_datetime !== '') {
                    echo ' | <strong>تاریخ و ساعت لاگ:</strong> ' . esc_html($report_log_datetime);
                }
                echo '</p>';
            }
            echo '<p><strong>تعداد کل سفارش‌ها:</strong> ' . esc_html((string) $total_orders_count) . '</p>';
            echo '<p><strong>تعداد کل اقلام:</strong> ' . esc_html((string) $total_items_count) . '</p>';
            echo '<p><strong>تعداد بسته‌ها (بر اساس آدرس یکسان):</strong> ' . esc_html((string) $address_packages_count) . '</p>';
            if (! empty($same_address_package_instructions)) {
                echo '<h2>پیشنهاد بسته‌بندی سفارش‌های هم‌آدرس</h2>';
                foreach ($same_address_package_instructions as $instruction) {
                    echo '<p>' . esc_html($instruction) . '</p>';
                }
            }

            if (empty($product_totals)) {
                echo '<p>برای این بازه سفارشی ثبت نشده است.</p>';
            } else {
                echo '<h2>تجمیع محصولات</h2>';
                echo '<table>';
                echo '<thead><tr><th>محصول</th><th>تعداد کل سفارش داده‌شده</th></tr></thead><tbody>';
                foreach ($product_totals as $product_name => $quantity) {
                    echo '<tr>';
                    echo '<td>' . esc_html((string) $product_name) . '</td>';
                    echo '<td>' . esc_html((string) $quantity) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div></body></html>';
            exit;
        }

        public function render_logs_page()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            global $wpdb;
            $this->ensure_label_log_table_exists();
            $table_name = $wpdb->prefix . self::LABEL_LOG_TABLE_SUFFIX;
            $logs = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT 500");

            echo '<div class="wrap">';
            echo '<h1>لاگ چاپ لیبل کلی</h1>';
            if (isset($_GET['wpr_log_deleted']) && $_GET['wpr_log_deleted'] === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>لاگ با موفقیت حذف شد.</p></div>';
            }
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>شماره لاگ</th>';
            echo '<th>تاریخ چاپ لیبل</th>';
            echo '<th>تعداد سفارشات</th>';
            echo '<th>تعداد بسته‌ها</th>';
            echo '<th>چاپ مجدد کلیه لیبل‌ها</th>';
            echo '<th>گزارش آمار</th>';
            echo '<th>حذف لاگ</th>';
            echo '</tr></thead><tbody>';

            if (empty($logs)) {
                echo '<tr><td colspan="7">هنوز لاگی برای چاپ لیبل کلی ثبت نشده است.</td></tr>';
            } else {
                foreach ($logs as $log) {
                    $print_link = add_query_arg(
                        [
                            'action' => 'wpr_print_all_labels_from_log',
                            'log_id' => (int) $log->id,
                            '_wpnonce' => wp_create_nonce('wpr_print_all_labels_from_log_' . (int) $log->id),
                        ],
                        admin_url('admin-post.php')
                    );
                    $stats_link = add_query_arg(
                        [
                            'action' => 'wpr_stats_report_from_log',
                            'log_id' => (int) $log->id,
                            '_wpnonce' => wp_create_nonce('wpr_stats_report_from_log_' . (int) $log->id),
                        ],
                        admin_url('admin-post.php')
                    );

                    $printed_timestamp = $this->parse_mysql_datetime_to_wp_timestamp($log->printed_at);
                    $printed_at = $printed_timestamp ? $this->format_persian_datetime($printed_timestamp) : (string) $log->printed_at;
                    $delete_log_url = add_query_arg(
                        [
                            'action' => 'wpr_delete_label_log',
                            'log_id' => (int) $log->id,
                            '_wpnonce' => wp_create_nonce('wpr_delete_label_log_' . (int) $log->id),
                        ],
                        admin_url('admin-post.php')
                    );

                    echo '<tr>';
                    echo '<td>' . esc_html((string) $log->id) . '</td>';
                    echo '<td>' . esc_html($printed_at) . '</td>';
                    echo '<td>' . esc_html((string) $log->order_count) . '</td>';
                    echo '<td>' . esc_html((string) $log->package_count) . '</td>';
                    echo '<td><a class="button button-secondary" target="_blank" href="' . esc_url($print_link) . '">چاپ مجدد</a></td>';
                    echo '<td><a class="button button-secondary" target="_blank" href="' . esc_url($stats_link) . '">نمایش گزارش</a></td>';
                    echo '<td><a class="button button-link-delete" href="' . esc_url($delete_log_url) . '" onclick="return confirm(\'آیا از حذف این لاگ مطمئن هستید؟\');">حذف لاگ</a></td>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        public function render_print_label_page()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
            if (! $order_id) {
                wp_die('شناسه سفارش نامعتبر است.');
            }

            check_admin_referer('wpr_print_label_' . $order_id);

            $order = wc_get_order($order_id);
            if (! $order) {
                wp_die('سفارش پیدا نشد.');
            }

            $full_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $full_address = $this->get_full_shipping_address($order);

            $postcode = $order->get_shipping_postcode();
            if (empty($postcode)) {
                $postcode = $order->get_billing_postcode();
            }

            $phone = $this->normalize_iran_phone($order->get_billing_phone());
            $label_pages = $this->get_order_label_pages($order, 3, 7);
            $total_labels = count($label_pages);
            $total_order_items = 0;
            foreach ($order->get_items() as $item) {
                $total_order_items += (int) $item->get_quantity();
            }

            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<title>لیبل سفارش #' . esc_html((string) $order_id) . '</title>';
            echo '<style>
                    @page{size:3.94in 1.97in;margin:0;}
                    body{font-family:tahoma,Arial,sans-serif;background:#fff;margin:0;padding:0;color:#000;}
                    .sheet{width:3.94in;height:1.97in;box-sizing:border-box;padding:4px;page-break-after:always;overflow:hidden;}
                    .sheet:last-child{page-break-after:auto;}
                    .label-box{height:100%;box-sizing:border-box;border:1px solid #000;border-radius:14px;padding:4px 6px;display:flex;flex-direction:column;gap:3px;}
                    .top-line{display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:bold;}
                    .address-line{font-size:11px;line-height:1.4;min-height:30px;word-break:break-word;}
                    .meta-line{display:flex;justify-content:space-between;gap:6px;font-size:11px;border-bottom:1px dotted #000;padding:3px 0;}
                    .items-line{font-size:11px;}
                    .order-pill{display:inline-block;border:1px solid #000;border-radius:999px;padding:1px 8px;min-width:54px;text-align:center;font-weight:bold;}
                    table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed;}
                    td{border:1px solid #000;padding:2px 3px;line-height:1.35;vertical-align:middle;}
                    td.qty{width:42px;text-align:center;font-weight:bold;white-space:nowrap;}
                    td.product-name{font-size:10.5px;white-space:nowrap;line-height:1.6;}
                    .print-note{display:none;}
                    @media screen{
                        body{background:#f0f0f1;padding:10px;}
                        .sheet{margin:0 auto 8px auto;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15);}
                        .print-note{display:block;margin:0 auto 12px auto;text-align:center;font-size:12px;}
                    }
                  </style></head><body>';
            echo '<p class="print-note">برای چاپ لیبل‌ها از Ctrl+P استفاده کنید.</p>';

            foreach ($label_pages as $page_index => $page_rows) {
                $is_first_page = $page_index === 0;
                $label_number = $page_index + 1;
                $label_number_text = '';
                if ($total_labels > 1) {
                    $label_number_text = ' (لیبل ' . (string) $label_number . ' از ' . (string) $total_labels . ')';
                }

                echo '<section class="sheet"><div class="label-box">';

                if ($is_first_page) {
                    echo '<div class="top-line">';
                    echo '<span>گیرنده: ' . esc_html($full_name !== '' ? $full_name : '-') . '</span>';
                    echo '<span>شماره سفارش: <span class="order-pill">' . esc_html((string) $order_id) . '</span>' . esc_html($label_number_text) . '</span>';
                    echo '</div>';
                    echo '<div class="address-line">آدرس: ' . esc_html($full_address !== '' ? $full_address : '-') . '</div>';
                    echo '<div class="meta-line">';
                    echo '<span>کد پستی: ' . esc_html((string) $postcode !== '' ? (string) $postcode : '-') . '</span>';
                    echo '<span>شماره تماس: ' . esc_html((string) $phone !== '' ? (string) $phone : '-') . '</span>';
                    echo '<span>تعداد اقلام: ' . esc_html((string) $total_order_items) . ' عدد</span>';
                    echo '</div>';
                } else {
                    echo '<div class="top-line"><span>ادامه سفارش #' . esc_html((string) $order_id) . esc_html($label_number_text) . '</span></div>';
                }

                echo '<table><tbody>';
                foreach ($page_rows as $row) {
                    echo '<tr>';
                    echo '<td class="qty">' . esc_html((string) $row['qty']) . ' عدد</td>';
                    echo '<td class="product-name">' . esc_html((string) $row['name']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';

                echo '</div></section>';
            }

            echo '</body></html>';
            exit;
        }

        public function render_print_all_labels_page()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            check_admin_referer('wpr_print_all_labels');

            if (! class_exists('WooCommerce')) {
                wp_die('ووکامرس فعال نیست.');
            }

            $snapshot_order_ids = $this->get_snapshot_order_ids_from_request($_GET);
            if (empty($snapshot_order_ids)) {
                $stats_data = $this->get_processing_stats_data();
            } else {
                $snapshot_orders = $this->get_orders_by_ids($snapshot_order_ids);
                $stats_data = $this->build_stats_data_from_orders($snapshot_orders);
            }
            $orders = $stats_data['orders'];

            if (empty($orders)) {
                wp_die('سفارش در حال انجامی برای چاپ لیبل وجود ندارد.');
            }

            $order_ids_for_log = array_map(static function ($order) {
                return (int) $order->get_id();
            }, $orders);
            $this->create_label_print_log($stats_data, $order_ids_for_log);

            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<title>چاپ لیبل کلی سفارش‌های در حال انجام</title>';
            echo '<style>
                    @page{size:3.94in 1.97in;margin:0;}
                    body{font-family:tahoma,Arial,sans-serif;background:#fff;margin:0;padding:0;color:#000;}
                    .sheet{width:3.94in;height:1.97in;box-sizing:border-box;padding:4px;page-break-after:always;overflow:hidden;}
                    .sheet:last-child{page-break-after:auto;}
                    .label-box{height:100%;box-sizing:border-box;border:1px solid #000;border-radius:14px;padding:4px 6px;display:flex;flex-direction:column;gap:3px;}
                    .top-line{display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:bold;}
                    .address-line{font-size:11px;line-height:1.4;min-height:30px;word-break:break-word;}
                    .meta-line{display:flex;justify-content:space-between;gap:6px;font-size:11px;border-bottom:1px dotted #000;padding:3px 0;}
                    .items-line{font-size:11px;}
                    .order-pill{display:inline-block;border:1px solid #000;border-radius:999px;padding:1px 8px;min-width:54px;text-align:center;font-weight:bold;}
                    table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed;}
                    td{border:1px solid #000;padding:2px 3px;line-height:1.35;vertical-align:middle;}
                    td.qty{width:42px;text-align:center;font-weight:bold;white-space:nowrap;}
                    td.product-name{font-size:10.5px;white-space:nowrap;line-height:1.6;}
                    .print-note{display:none;}
                    @media screen{
                        body{background:#f0f0f1;padding:10px;}
                        .sheet{margin:0 auto 8px auto;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15);}
                        .print-note{display:block;margin:0 auto 12px auto;text-align:center;font-size:12px;}
                    }
                  </style></head><body>';
            echo '<p class="print-note">برای چاپ لیبل‌ها از Ctrl+P استفاده کنید.</p>';

            foreach ($orders as $order) {
                $order_id = $order->get_id();
                $full_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $full_address = $this->get_full_shipping_address($order);
                $postcode = $order->get_shipping_postcode();
                if (empty($postcode)) {
                    $postcode = $order->get_billing_postcode();
                }

                $phone = $this->normalize_iran_phone($order->get_billing_phone());
                $label_pages = $this->get_order_label_pages($order, 3, 7);
                $total_labels = count($label_pages);
                $total_order_items = 0;
                foreach ($order->get_items() as $item) {
                    $total_order_items += (int) $item->get_quantity();
                }

                foreach ($label_pages as $page_index => $page_rows) {
                    $is_first_page = $page_index === 0;
                    $label_number = $page_index + 1;
                    $label_number_text = '';
                    if ($total_labels > 1) {
                        $label_number_text = ' (لیبل ' . (string) $label_number . ' از ' . (string) $total_labels . ')';
                    }

                    echo '<section class="sheet"><div class="label-box">';
                    if ($is_first_page) {
                        echo '<div class="top-line">';
                        echo '<span>گیرنده: ' . esc_html($full_name !== '' ? $full_name : '-') . '</span>';
                        echo '<span>شماره سفارش: <span class="order-pill">' . esc_html((string) $order_id) . '</span>' . esc_html($label_number_text) . '</span>';
                        echo '</div>';
                        echo '<div class="address-line">آدرس: ' . esc_html($full_address !== '' ? $full_address : '-') . '</div>';
                        echo '<div class="meta-line">';
                        echo '<span>کد پستی: ' . esc_html((string) $postcode !== '' ? (string) $postcode : '-') . '</span>';
                        echo '<span>شماره تماس: ' . esc_html((string) $phone !== '' ? (string) $phone : '-') . '</span>';
                        echo '<span>تعداد اقلام: ' . esc_html((string) $total_order_items) . ' عدد</span>';
                        echo '</div>';
                    } else {
                        echo '<div class="top-line"><span>ادامه سفارش #' . esc_html((string) $order_id) . esc_html($label_number_text) . '</span></div>';
                    }

                    echo '<table><tbody>';
                    foreach ($page_rows as $row) {
                        echo '<tr>';
                        echo '<td class="qty">' . esc_html((string) $row['qty']) . ' عدد</td>';
                        echo '<td class="product-name">' . esc_html((string) $row['name']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div></section>';
                }
            }

            echo '</body></html>';
            exit;
        }

        public function render_print_all_labels_from_log()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            $log_id = isset($_GET['log_id']) ? absint($_GET['log_id']) : 0;
            if (! $log_id) {
                wp_die('شناسه لاگ نامعتبر است.');
            }

            check_admin_referer('wpr_print_all_labels_from_log_' . $log_id);
            $log = $this->get_label_log($log_id);
            if (! $log) {
                wp_die('لاگ چاپ پیدا نشد.');
            }

            $order_ids = json_decode((string) $log->order_ids, true);
            if (! is_array($order_ids)) {
                $order_ids = [];
            }

            $redirect_url = add_query_arg(
                [
                    'action' => 'wpr_print_all_labels',
                    '_wpnonce' => wp_create_nonce('wpr_print_all_labels'),
                    'snapshot_order_ids' => implode(',', array_map('absint', $order_ids)),
                ],
                admin_url('admin-post.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        public function render_stats_report_from_log()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            $log_id = isset($_GET['log_id']) ? absint($_GET['log_id']) : 0;
            if (! $log_id) {
                wp_die('شناسه لاگ نامعتبر است.');
            }

            check_admin_referer('wpr_stats_report_from_log_' . $log_id);
            $log = $this->get_label_log($log_id);
            if (! $log) {
                wp_die('لاگ چاپ پیدا نشد.');
            }

            $order_ids = json_decode((string) $log->order_ids, true);
            if (! is_array($order_ids)) {
                $order_ids = [];
            }

            $redirect_url = add_query_arg(
                [
                    'action' => 'wpr_stats_report',
                    '_wpnonce' => wp_create_nonce('wpr_stats_report'),
                    'snapshot_order_ids' => implode(',', array_map('absint', $order_ids)),
                    'log_id' => $log_id,
                ],
                admin_url('admin-post.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        public function delete_label_log()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            $log_id = isset($_GET['log_id']) ? absint($_GET['log_id']) : 0;
            if (! $log_id) {
                wp_die('شناسه لاگ نامعتبر است.');
            }

            check_admin_referer('wpr_delete_label_log_' . $log_id);

            global $wpdb;
            $this->ensure_label_log_table_exists();
            $table_name = $wpdb->prefix . self::LABEL_LOG_TABLE_SUFFIX;
            $wpdb->delete($table_name, ['id' => $log_id], ['%d']);

            $redirect_url = add_query_arg(
                [
                    'page' => 'wpr-processing-orders-report-logs',
                    'wpr_log_deleted' => '1',
                ],
                admin_url('admin.php')
            );

            wp_safe_redirect($redirect_url);
            exit;
        }

        public function save_address()
        {
            if (! current_user_can('manage_woocommerce')) {
                wp_die('شما دسترسی لازم را ندارید.');
            }

            $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

            if (! $order_id) {
                wp_safe_redirect(add_query_arg('wpr_saved', '0', admin_url('admin.php?page=wpr-processing-orders-report')));
                exit;
            }

            check_admin_referer('wpr_save_address_' . $order_id);

            $order = wc_get_order($order_id);
            if (! $order) {
                wp_safe_redirect(add_query_arg('wpr_saved', '0', admin_url('admin.php?page=wpr-processing-orders-report')));
                exit;
            }

            $full_shipping_address = isset($_POST['full_shipping_address']) ? sanitize_textarea_field(wp_unslash($_POST['full_shipping_address'])) : '';

            $address_parts = array_map('trim', explode('-', $full_shipping_address));
            $shipping_state = isset($address_parts[0]) ? $address_parts[0] : '';
            $shipping_city = isset($address_parts[1]) ? $address_parts[1] : '';
            $shipping_address_1 = isset($address_parts[2]) ? $address_parts[2] : '';
            $shipping_address_2 = implode(' - ', array_slice($address_parts, 3));

            $order->set_shipping_state($shipping_state);
            $order->set_shipping_city($shipping_city);
            $order->set_shipping_address_1($shipping_address_1);
            $order->set_shipping_address_2($shipping_address_2);
            $order->save();

            $url = add_query_arg(
                [
                    'page' => 'wpr-processing-orders-report',
                    'wpr_saved' => '1',
                ],
                admin_url('admin.php')
            );

            wp_safe_redirect($url);
            exit;
        }
    }

    register_activation_hook(__FILE__, ['WPR_Processing_Orders_Report', 'activate']);
    new WPR_Processing_Orders_Report();
}
