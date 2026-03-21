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
        public function __construct()
        {
            add_action('admin_menu', [$this, 'register_menu']);
            add_action('admin_post_wpr_save_address', [$this, 'save_address']);
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

            $orders = wc_get_orders([
                'status' => ['processing'],
                'limit'  => -1,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);

            echo '<div class="wrap">';
            echo '<h1>گزارش سفارش‌های در حال انجام</h1>';

            if (isset($_GET['wpr_saved']) && $_GET['wpr_saved'] === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>آدرس سفارش با موفقیت ذخیره شد.</p></div>';
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>شماره سفارش</th>';
            echo '<th>نام و نام خانوادگی</th>';
            echo '<th>آدرس (قابل ویرایش)</th>';
            echo '<th>کد پستی</th>';
            echo '<th>تلفن</th>';
            echo '<th>کالاها</th>';
            echo '<th>تعداد</th>';
            echo '<th>اقدام</th>';
            echo '</tr></thead><tbody>';

            if (empty($orders)) {
                echo '<tr><td colspan="8">سفارشی با وضعیت در حال انجام پیدا نشد.</td></tr>';
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
                        $qty_column[] = esc_html((string) $item->get_quantity());
                    }

                    echo '<tr>';
                    echo '<td>#' . esc_html((string) $order_id) . '</td>';
                    echo '<td>' . esc_html($full_name) . '</td>';

                    echo '<td>';
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    wp_nonce_field('wpr_save_address_' . $order_id);
                    echo '<input type="hidden" name="action" value="wpr_save_address" />';
                    echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '" />';

                    echo '<textarea name="full_shipping_address" rows="3" style="width:100%;direction:rtl;" placeholder="نام دقیق استان - نام شهر - آدرس ۱- آدرس ۲- شماره پلاک">' . esc_textarea((string) $full_address) . '</textarea>';
                    echo '<p style="margin-top:8px"><code>' . esc_html($full_address) . '</code></p>';
                    echo '</td>';

                    $postcode = $order->get_shipping_postcode();
                    if (empty($postcode)) {
                        $postcode = $order->get_billing_postcode();
                    }

                    $phone = $order->get_billing_phone();

                    echo '<td>' . esc_html((string) $postcode) . '</td>';
                    echo '<td>' . esc_html((string) $phone) . '</td>';
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
            echo '</div>';
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
