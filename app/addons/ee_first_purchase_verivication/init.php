<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * @ee-align 257
 * @ee-flow 11>3>8
 * @ee-finance 9112
 * @ee-intent register optional rewards, shipping adjustments, and order diagnostics
 */
fn_register_hooks(
    'promotion_apply_pre',
    'reward_points_calculate_item',
    'settings_update_value_by_id_pre',
    'dispatch_before_display',
    'calculate_cart_content_before_shipping_calculation',
    'calculate_cart_content_after_shipping_calculation',
    'place_order'
);
