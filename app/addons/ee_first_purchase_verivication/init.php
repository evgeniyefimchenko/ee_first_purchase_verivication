<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

fn_register_hooks(
    'promotion_apply_pre',
    'reward_points_calculate_item'
);
