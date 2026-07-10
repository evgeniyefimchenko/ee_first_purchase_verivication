<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

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

return $schema;
