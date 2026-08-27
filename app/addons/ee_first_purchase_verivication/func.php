<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Registry;

require_once __DIR__ . '/logger.php';

/**
 * @ee-align 257
 * @ee-flow 11>3>8
 * @ee-finance 9112
 * @ee-intent optional rewards, bounded shipping discounts, and diagnostic evidence
 */

function fn_ee_first_purchase_verivication_install()
{
    return true;
}

function fn_ee_first_purchase_verivication_uninstall()
{
    return true;
}

/**
 * Returns the instruction block for the add-on settings page.
 *
 * @return string
 */
function fn_ee_first_purchase_verivication_settings_instructions()
{
    return __('ee_first_purchase_verivication_instructions');
}

/**
 * Checks whether the registered customer has no previous orders.
 *
 * In CS-Cart Ultimate the check is limited to the current storefront. In
 * Multi-Vendor the customer's order history is checked across the marketplace.
 *
 * @ee-rune \xE1\x9B\x8B\xE1\x9B\x92\xE1\x9B\x97
 * @ee-purpose promotion condition contract and storefront scope
 *
 * @param array<string, mixed> $auth Customer auth data.
 *
 * @return bool
 */
function fn_ee_first_purchase_verivication_promo(array $auth = [])
{
    if (Registry::get('addons.ee_first_purchase_verivication.ee_first_purchase_verivication_active') !== 'Y') {
        return false;
    }

    $ee_user_id = !empty($auth['user_id']) ? (int) $auth['user_id'] : 0;
    if ($ee_user_id <= 0) {
        return false;
    }

    $ee_conditions = [
        'user_id' => $ee_user_id,
        ['status', '!=', fn_ee_first_purchase_verivication_get_incomplete_order_status()],
    ];

    $ee_storefront_company_id = fn_ee_first_purchase_verivication_get_storefront_company_id();
    if ($ee_storefront_company_id > 0) {
        $ee_conditions['company_id'] = $ee_storefront_company_id;
    }

    $ee_order_id = db_get_field(
        'SELECT order_id FROM ?:orders WHERE ?w LIMIT 1',
        $ee_conditions
    );

    return empty($ee_order_id);
}

/**
 * Clears calculated promotion metadata before cart promotions are recalculated.
 *
 * @param array<string, mixed> $promotions    Promotions list.
 * @param string              $zone          Promotion zone.
 * @param array<string, mixed> $data          Cart data.
 * @param array<string, mixed> $auth          Customer auth data.
 * @param array<string, mixed> $cart_products Cart products.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_promotion_apply_pre(&$promotions, &$zone, &$data, &$auth, &$cart_products)
{
    if ($zone !== 'cart') {
        return;
    }

    unset(
        $data['ee_reward_points_percent_promotions'],
        $data['ee_shipping_discount_percent_promotions']
    );
}

/**
 * Keeps a promotion shipping percentage inside the supported range.
 *
 * @param mixed $value Promotion value.
 *
 * @return float
 */
function fn_ee_first_purchase_verivication_filter_shipping_discount_percent($value)
{
    return min(100.0, max(1.0, (float) $value));
}

/**
 * Registers a percentage discount for a selected shipping method.
 *
 * Priority and Stop other rules are handled by the standard promotion engine
 * before this callback is invoked.
 *
 * @param array<string, mixed> $bonus         Bonus data.
 * @param array<string, mixed> $cart          Cart data.
 * @param array<string, mixed> $auth          Customer auth data.
 * @param array<string, mixed> $cart_products Cart products.
 *
 * @return bool
 */
function fn_ee_first_purchase_verivication_shipping_discount_percent_bonus(
    $bonus,
    &$cart,
    &$auth,
    &$cart_products
) {
    if (Registry::get('addons.ee_first_purchase_verivication.ee_first_purchase_verivication_active') !== 'Y') {
        return false;
    }

    $ee_shipping_id = isset($bonus['value']) ? (int) $bonus['value'] : 0;
    $ee_percentage = isset($bonus['discount_value']) ? (float) $bonus['discount_value'] : 0.0;
    $ee_promotion_id = isset($bonus['promotion_id']) ? (int) $bonus['promotion_id'] : 0;
    if (
        $ee_shipping_id <= 0
        || $ee_percentage <= 0
        || $ee_promotion_id <= 0
        || (isset($bonus['discount_bonus']) && $bonus['discount_bonus'] !== 'by_percentage')
    ) {
        fn_ee_first_purchase_verivication_log(
            'warning',
            'shipping_bonus_rejected',
            'A shipping discount promotion bonus was ignored because its parameters are invalid.',
            [
                'promotion_id' => $ee_promotion_id,
                'shipping_id' => $ee_shipping_id,
                'percentage' => $ee_percentage,
                'discount_type' => isset($bonus['discount_bonus']) ? $bonus['discount_bonus'] : null,
            ]
        );

        return false;
    }

    $ee_percentage = fn_ee_first_purchase_verivication_filter_shipping_discount_percent($ee_percentage);
    if (!fn_promotion_apply_cart_rule($bonus, $cart, $auth, $cart_products)) {
        fn_ee_first_purchase_verivication_log(
            'error',
            'shipping_bonus_registration_failed',
            'The standard CS-Cart promotion handler rejected a valid shipping discount bonus.',
            [
                'promotion_id' => $ee_promotion_id,
                'shipping_id' => $ee_shipping_id,
                'percentage' => $ee_percentage,
            ]
        );

        return false;
    }

    $cart['ee_shipping_discount_percent_promotions'][] = [
        'promotion_id' => $ee_promotion_id,
        'shipping_id' => $ee_shipping_id,
        'percentage' => $ee_percentage,
    ];

    fn_ee_first_purchase_verivication_log(
        'debug',
        'shipping_bonus_registered',
        'A percentage shipping discount bonus was accepted by the promotion engine.',
        [
            'promotion_id' => $ee_promotion_id,
            'shipping_id' => $ee_shipping_id,
            'percentage' => $ee_percentage,
        ]
    );

    return true;
}

