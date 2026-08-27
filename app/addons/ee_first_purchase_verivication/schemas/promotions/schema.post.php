<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * @ee-align 257
 * @ee-flow 11>3>8
 * @ee-finance 9112
 * @ee-intent expose native promotion contracts with bounded shipping input
 */
$schema['conditions']['ee_first_pay'] = [
    'type' => 'statement',
    'field_function' => ['fn_ee_first_purchase_verivication_promo', '@auth'],
    'zones' => ['catalog', 'cart'],
];

$schema['bonuses']['ee_reward_points_percent'] = [
    'type' => 'input',
    'function' => ['fn_ee_first_purchase_verivication_reward_points_percent_bonus', '#this', '@cart', '@auth', '@cart_products'],
    'zones' => ['cart'],
    'filter' => 'floatval',
];

$schema['bonuses']['ee_shipping_discount_percent'] = [
    'type' => 'select',
    'function' => [
        'fn_ee_first_purchase_verivication_shipping_discount_percent_bonus',
        '#this',
        '@cart',
        '@auth',
        '@cart_products',
    ],
    'variants_function' => ['fn_get_shippings_names', fn_get_runtime_company_id()],
    'discount_bonuses' => ['by_percentage'],
    'zones' => ['cart'],
    'filter' => 'fn_ee_first_purchase_verivication_filter_shipping_discount_percent',
    'filter_field' => 'discount_value',
];

return $schema;
