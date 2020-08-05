<?php

namespace Give\PaymentGateways\PayPalCommerce;


use Give\Helpers\ArrayDataSet;
use Give\PaymentGateways\PayPalCommerce\Models\MerchantDetail;
use Give\PaymentGateways\PayPalCommerce\Repositories\MerchantDetails;
use PayPalCheckoutSdk\Core\RefreshTokenRequest;

/**
 * Class RefreshToken
 *
 * @since 2.8.0
 */
class RefreshToken {
	/** @var MerchantDetails */
	private $detailsRepository;

	public function __construct( MerchantDetails $detailsRepository ) {
		$this->detailsRepository = $detailsRepository;
	}

	/**
	 * Register cron job to refresh access token.
	 * Note: only for internal use.
	 *
	 * @since 2.8.0
	 *
	 * @param string $tokenExpires
	 *
	 */
	public function registerCronJobToRefreshToken( $tokenExpires ) {
		wp_schedule_single_event(
			time() + ( $tokenExpires - 1800 ), // Refresh token before half hours of expires date.
			'give_paypal_commerce_refresh_token'
		);
	}

	/**
	 * Refresh token.
	 * Note: only for internal use
	 *
	 * @since 2.8.0
	 */
	public function refreshToken() {
		/* @var MerchantDetail $merchant */
		$merchant = give( MerchantDetail::class );

		/* @var PayPalClient $paypalClient */
		$paypalClient = give( PayPalClient::class );

		$refreshToken  = $merchant->getRefreshToken();
		$request       = new RefreshTokenRequest(
			$paypalClient->getEnvironment(),
			$refreshToken
		);
		$request->body = [
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refreshToken,
		];

		$response     = $paypalClient->getHttpClient()->execute( $request );
		$tokenDetails = ArrayDataSet::camelCaseKeys( (array) $response->result );

		$merchant->setTokenDetails( $tokenDetails );
		$this->detailsRepository->save( $merchant );

		$this->registerCronJobToRefreshToken( $tokenDetails['expiresIn'] );
	}
}