/**
 * Lets a percentage promotion override standard free shipping when configured.
 *
 * @param array<string, mixed> $cart                  Cart data.
 * @param array<string, mixed> $auth                  Customer auth data.
 * @param string               $calculate_shipping    Shipping calculation mode.
 * @param bool                 $calculate_taxes       Whether taxes are calculated.
 * @param string               $options_style         Product options mode.
 * @param bool                 $apply_cart_promotions Whether cart promotions are applied.
 * @param array<string>        $shipping_cache_tables Shipping cache dependencies.
 * @param string               $shipping_cache_key    Shipping cache key.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_calculate_cart_content_before_shipping_calculation(
    &$cart,
    &$auth,
    &$calculate_shipping,
    &$calculate_taxes,
    &$options_style,
    &$apply_cart_promotions,
    &$shipping_cache_tables,
    &$shipping_cache_key
) {
    if (
        Registry::get('addons.ee_first_purchase_verivication.ee_first_purchase_verivication_active') !== 'Y'
        || Registry::get('addons.ee_first_purchase_verivication.ee_shipping_free_shipping_priority') !== 'percentage'
        || empty($cart['free_shipping'])
        || empty($cart['ee_shipping_discount_percent_promotions'])
    ) {
        return;
    }

    $ee_percentages = fn_ee_first_purchase_verivication_get_shipping_discount_percentages(
        $cart['ee_shipping_discount_percent_promotions'],
        fn_ee_first_purchase_verivication_get_shipping_discount_strategy()
    );
    if (empty($ee_percentages)) {
        return;
    }

    $ee_free_shipping = array_values(array_filter(
        $cart['free_shipping'],
        static function ($ee_shipping_id) use ($ee_percentages) {
            return !isset($ee_percentages[(int) $ee_shipping_id]);
        }
    ));

    if (count($ee_free_shipping) !== count($cart['free_shipping'])) {
        $ee_removed_shipping_ids = array_values(array_diff($cart['free_shipping'], $ee_free_shipping));
        $cart['free_shipping'] = $ee_free_shipping;
        $calculate_shipping = 'A';

        fn_ee_first_purchase_verivication_log(
            'debug',
            'free_shipping_overridden',
            'Percentage discounts took priority over standard free shipping.',
            [
                'shipping_ids' => array_map('intval', $ee_removed_shipping_ids),
                'shipping_calculation_forced' => true,
            ]
        );
    }
}

/**
 * Applies shipping discounts after CS-Cart has calculated unmodified rates.
 *
 * @param array<string, mixed> $cart                  Cart data.
 * @param array<string, mixed> $auth                  Customer auth data.
 * @param string               $calculate_shipping    Shipping calculation mode.
 * @param bool                 $calculate_taxes       Whether taxes are calculated.
 * @param string               $options_style         Product options mode.
 * @param bool                 $apply_cart_promotions Whether cart promotions are applied.
 * @param string               $lang_code             Language code.
 * @param string               $area                  Current application area.
 * @param array<string, mixed> $cart_products         Cart products.
 * @param array<string, mixed> $product_groups        Product groups and shipping rates.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_calculate_cart_content_after_shipping_calculation(
    &$cart,
    &$auth,
    &$calculate_shipping,
    &$calculate_taxes,
    &$options_style,
    &$apply_cart_promotions,
    &$lang_code,
    &$area,
    &$cart_products,
    &$product_groups
) {
    $ee_percentages = [];
    if (
        Registry::get('addons.ee_first_purchase_verivication.ee_first_purchase_verivication_active') === 'Y'
        && !empty($cart['ee_shipping_discount_percent_promotions'])
    ) {
        $ee_percentages = fn_ee_first_purchase_verivication_get_shipping_discount_percentages(
            $cart['ee_shipping_discount_percent_promotions'],
            fn_ee_first_purchase_verivication_get_shipping_discount_strategy()
        );
    }

    $ee_free_shipping_ids = !empty($cart['free_shipping']) ? $cart['free_shipping'] : [];
    $ee_strategy = fn_ee_first_purchase_verivication_get_shipping_discount_strategy();
    $ee_free_priority = fn_ee_first_purchase_verivication_get_free_shipping_priority();

    try {
        $ee_diagnostics = fn_ee_first_purchase_verivication_apply_shipping_discounts(
            $product_groups,
            $ee_percentages,
            $ee_free_shipping_ids,
            $ee_free_priority
        );

        foreach ($ee_diagnostics['warnings'] as $ee_warning) {
            fn_ee_first_purchase_verivication_log(
                'warning',
                'shipping_rate_invalid',
                'A shipping rate had an unexpected value and was safely normalized.',
                $ee_warning
            );
        }

        if (!empty($ee_diagnostics['applied']) || !empty($ee_diagnostics['restored'])) {
            fn_ee_first_purchase_verivication_log(
                'debug',
                'shipping_discounts_calculated',
                'Shipping discount rates were recalculated from their original values.',
                [
                    'strategy' => $ee_strategy,
                    'free_shipping_priority' => $ee_free_priority,
                    'applied' => $ee_diagnostics['applied'],
                    'restored' => $ee_diagnostics['restored'],
                ]
            );
        }
    } catch (\Exception $ee_exception) {
        fn_ee_first_purchase_verivication_restore_shipping_discount_rates(
            $product_groups,
            $ee_free_shipping_ids
        );
        fn_ee_first_purchase_verivication_log_exception(
            'shipping_discount_calculation_failed',
            'Shipping discounts failed; standard shipping rates were restored.',
            $ee_exception,
            ['strategy' => $ee_strategy, 'free_shipping_priority' => $ee_free_priority]
        );
    } catch (\Error $ee_exception) {
        fn_ee_first_purchase_verivication_restore_shipping_discount_rates(
            $product_groups,
            $ee_free_shipping_ids
        );
        fn_ee_first_purchase_verivication_log_exception(
            'shipping_discount_calculation_failed',
            'Shipping discounts failed; standard shipping rates were restored.',
            $ee_exception,
            ['strategy' => $ee_strategy, 'free_shipping_priority' => $ee_free_priority]
        );
    }
}

/**
 * Combines applied percentages by shipping method.
 *
 * @param array<int, array<string, mixed>> $ee_promotions Applied shipping bonuses.
 * @param string                           $ee_strategy   max or sum.
 *
 * @return array<int, float>
 */
