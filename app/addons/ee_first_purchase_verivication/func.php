<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Registry;

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

    unset($data['ee_reward_points_percent_promotions']);
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
    if (Registry::get('addons.ee_first_purchase_verivication.ee_first_purchase_verivication_active') !== 'Y') {
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
