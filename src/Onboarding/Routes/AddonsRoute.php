<?php

namespace Give\Onboarding\Routes;

use WP_REST_Request;
use Give\API\RestRoute;
use Give\Onboarding\Helpers\Currency;
use Give\Onboarding\SettingsRepositoryFactory;

/**
 * @since 2.8.0
 */
class AddonsRoute implements RestRoute {

	/** @var string */
	protected $endpoint = 'onboarding/settings/addons';

	/** @var SettingsRepository */
	protected $settingsRepository;

	/**
	 * @param SettingsRepository $settingsRepository
	 *
	 * @since 2.8.0
	 */
	public function __construct( SettingsRepositoryFactory $settingsRepositoryFactory ) {
		$this->settingsRepository = $settingsRepositoryFactory->make( 'give_onboarding' );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 *
	 * @since 2.8.0
	 */
	public function handleRequest( WP_REST_Request $request ) {

		$value = json_decode( $request->get_param( 'value' ) );

		$this->settingsRepository->set( 'addons', $value );
		$this->settingsRepository->save();

		return [
			'data' => [
				'setting' => 'addons',
				'value'   => $value,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function registerRoute() {
		register_rest_route(
			'give-api/v2',
			$this->endpoint,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handleRequest' ],
					'permission_callback' => function() {
						return current_user_can( 'manage_options' );
					},
					'args'                => [
						'value' => [
							'type'              => 'string',
							'required'          => false,
							// 'validate_callback' => [ $this, 'validateValue' ],
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				'schema' => [ $this, 'getSchema' ],
			]
		);
	}

	/**
	 * @return array
	 *
	 * @since 2.8.0
	 */
	public function getSchema() {
		return [
			// This tells the spec of JSON Schema we are using which is draft 4.
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			// The title property marks the identity of the resource.
			'title'      => 'onboarding',
			'type'       => 'object',
			// In JSON Schema you can specify object properties in the properties attribute.
			'properties' => [
				'setting' => [
					'description' => esc_html__( 'The reference name for the setting being updated.', 'give' ),
					'type'        => 'string',
				],
				'value'   => [
					'description' => esc_html__( 'The value of the setting being updated.', 'give' ),
					'type'        => 'string',
				],
			],
		];
	}
}