function fn_ee_first_purchase_verivication_get_shipping_discount_percentages(
    array $ee_promotions,
    $ee_strategy
) {
    $ee_percentages = [];

    foreach ($ee_promotions as $ee_promotion) {
        $ee_shipping_id = !empty($ee_promotion['shipping_id']) ? (int) $ee_promotion['shipping_id'] : 0;
        $ee_percentage = isset($ee_promotion['percentage']) ? (float) $ee_promotion['percentage'] : 0.0;
        if ($ee_shipping_id <= 0 || $ee_percentage <= 0) {
            continue;
        }

        $ee_percentage = min(100.0, $ee_percentage);
        if ($ee_strategy === 'sum') {
            $ee_percentages[$ee_shipping_id] = min(
                100.0,
                (isset($ee_percentages[$ee_shipping_id]) ? $ee_percentages[$ee_shipping_id] : 0.0)
                    + $ee_percentage
            );
        } else {
            $ee_percentages[$ee_shipping_id] = max(
                isset($ee_percentages[$ee_shipping_id]) ? $ee_percentages[$ee_shipping_id] : 0.0,
                $ee_percentage
            );
        }
    }

    return $ee_percentages;
}

/**
 * Restores original rates and applies the current promotion result once.
 *
 * @param array<string, mixed> $ee_product_groups     Product groups.
 * @param array<int, float>    $ee_percentages        Percentages by shipping ID.
 * @param array<int|string>    $ee_free_shipping_ids  Standard free shipping IDs.
 * @param string               $ee_free_priority      Conflict mode.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function fn_ee_first_purchase_verivication_apply_shipping_discounts(
    array &$ee_product_groups,
    array $ee_percentages,
    array $ee_free_shipping_ids,
    $ee_free_priority
) {
    $ee_diagnostics = [
        'applied' => [],
        'restored' => [],
        'warnings' => [],
    ];
    $ee_free_shipping_map = [];
    foreach ($ee_free_shipping_ids as $ee_shipping_id) {
        $ee_free_shipping_map[(int) $ee_shipping_id] = true;
    }

    foreach ($ee_product_groups as $ee_group_key => &$ee_product_group) {
        if (empty($ee_product_group['shippings']) || !is_array($ee_product_group['shippings'])) {
            continue;
        }

        foreach ($ee_product_group['shippings'] as $ee_shipping_key => &$ee_shipping) {
            $ee_shipping_id = !empty($ee_shipping['shipping_id'])
                ? (int) $ee_shipping['shipping_id']
                : (int) $ee_shipping_key;
            $ee_had_discount = array_key_exists('ee_shipping_discount_base_rate', $ee_shipping);
            $ee_rate_value = $ee_had_discount
                ? $ee_shipping['ee_shipping_discount_base_rate']
                : (isset($ee_shipping['rate']) ? $ee_shipping['rate'] : 0.0);
            if (!is_numeric($ee_rate_value)) {
                $ee_diagnostics['warnings'][] = [
                    'group_key' => $ee_group_key,
                    'shipping_id' => $ee_shipping_id,
                    'rate_type' => gettype($ee_rate_value),
                ];
            }
            $ee_base_rate = is_numeric($ee_rate_value) ? (float) $ee_rate_value : 0.0;
            $ee_base_rate = max(0.0, $ee_base_rate);

            unset(
                $ee_shipping['ee_shipping_discount_base_rate'],
                $ee_shipping['ee_shipping_discount_percentage']
            );
            $ee_shipping['rate'] = $ee_base_rate;

            $ee_percentage = isset($ee_percentages[$ee_shipping_id])
                ? min(100.0, max(0.0, (float) $ee_percentages[$ee_shipping_id]))
                : 0.0;
            $ee_is_free = isset($ee_free_shipping_map[$ee_shipping_id]);

            if ($ee_percentage > 0.0) {
                $ee_shipping['ee_shipping_discount_base_rate'] = $ee_base_rate;
                $ee_shipping['ee_shipping_discount_percentage'] = $ee_percentage;
                $ee_shipping['rate'] = $ee_is_free && $ee_free_priority !== 'percentage'
                    ? 0.0
                    : fn_format_price(max(0.0, $ee_base_rate * (100.0 - $ee_percentage) / 100.0));
                $ee_diagnostics['applied'][] = [
                    'group_key' => $ee_group_key,
                    'shipping_id' => $ee_shipping_id,
                    'base_rate' => $ee_base_rate,
                    'percentage' => $ee_percentage,
                    'final_rate' => (float) $ee_shipping['rate'],
                    'standard_free_shipping' => $ee_is_free,
                ];
            } elseif ($ee_had_discount && $ee_is_free) {
                $ee_shipping['ee_shipping_discount_base_rate'] = $ee_base_rate;
                $ee_shipping['rate'] = 0.0;
                $ee_diagnostics['restored'][] = [
                    'group_key' => $ee_group_key,
                    'shipping_id' => $ee_shipping_id,
                    'base_rate' => $ee_base_rate,
                    'final_rate' => 0.0,
                    'reason' => 'standard_free_shipping',
                ];
            } elseif ($ee_had_discount) {
                $ee_diagnostics['restored'][] = [
                    'group_key' => $ee_group_key,
                    'shipping_id' => $ee_shipping_id,
                    'base_rate' => $ee_base_rate,
                    'final_rate' => $ee_base_rate,
                    'reason' => 'promotion_not_applied',
                ];
            }
        }
        unset($ee_shipping);
    }
    unset($ee_product_group);

    return $ee_diagnostics;
}

/**
 * Restores marked shipping rates after an unexpected calculation failure.
 *
 * @param mixed             $ee_product_groups    Product groups.
 * @param array<int|string> $ee_free_shipping_ids Standard free shipping IDs.
 *
 * @return int Number of restored rates.
 */
