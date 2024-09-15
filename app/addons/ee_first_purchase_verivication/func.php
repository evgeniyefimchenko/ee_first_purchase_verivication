<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Registry;

function fn_ee_first_purchase_verivication_install() {
	$message = 'The module was installed on the site ' . Registry::get('config.http_host');
	mail('evgeniy@efimchenko.com', 'module installed', $message);	
}

function fn_ee_first_purchase_verivication_uninstall() {
	return true;
}

/**
 * Проверяет, совершал ли пользователь покупки
 * Функция проверяет историю заказов пользователя и возвращает true, если заказы отсутствуют,
 * или false, если пользователь уже совершал покупки
 * @param array $auth Массив данных авторизации пользователя, содержащий 'user_id'
 * @return bool Возвращает true, если у пользователя нет заказов, и false, если они были
 */
function fn_ee_first_purchase_verivication_promo($auth) {
    $user_id = $auth['user_id'];
    $order_exists = db_get_field('SELECT order_id FROM ?:orders WHERE user_id = ?i LIMIT 1', $user_id);
    return empty($order_exists);
}
