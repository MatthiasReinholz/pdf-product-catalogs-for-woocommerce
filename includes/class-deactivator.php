<?php

namespace PdfProductCatalogsForWooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {
	public static function deactivate( bool $network_wide ): void {
		unset( $network_wide );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Plugin::ASYNC_ACTION, array(), Plugin::ASYNC_GROUP );
		}
	}
}