function fn_ee_first_purchase_verivication_restore_shipping_discount_rates(
    &$ee_product_groups,
    array $ee_free_shipping_ids = []
) {
    if (!is_array($ee_product_groups)) {
        return 0;
    }

    $ee_free_shipping_map = [];
    foreach ($ee_free_shipping_ids as $ee_shipping_id) {
        $ee_free_shipping_map[(int) $ee_shipping_id] = true;
    }

    $ee_restored = 0;
    foreach ($ee_product_groups as &$ee_product_group) {
        if (empty($ee_product_group['shippings']) || !is_array($ee_product_group['shippings'])) {
            continue;
        }

        foreach ($ee_product_group['shippings'] as $ee_shipping_key => &$ee_shipping) {
            if (!array_key_exists('ee_shipping_discount_base_rate', $ee_shipping)) {
                continue;
            }

            $ee_shipping_id = !empty($ee_shipping['shipping_id'])
                ? (int) $ee_shipping['shipping_id']
                : (int) $ee_shipping_key;
            $ee_base_rate = is_numeric($ee_shipping['ee_shipping_discount_base_rate'])
                ? max(0.0, (float) $ee_shipping['ee_shipping_discount_base_rate'])
                : 0.0;
            $ee_shipping['rate'] = isset($ee_free_shipping_map[$ee_shipping_id])
                ? 0.0
                : $ee_base_rate;
            unset(
                $ee_shipping['ee_shipping_discount_base_rate'],
                $ee_shipping['ee_shipping_discount_percentage']
            );
            $ee_restored++;
        }
        unset($ee_shipping);
    }
    unset($ee_product_group);

    return $ee_restored;
}

/**
 * @return string max or sum
 */
function fn_ee_first_purchase_verivication_get_shipping_discount_strategy()
{
    return Registry::get('addons.ee_first_purchase_verivication.ee_shipping_discount_strategy') === 'sum'
        ? 'sum'
        : 'max';
}

/**
 * @return string free_shipping or percentage
 */
function fn_ee_first_purchase_verivication_get_free_shipping_priority()
{
    return Registry::get('addons.ee_first_purchase_verivication.ee_shipping_free_shipping_priority') === 'percentage'
        ? 'percentage'
        : 'free_shipping';
}

