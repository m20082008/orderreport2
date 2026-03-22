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
        private function format_persian_datetime($timestamp)
        {
            if (class_exists('IntlDateFormatter')) {
                $formatter = new IntlDateFormatter(
                    'fa_IR@calendar=persian',
                    IntlDateFormatter::FULL,
                    IntlDateFormatter::SHORT,
                    wp_timezone(),
                    IntlDateFormatter::TRADITIONAL,
                    'yyyy/MM/dd HH:mm:ss'
                );

                if ($formatter) {
                    $formatted = $formatter->format($timestamp);
                    if ($formatted !== false) {
                        return (string) $formatted;
                    }
                }
            }

            return wp_date('Y/m/d H:i:s', $timestamp);
        }

        private function get_processing_stats_data()
        {
            $orders = wc_get_orders([
                'status' => ['processing'],
                'limit'  => -1,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);

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

            arsort($product_totals);

            return [
                'orders' => $orders,
                'product_totals' => $product_totals,
                'total_items_count' => $total_items_count,
                'address_packages_count' => count($address_packages),
                'address_order_groups' => $address_order_groups,
            ];
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

        public function __construct()
        {
            add_action('admin_menu', [$this, 'register_menu']);
            add_action('admin_post_wpr_save_address', [$this, 'save_address']);
            add_action('admin_post_wpr_stats_report', [$this, 'render_stats_report_page']);
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

                    $phone = $order->get_billing_phone();
                    $customer_note = $order->get_customer_note();

                    echo '<td>' . esc_html((string) $postcode) . '</td>';
                    echo '<td>' . esc_html((string) $phone) . '</td>';
                    echo '<td>' . esc_html((string) $customer_note) . '</td>';
                    echo '<td>' . wp_kses_post(implode('<br>', $items_column)) . '</td>';
                    echo '<td>' . wp_kses_post(implode('<br>', $qty_column)) . '</td>';
                    echo '<td>';
                    echo '<button type="submit" class="button button-primary">ذخیره آدرس</button>';
                    echo '<button type="button" class="button" style="margin-top:8px;">چاپ لیبل</button>';
                    echo '</td>';
                    echo '</form>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table>';
            echo '<div style="margin-top:16px;display:flex;gap:8px;align-items:center;">';
            echo '<button type="button" class="button button-secondary">چاپ کلیه لیبل‌ها</button>';
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
            $orders = $stats_data['orders'];
            $product_totals = $stats_data['product_totals'];
            $total_items_count = $stats_data['total_items_count'];
            $address_packages_count = $stats_data['address_packages_count'];
            $address_order_groups = $stats_data['address_order_groups'];
            $stats_generated_at = $this->format_persian_datetime(current_time('timestamp'));
            $total_orders_count = count($orders);
            $same_address_package_instructions = [];

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
            echo '<p><strong>زمان گزارش:</strong> ' . esc_html($stats_generated_at) . '</p>';
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

    new WPR_Processing_Orders_Report();
}
