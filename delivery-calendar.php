<?php
/**
 * Plugin Name: تقویم ارسال ووکامرس
 * Plugin URI: https://github.com/sahandse/delivery-calendar
 * Description: مدیریت تاریخ ارسال سفارش ووکامرس با تقویم شمسی/میلادی، تعطیلات، روز استراحت و قوانین هوشمند ارسال.
 * Version: 1.0.1
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: delivery-calendar
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

final class DC_Plugin {
    const VERSION = '1.0.1';
    const OPTION  = 'dc_settings';
    const META    = '_dc_delivery_date';

    public function __construct() {
        add_action('before_woocommerce_init', [$this, 'declare_hpos']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function declare_hpos() {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    public function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('woocommerce_after_order_notes', [$this, 'checkout_field']);
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_order_meta'], 10, 2);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'admin_order_meta']);
    }

    public function woocommerce_notice() {
        echo '<div class="notice notice-error"><p>افزونه تقویم ارسال برای اجرا به WooCommerce نیاز دارد.</p></div>';
    }

    public function defaults() {
        return [
            'enabled' => 'yes',
            'calendar' => 'jalali',
            'rest_days' => 1,
            'days_ahead' => 14,
            'weekly_off' => ['5'],
            'special_holidays' => '',
            'courier_keywords' => 'courier,motor,peyk,پیک',
            'courier_everyday' => 'yes',
            'accent' => '#111827',
            'label' => 'تاریخ ارسال',
        ];
    }

    public function settings() {
        return wp_parse_args((array)get_option(self::OPTION, []), $this->defaults());
    }

    public function register_settings() {
        register_setting('dc_group', self::OPTION, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($in) {
        $d = $this->defaults();
        return [
            'enabled' => !empty($in['enabled']) ? 'yes' : 'no',
            'calendar' => in_array($in['calendar'] ?? '', ['jalali','gregorian'], true) ? $in['calendar'] : $d['calendar'],
            'rest_days' => min(7, max(0, absint($in['rest_days'] ?? 1))),
            'days_ahead' => min(60, max(3, absint($in['days_ahead'] ?? 14))),
            'weekly_off' => array_values(array_intersect(array_map('strval', range(0,6)), array_map('strval', (array)($in['weekly_off'] ?? [])))),
            'special_holidays' => sanitize_textarea_field($in['special_holidays'] ?? ''),
            'courier_keywords' => sanitize_text_field($in['courier_keywords'] ?? $d['courier_keywords']),
            'courier_everyday' => !empty($in['courier_everyday']) ? 'yes' : 'no',
            'accent' => sanitize_hex_color($in['accent'] ?? '') ?: $d['accent'],
            'label' => sanitize_text_field($in['label'] ?? $d['label']),
        ];
    }

    public function admin_menu() {
        if (function_exists('s_store_register_submenu')) {
            s_store_register_submenu('delivery-calendar', 'تقویم ارسال', [$this, 'settings_page'], 'manage_woocommerce', 'تقویم ارسال');
            return;
        }
        add_submenu_page(
            'woocommerce',
            'تقویم ارسال',
            'تقویم ارسال',
            'manage_woocommerce',
            'delivery-calendar',
            [$this, 'settings_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'delivery-calendar')) return;
        wp_enqueue_style('dc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = $this->settings();
        $days = [0=>'یکشنبه',1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه',6=>'شنبه'];
        ?>
        <div class="wrap dc-admin">
            <div class="dc-hero">
                <div>
                    <h1>تقویم ارسال ووکامرس</h1>
                    <p>مدیریت روزهای قابل ارسال، تعطیلات و زمان آماده‌سازی سفارش.</p>
                </div>
                <span>v<?php echo esc_html(self::VERSION); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('dc_group'); ?>
                <div class="dc-grid">
                    <section class="dc-card">
                        <h2>تنظیمات عمومی</h2>
                        <label class="dc-switch">
                            <span>فعال بودن افزونه</span>
                            <input type="checkbox" name="<?php echo self::OPTION; ?>[enabled]" value="1" <?php checked($s['enabled'],'yes'); ?>>
                        </label>

                        <label>نوع تقویم
                            <select name="<?php echo self::OPTION; ?>[calendar]">
                                <option value="jalali" <?php selected($s['calendar'],'jalali'); ?>>شمسی</option>
                                <option value="gregorian" <?php selected($s['calendar'],'gregorian'); ?>>میلادی</option>
                            </select>
                        </label>

                        <label>روز استراحت بعد از سفارش
                            <input type="number" min="0" max="7" name="<?php echo self::OPTION; ?>[rest_days]" value="<?php echo esc_attr($s['rest_days']); ?>">
                        </label>

                        <label>تعداد روزهای قابل انتخاب
                            <input type="number" min="3" max="60" name="<?php echo self::OPTION; ?>[days_ahead]" value="<?php echo esc_attr($s['days_ahead']); ?>">
                        </label>
                    </section>

                    <section class="dc-card">
                        <h2>تعطیلات</h2>
                        <div class="dc-days">
                            <?php foreach ($days as $n=>$label): ?>
                                <label><input type="checkbox" name="<?php echo self::OPTION; ?>[weekly_off][]" value="<?php echo esc_attr($n); ?>" <?php checked(in_array((string)$n,(array)$s['weekly_off'],true)); ?>> <?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                        </div>

                        <label>تعطیلات خاص
                            <textarea rows="7" name="<?php echo self::OPTION; ?>[special_holidays]" placeholder="2026-09-20&#10;2026-09-21"><?php echo esc_textarea($s['special_holidays']); ?></textarea>
                            <small>هر تاریخ را به میلادی در یک خط وارد کنید.</small>
                        </label>
                    </section>

                    <section class="dc-card">
                        <h2>پیک موتوری</h2>
                        <label>کلیدواژه‌های روش ارسال
                            <input type="text" name="<?php echo self::OPTION; ?>[courier_keywords]" value="<?php echo esc_attr($s['courier_keywords']); ?>">
                        </label>
                        <label class="dc-switch">
                            <span>پیک در همه روزها فعال باشد</span>
                            <input type="checkbox" name="<?php echo self::OPTION; ?>[courier_everyday]" value="1" <?php checked($s['courier_everyday'],'yes'); ?>>
                        </label>
                    </section>

                    <section class="dc-card">
                        <h2>ظاهر</h2>
                        <label>عنوان فیلد
                            <input type="text" name="<?php echo self::OPTION; ?>[label]" value="<?php echo esc_attr($s['label']); ?>">
                        </label>
                        <label>رنگ اصلی
                            <input type="color" name="<?php echo self::OPTION; ?>[accent]" value="<?php echo esc_attr($s['accent']); ?>">
                        </label>
                    </section>
                </div>

                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
        </div>
        <?php
    }

    private function is_courier() {
        if (!WC()->session) return false;
        $chosen = (array)WC()->session->get('chosen_shipping_methods', []);
        if (!$chosen) return false;

        $keywords = array_filter(array_map('trim', explode(',', $this->settings()['courier_keywords'])));
        foreach ($chosen as $method) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && false !== mb_stripos($method, $keyword)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function holiday_dates() {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', $this->settings()['special_holidays'])
        )));
    }

    private function is_available(DateTimeImmutable $date) {
        $s = $this->settings();
        $ymd = $date->format('Y-m-d');

        if (in_array($ymd, $this->holiday_dates(), true)) {
            return false;
        }

        if ($this->is_courier() && 'yes' === $s['courier_everyday']) {
            return true;
        }

        return !in_array($date->format('w'), (array)$s['weekly_off'], true);
    }

    private function dates() {
        $s = $this->settings();
        if ('yes' !== $s['enabled']) return [];

        $cursor = new DateTimeImmutable('today', wp_timezone());
        $cursor = $cursor->modify('+' . ((int)$s['rest_days'] + 1) . ' days');

        $out = [];
        $guard = 0;

        while (count($out) < (int)$s['days_ahead'] && $guard < 120) {
            if ($this->is_available($cursor)) {
                $out[] = [
                    'value' => $cursor->format('Y-m-d'),
                    'label' => $this->format_date($cursor),
                ];
            }
            $cursor = $cursor->modify('+1 day');
            $guard++;
        }

        return $out;
    }

    private function format_date(DateTimeImmutable $date) {
        if ('gregorian' === $this->settings()['calendar']) {
            return wp_date(get_option('date_format'), $date->getTimestamp(), wp_timezone());
        }

        [$jy,$jm,$jd] = $this->gregorian_to_jalali(
            (int)$date->format('Y'),
            (int)$date->format('n'),
            (int)$date->format('j')
        );

        $months = [1=>'فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        return sprintf('%d %s %d', $jd, $months[$jm], $jy);
    }

    public function checkout_field($checkout) {
        $dates = $this->dates();
        if (!$dates) return;

        $s = $this->settings();

        echo '<div class="dc-checkout" style="--dc-accent:' . esc_attr($s['accent']) . '">';
        echo '<h3>' . esc_html($s['label']) . '</h3>';
        echo '<select name="dc_delivery_date" id="dc_delivery_date" required>';
        echo '<option value="">انتخاب تاریخ ارسال</option>';

        foreach ($dates as $d) {
            echo '<option value="' . esc_attr($d['value']) . '">' . esc_html($d['label']) . '</option>';
        }

        echo '</select>';
        echo '</div>';
    }

    public function validate_checkout() {
        if ('yes' !== $this->settings()['enabled']) return;

        $selected = isset($_POST['dc_delivery_date'])
            ? sanitize_text_field(wp_unslash($_POST['dc_delivery_date']))
            : '';

        if (!$selected) {
            wc_add_notice('لطفاً تاریخ ارسال را انتخاب کنید.', 'error');
            return;
        }

        if (!in_array($selected, wp_list_pluck($this->dates(), 'value'), true)) {
            wc_add_notice('تاریخ ارسال انتخاب‌شده معتبر نیست.', 'error');
        }
    }

    public function save_order_meta($order, $data) {
        if (empty($_POST['dc_delivery_date'])) return;

        $selected = sanitize_text_field(wp_unslash($_POST['dc_delivery_date']));
        if (in_array($selected, wp_list_pluck($this->dates(), 'value'), true)) {
            $order->update_meta_data(self::META, $selected);
        }
    }

    public function admin_order_meta($order) {
        $date = $order->get_meta(self::META);
        if ($date) {
            echo '<p><strong>تاریخ ارسال:</strong> ' . esc_html($date) . '</p>';
        }
    }

    private function gregorian_to_jalali($gy, $gm, $gd) {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365*$gy) + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400) + $gd + $g_d_m[$gm-1];

        $jy = -1595 + (33 * intdiv($days,12053));
        $days %= 12053;

        $jy += 4 * intdiv($days,1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days-1,365);
            $days = ($days-1)%365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days,31);
            $jd = 1 + ($days%31);
        } else {
            $jm = 7 + intdiv($days-186,30);
            $jd = 1 + (($days-186)%30);
        }

        return [$jy,$jm,$jd];
    }
}

new DC_Plugin();