/**
 * Records the shipping amount that CS-Cart is about to persist with an order.
 *
 * @param int                  $order_id    Created order ID.
 * @param string               $action      Order placement action.
 * @param string               $order_status Initial order status.
 * @param array<string, mixed> $cart        Cart persisted to the order.
 * @param array<string, mixed> $auth        Customer auth data; intentionally not logged.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_place_order(
    &$order_id,
    &$action,
    &$order_status,
    &$cart,
    &$auth
) {
    $ee_shipping_summary = fn_ee_first_purchase_verivication_get_order_shipping_log_summary($cart);
    if (!$ee_shipping_summary['has_module_discount']) {
        return;
    }

    $ee_cart_shipping_cost = isset($cart['shipping_cost']) ? (float) $cart['shipping_cost'] : 0.0;
    $ee_difference = fn_format_price($ee_cart_shipping_cost - $ee_shipping_summary['selected_rate_total']);
    $ee_context = [
        'order_id' => (int) $order_id,
        'action' => (string) $action,
        'order_status' => (string) $order_status,
        'cart_shipping_cost' => $ee_cart_shipping_cost,
        'selected_rate_total' => $ee_shipping_summary['selected_rate_total'],
        'difference' => $ee_difference,
        'selected_shippings' => $ee_shipping_summary['selected_shippings'],
        'promotion_ids' => $ee_shipping_summary['promotion_ids'],
    ];

    if (abs($ee_difference) > 0.01) {
        fn_ee_first_purchase_verivication_log(
            'error',
            'order_shipping_total_mismatch',
            'The order shipping total differs from the selected discounted shipping rates.',
            $ee_context
        );

        return;
    }

    fn_ee_first_purchase_verivication_log(
        'debug',
        'order_shipping_persisted',
        'The discounted shipping total was passed to the created order.',
        $ee_context
    );
}

/**
 * Builds a PII-free shipping summary for order diagnostics.
 *
 * @param array<string, mixed> $ee_cart Cart data.
 *
 * @return array<string, mixed>
 */
function fn_ee_first_purchase_verivication_get_order_shipping_log_summary(array $ee_cart)
{
    $ee_summary = [
        'has_module_discount' => false,
        'selected_rate_total' => 0.0,
        'selected_shippings' => [],
        'promotion_ids' => [],
    ];

    if (!empty($ee_cart['ee_shipping_discount_percent_promotions'])) {
        foreach ($ee_cart['ee_shipping_discount_percent_promotions'] as $ee_promotion) {
            if (!empty($ee_promotion['promotion_id'])) {
                $ee_summary['promotion_ids'][] = (int) $ee_promotion['promotion_id'];
            }
        }
        $ee_summary['promotion_ids'] = array_values(array_unique($ee_summary['promotion_ids']));
    }

    if (empty($ee_cart['product_groups']) || !is_array($ee_cart['product_groups'])) {
        return $ee_summary;
    }

    foreach ($ee_cart['product_groups'] as $ee_group_key => $ee_group) {
        $ee_chosen_shippings = !empty($ee_group['chosen_shippings']) && is_array($ee_group['chosen_shippings'])
            ? $ee_group['chosen_shippings']
            : [];
        if (
            empty($ee_chosen_shippings)
            && isset($ee_cart['chosen_shipping'][$ee_group_key])
            && isset($ee_group['shippings'][$ee_cart['chosen_shipping'][$ee_group_key]])
        ) {
            $ee_chosen_shippings[] = $ee_group['shippings'][$ee_cart['chosen_shipping'][$ee_group_key]];
        }

        foreach ($ee_chosen_shippings as $ee_shipping) {
            $ee_rate = isset($ee_shipping['rate']) && is_numeric($ee_shipping['rate'])
                ? max(0.0, (float) $ee_shipping['rate'])
                : 0.0;
            $ee_has_discount = array_key_exists('ee_shipping_discount_base_rate', $ee_shipping);
            $ee_summary['has_module_discount'] = $ee_summary['has_module_discount'] || $ee_has_discount;
            $ee_summary['selected_rate_total'] += $ee_rate;
            $ee_summary['selected_shippings'][] = [
                'group_key' => $ee_group_key,
                'shipping_id' => !empty($ee_shipping['shipping_id']) ? (int) $ee_shipping['shipping_id'] : 0,
                'rate' => $ee_rate,
                'base_rate' => $ee_has_discount && is_numeric($ee_shipping['ee_shipping_discount_base_rate'])
                    ? max(0.0, (float) $ee_shipping['ee_shipping_discount_base_rate'])
                    : null,
                'percentage' => isset($ee_shipping['ee_shipping_discount_percentage'])
                    && is_numeric($ee_shipping['ee_shipping_discount_percentage'])
                    ? (float) $ee_shipping['ee_shipping_discount_percentage']
                    : null,
            ];
        }
    }

    $ee_summary['selected_rate_total'] = fn_format_price($ee_summary['selected_rate_total']);

    return $ee_summary;
}

/**
 * Registers a cart promotion bonus that sets target reward points percentage.
 *
 * The actual per-product reward is applied from the Reward points calculation
 * hook so the target percentage replaces the global product reward instead of
 * being added on top of it.
 *
 * @param array<string, mixed> $bonus         Bonus data.
 * @param array<string, mixed> $cart          Cart data.
 * @param array<string, mixed> $auth          Customer auth data.
 * @param array<string, mixed> $cart_products Cart products.
 *
 * @return bool
 */
