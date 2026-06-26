<?php
/**
 * Lightweight shared-service container.
 *
 * Holds the per-request singletons (platform registry with its order cache,
 * resolvers, the withdrawal service) so the Frontend and REST layers share one
 * instance instead of rebuilding state.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Core;

use WebWakeUpWdb\WithdrawalButton\Domain\ApplicabilityResolver;
use WebWakeUpWdb\WithdrawalButton\Domain\LabelResolver;
use WebWakeUpWdb\WithdrawalButton\Domain\WindowCalculator;
use WebWakeUpWdb\WithdrawalButton\Domain\WithdrawalService;
use WebWakeUpWdb\WithdrawalButton\Platform\PlatformRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service container.
 */
final class Services {

	/**
	 * Singleton instance.
	 *
	 * @var Services|null
	 */
	private static $instance = null;

	/**
	 * Platform registry.
	 *
	 * @var PlatformRegistry
	 */
	public $platforms;

	/**
	 * Label resolver.
	 *
	 * @var LabelResolver
	 */
	public $labels;

	/**
	 * Applicability resolver.
	 *
	 * @var ApplicabilityResolver
	 */
	public $applicability;

	/**
	 * Window calculator.
	 *
	 * @var WindowCalculator
	 */
	public $window;

	/**
	 * Withdrawal service.
	 *
	 * @var WithdrawalService
	 */
	public $withdrawal;

	/**
	 * Constructor — wire the singletons.
	 */
	private function __construct() {
		$this->platforms     = PlatformRegistry::create_default();
		$this->labels        = new LabelResolver();
		$this->applicability = new ApplicabilityResolver();
		$this->window        = new WindowCalculator();
		$this->withdrawal    = new WithdrawalService();
	}

	/**
	 * Get the shared instance.
	 *
	 * @return Services
	 */
	public static function instance(): Services {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
