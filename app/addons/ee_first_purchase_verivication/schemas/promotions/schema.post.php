<?php

$schema['conditions']['ee_first_pay'] = [ 'type' => 'statement', 'field_function' => ['fn_ee_first_purchase_verivication_promo', '@auth'], 'zones' => ['cart']];

return $schema;