function fn_ee_first_purchase_verivication_reward_points_percent_bonus($bonus, &$cart, &$auth, &$cart_products)
{
    if (!fn_ee_first_purchase_verivication_is_reward_points_feature_available()) {
        return false;
    }

    if (empty($auth['user_id'])) {
        return false;
    }

    $ee_percentage = isset($bonus['value']) ? (float) $bonus['value'] : 0.0;
    if ($ee_percentage <= 0) {
        return false;
    }

    $ee_promotion_id = !empty($bonus['promotion_id']) ? (int) $bonus['promotion_id'] : 0;
    if ($ee_promotion_id <= 0) {
        return false;
    }

    $ee_promotion_data = fn_ee_first_purchase_verivication_get_promotion_data($ee_promotion_id);
    if (empty($ee_promotion_data)) {
        return false;
    }

    $cart['promotions'][$ee_promotion_id]['bonuses'][$bonus['bonus']] = $bonus;
    $cart['ee_reward_points_percent_promotions'][$ee_promotion_id] = [
        'promotion_id' => $ee_promotion_id,
        'percentage' => $ee_percentage,
        'conditions' => fn_ee_first_purchase_verivication_extract_product_conditions($ee_promotion_data['conditions']),
    ];

    return !empty($cart_products);
}

/**
 * Overrides product reward points when a percent reward promotion matches it.
 *
 * @param array<string, mixed> $cart_products Cart products.
 * @param array<string, mixed> $cart          Cart data.
 * @param int|string          $cart_id       Cart item identifier.
 * @param array<string, mixed> $product       Current cart product snapshot.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_reward_points_calculate_item(&$cart_products, &$cart, $cart_id, $product)
{
    if (!fn_ee_first_purchase_verivication_is_reward_points_feature_available()) {
        return;
    }

    if (empty($cart['ee_reward_points_percent_promotions']) || empty($cart_products[$cart_id])) {
        return;
    }

    if (!isset($cart['points_info']['raw_total_price'])) {
        return;
    }

    $ee_percentage = fn_ee_first_purchase_verivication_get_product_target_percentage(
        $cart['ee_reward_points_percent_promotions'],
        $cart_products[$cart_id],
        []
    );

    if ($ee_percentage <= 0) {
        return;
    }

    $ee_base_price = fn_ee_first_purchase_verivication_get_product_reward_base_price($cart_products[$cart_id]);
    if ($ee_base_price <= 0) {
        return;
    }

    $ee_raw_amount = $ee_base_price * $ee_percentage / 100;

    $cart_products[$cart_id]['points_info']['reward'] = [
        'amount' => round($ee_raw_amount),
        'amount_type' => 'P',
        'coefficient' => 1,
        'object_id' => 0,
        'object_type' => 'ee_reward_points_percent',
        'pure_amount' => $ee_percentage,
        'raw_amount' => $ee_raw_amount,
    ];
}

/**
 * Gets promotion data with static request cache.
 *
 * @param int $promotion_id Promotion identifier.
 *
 * @return array<string, mixed>
 */
function fn_ee_first_purchase_verivication_get_promotion_data($promotion_id)
{
    static $ee_promotions = [];

    if (!isset($ee_promotions[$promotion_id])) {
        $ee_promotions[$promotion_id] = fn_get_promotion_data($promotion_id);
    }

    return is_array($ee_promotions[$promotion_id]) ? $ee_promotions[$promotion_id] : [];
}

/**
 * Keeps only product-scoped promotion conditions that can select cart items.
 *
 * @param array<string, mixed> $conditions_group Promotion condition group.
 *
 * @return array<string, mixed>|null
 */
function fn_ee_first_purchase_verivication_extract_product_conditions($conditions_group)
{
    if (empty($conditions_group['conditions']) || !is_array($conditions_group['conditions'])) {
        return null;
    }

    $ee_product_conditions = ['categories', 'products', 'feature'];
    $ee_filtered_conditions = [];

    foreach ($conditions_group['conditions'] as $ee_condition) {
        if (!is_array($ee_condition)) {
            continue;
        }

        if (isset($ee_condition['conditions'])) {
            $ee_nested_group = fn_ee_first_purchase_verivication_extract_product_conditions($ee_condition);
            if (!empty($ee_nested_group)) {
                $ee_filtered_conditions[] = $ee_nested_group;
            }
            continue;
        }

        if (!empty($ee_condition['condition']) && in_array($ee_condition['condition'], $ee_product_conditions, true)) {
            $ee_filtered_conditions[] = $ee_condition;
        }
    }

    if (empty($ee_filtered_conditions)) {
        return null;
    }

    return [
        'set' => !empty($conditions_group['set']) ? $conditions_group['set'] : 'all',
        'set_value' => isset($conditions_group['set_value']) ? $conditions_group['set_value'] : true,
        'conditions' => $ee_filtered_conditions,
    ];
}

/**
 * Finds the highest target percentage among applicable percent reward bonuses.
 *
 * @param array<int, array<string, mixed>> $ee_promotions Promotion reward metadata.
 * @param array<string, mixed>             $product       Cart product.
 * @param array<string, mixed>             $auth          Customer auth data.
 *
 * @return float
 */
