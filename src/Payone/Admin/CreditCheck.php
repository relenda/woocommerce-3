<?php

namespace Payone\Admin;

defined( 'ABSPATH' ) or die( 'Direct access not allowed' );

class CreditCheck {
	public function display() {
		include PAYONE_VIEW_PATH . '/admin/credit-check.php';
	}
}