<?php

namespace NovaExpress\Api\Exception;

defined( 'ABSPATH' ) || exit;

class NovaPoshtaApiException extends \RuntimeException {

	/** @var string[] */
	private array $api_errors;

	public function __construct( string $message, array $api_errors = array() ) {
		parent::__construct( $message );
		$this->api_errors = $api_errors;
	}

	public function api_errors(): array {
		return $this->api_errors;
	}
}