function fn_ee_first_purchase_verivication_get_product_target_percentage(array $ee_promotions, array $product, array $auth)
{
    $ee_target_percentage = 0.0;

    foreach ($ee_promotions as $ee_promotion) {
        $ee_percentage = !empty($ee_promotion['percentage']) ? (float) $ee_promotion['percentage'] : 0.0;
        if ($ee_percentage <= $ee_target_percentage) {
            continue;
        }

        if (fn_ee_first_purchase_verivication_product_matches_conditions($product, $ee_promotion, $auth)) {
            $ee_target_percentage = $ee_percentage;
        }
    }

    return $ee_target_percentage;
}

/**
 * Checks whether a cart product matches product-scoped promotion conditions.
 *
 * @param array<string, mixed> $product      Cart product.
 * @param array<string, mixed> $ee_promotion Promotion reward metadata.
 * @param array<string, mixed> $auth         Customer auth data.
 *
 * @return bool
 */
function fn_ee_first_purchase_verivication_product_matches_conditions(array $product, array $ee_promotion, array $auth)
{
    if (empty($ee_promotion['conditions'])) {
        return true;
    }

    if (empty($product['product_id'])) {
        return false;
    }

    if (empty($product['category_ids'])) {
        $product['category_ids'] = fn_ee_first_purchase_verivication_get_product_category_ids((int) $product['product_id']);
    }

    $ee_cart_products = [];
    $ee_promotion_data = [
        'promotion_id' => !empty($ee_promotion['promotion_id']) ? (int) $ee_promotion['promotion_id'] : 0,
        'conditions' => $ee_promotion['conditions'],
    ];

    list($ee_result) = fn_check_promotion_condition_groups_recursive(
        $ee_promotion['conditions'],
        $ee_promotion_data,
        $product,
        $auth,
        $ee_cart_products
    );

    return (bool) $ee_result;
}

/**
 * Gets category identifiers for product-level promotion checks.
 *
 * @param int $product_id Product identifier.
 *
 * @return array<int, int>
 */
function fn_ee_first_purchase_verivication_get_product_category_ids($product_id)
{
    static $ee_category_ids = [];

    if (!isset($ee_category_ids[$product_id])) {
        $ee_category_ids[$product_id] = db_get_fields(
            'SELECT category_id FROM ?:products_categories WHERE product_id = ?i',
            $product_id
        );
    }

    return $ee_category_ids[$product_id];
}

/**
 * Gets a unit product price used for target reward points calculation.
 *
 * @param array<string, mixed> $product Cart product.
 *
 * @return float
 */
function fn_ee_first_purchase_verivication_get_product_reward_base_price(array $product)
{
    if (isset($product['price'])) {
        return max(0.0, (float) $product['price']);
    }

    if (!empty($product['subtotal']) && !empty($product['amount'])) {
        return max(0.0, (float) $product['subtotal'] / (float) $product['amount']);
    }

    if (isset($product['base_price'])) {
        return max(0.0, (float) $product['base_price']);
    }

    return 0.0;
}

/**
 * Prevents enabling reward accrual when the optional Reward points add-on is unavailable.
 *
 * @param \Tygh\Settings       $settings          Settings manager.
 * @param int                  $object_id         Setting identifier.
 * @param mixed                $value             New setting value.
 * @param int|null             $company_id        Company identifier.
 * @param bool                 $execute_functions Whether setting actions will run.
 * @param array<string, mixed> $data              Data that will be stored.
 * @param array<string, mixed> $old_data          Existing setting data.
 * @param string               $table             Target settings table.
 * @param int|null             $storefront_id     Storefront identifier.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_settings_update_value_by_id_pre(
    $settings,
    $object_id,
    &$value,
    $company_id,
    $execute_functions,
    &$data,
    $old_data,
    $table,
    $storefront_id = null
) {
    $ee_setting_name = !empty($old_data['name'])
        ? (string) $old_data['name']
        : (string) db_get_field('SELECT name FROM ?:settings_objects WHERE object_id = ?i', $object_id);

    if ($ee_setting_name !== 'ee_reward_points_enabled' || (string) $value !== 'Y') {
        return;
    }

    $ee_issue = fn_ee_first_purchase_verivication_get_reward_points_dependency_issue();
    if ($ee_issue === '') {
        return;
    }

    $value = 'N';
    if (is_array($data)) {
        $data['value'] = 'N';
    }

    Registry::set('addons.ee_first_purchase_verivication.ee_reward_points_enabled', 'N');

    fn_ee_first_purchase_verivication_log(
        'warning',
        'reward_points_enable_rejected',
        'Reward points promotion support was not enabled because its optional dependency is unavailable.',
        [
            'dependency_issue' => $ee_issue,
            'company_id' => $company_id !== null ? (int) $company_id : null,
            'storefront_id' => $storefront_id !== null ? (int) $storefront_id : null,
        ]
    );

    fn_set_notification(
        'E',
        __('error'),
        __('ee_first_purchase_verivication_reward_points_cannot_enable', [
            '[reason]' => __(
                'ee_first_purchase_verivication_reward_points_reason_' . $ee_issue
            ),
        ]),
        'K',
        'ee_first_purchase_verivication_reward_points_cannot_enable'
    );
}

/**
 * Warns administrators when reward accrual is configured but its dependency is unavailable.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_dispatch_before_display()
{
    $ee_admin_auth = !empty($_SESSION['auth']) && is_array($_SESSION['auth'])
        ? $_SESSION['auth']
        : [];

    if (
        !defined('AREA')
        || AREA !== 'A'
        || defined('AJAX_REQUEST')
        || empty($ee_admin_auth['user_id'])
        || empty($ee_admin_auth['user_type'])
        || $ee_admin_auth['user_type'] !== 'A'
    ) {
        return;
    }

    fn_ee_first_purchase_verivication_check_log_storage_for_admin();

    $ee_notification_id = 'ee_first_purchase_verivication_reward_points_dependency';
    if (Registry::get('addons.ee_first_purchase_verivication.ee_reward_points_enabled') !== 'Y') {
        if (function_exists('fn_delete_notification')) {
            fn_delete_notification($ee_notification_id);
        }
        return;
    }

    $ee_issue = fn_ee_first_purchase_verivication_get_reward_points_dependency_issue();
    if ($ee_issue === '') {
        if (function_exists('fn_delete_notification')) {
            fn_delete_notification($ee_notification_id);
        }
        return;
    }

    if (
        function_exists('fn_notification_exists')
        && fn_notification_exists('extra', $ee_notification_id)
    ) {
        return;
    }

    fn_ee_first_purchase_verivication_log(
        'warning',
        'reward_points_dependency_unavailable',
        'Reward points promotion support is configured but its optional dependency is unavailable.',
        ['dependency_issue' => $ee_issue]
    );

    fn_set_notification(
        'W',
        __('warning'),
        __('ee_first_purchase_verivication_reward_points_unavailable', [
            '[reason]' => __(
                'ee_first_purchase_verivication_reward_points_reason_' . $ee_issue
            ),
        ]),
        'S',
        $ee_notification_id
    );
}

/**
 * Displays a persistent admin warning when module-owned logs cannot be written.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_check_log_storage_for_admin()
{
    $ee_notification_id = 'ee_first_purchase_verivication_log_storage';
    $ee_log_directory = fn_ee_first_purchase_verivication_get_log_directory();
    $ee_issue = fn_ee_first_purchase_verivication_get_log_storage_issue($ee_log_directory);
    if ($ee_issue === '') {
        if (function_exists('fn_delete_notification')) {
            fn_delete_notification($ee_notification_id);
        }

        return;
    }

    if (
        function_exists('fn_notification_exists')
        && fn_notification_exists('extra', $ee_notification_id)
    ) {
        return;
    }

    fn_ee_first_purchase_verivication_log_fallback(
        'warning',
        'log_storage_unavailable',
        'Module log storage is unavailable: ' . $ee_issue
    );
    fn_set_notification(
        'W',
        __('warning'),
        __('ee_first_purchase_verivication_log_storage_unavailable', [
            '[reason]' => $ee_issue,
            '[path]' => $ee_log_directory,
        ]),
        'S',
        $ee_notification_id
    );
}

/**
 * Checks whether the optional reward points promotion bonus can run.
 *
 * @return bool
 */
function fn_ee_first_purchase_verivication_is_reward_points_feature_available()
{
    return Registry::get('addons.ee_first_purchase_verivication.ee_first_purchase_verivication_active') === 'Y'
        && Registry::get('addons.ee_first_purchase_verivication.ee_reward_points_enabled') === 'Y'
        && fn_ee_first_purchase_verivication_get_reward_points_dependency_issue() === '';
}

/**
 * Returns an empty string when Reward points is usable or a machine-readable issue code.
 *
 * @return string
 */
function fn_ee_first_purchase_verivication_get_reward_points_dependency_issue()
{
    static $ee_issues = [];

    $ee_reward_points = Registry::get('addons.reward_points');
    $ee_status = is_array($ee_reward_points) && !empty($ee_reward_points['status'])
        ? (string) $ee_reward_points['status']
        : '';
    $ee_addons_dir = rtrim((string) Registry::get('config.dir.addons'), '/\\');
    $ee_cache_key = $ee_status . '|' . $ee_addons_dir;
    if (isset($ee_issues[$ee_cache_key])) {
        return $ee_issues[$ee_cache_key];
    }

    if ($ee_status === '') {
        return $ee_issues[$ee_cache_key] = 'not_installed';
    }

    if (
        $ee_addons_dir !== ''
        && !is_file($ee_addons_dir . DIRECTORY_SEPARATOR . 'reward_points' . DIRECTORY_SEPARATOR . 'addon.xml')
    ) {
        return $ee_issues[$ee_cache_key] = 'files_missing';
    }

    if ($ee_status !== 'A') {
        return $ee_issues[$ee_cache_key] = 'disabled';
    }

    return $ee_issues[$ee_cache_key] = '';
}

/**
 * Gets the current storefront company ID for CS-Cart Ultimate installations.
 *
 * @return int
 */
function fn_ee_first_purchase_verivication_get_storefront_company_id()
{
    if (!function_exists('fn_allowed_for') || !fn_allowed_for('ULTIMATE')) {
        return 0;
    }

    return (int) Registry::get('runtime.company_id');
}

/**
 * Gets the status code for incomplete orders.
 *
 * @return string
 */
function fn_ee_first_purchase_verivication_get_incomplete_order_status()
{
    return defined('STATUS_INCOMPLETED_ORDER') ? STATUS_INCOMPLETED_ORDER : 'N';
}
