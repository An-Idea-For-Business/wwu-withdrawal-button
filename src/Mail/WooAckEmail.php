<?php
/**
 * WooCommerce-integrated acknowledgement-of-receipt email.
 *
 * Registers the durable-medium acknowledgement (Art. 11a(4)) as a first-class
 * WC_Email so it appears under WooCommerce → Settings → Emails, inherits the
 * store's email branding (logo, base colour, header/footer) and is customisable
 * (subject, heading, additional content, email type) and template-overridable in
 * the theme (woocommerce/emails/webwakeupwdb-withdrawal-ack.php).
 *
 * COMPLIANCE NOTE — the acknowledgement is legally MANDATORY. The WooCommerce
 * enable/disable toggle here only controls whether this *branded* version is used:
 * when it is disabled, ConfirmationDispatcher falls back to the plain built-in
 * template so the legally-required email is still delivered. Disabling never
 * stops the acknowledgement from being sent.
 *
 * LOCALE — ConfirmationDispatcher already switches to the consumer's locale around
 * the whole dispatch, so this class deliberately does NOT call setup_locale() /
 * restore_locale() (which would switch to the site locale instead).
 *
 * This class extends \WC_Email, which only exists once WooCommerce is loaded. It
 * is therefore only ever autoloaded from inside the woocommerce_email_classes
 * filter callback (which fires after WooCommerce init) — never at plugin boot.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WC_Email' ) ) {
	return; // WooCommerce not loaded — nothing to declare.
}

/**
 * Withdrawal acknowledgement WC email.
 */
class WooAckEmail extends \WC_Email {

	/**
	 * The WooCommerce email-classes array key under which this email is registered.
	 *
	 * @var string
	 */
	public const CLASS_KEY = 'WEBWAKEUPWDB_Withdrawal_Ack';

	/**
	 * Receipt data for the current send (set by trigger()).
	 *
	 * @var array
	 */
	protected $ack_data = array();

	/**
	 * Constructor — declare the email's identity before parent setup.
	 */
	public function __construct() {
		$this->id             = 'webwakeupwdb_withdrawal_ack';
		$this->customer_email = true;
		$this->title          = __( 'Withdrawal acknowledgement', 'wwu-withdrawal-button' );
		$this->description    = __( 'Acknowledgement of receipt sent to the consumer when they withdraw from a contract. This email is legally required (Art. 11a / Art. 54-bis); disabling it here only reverts to a plain built-in template — the acknowledgement is still sent.', 'wwu-withdrawal-button' );

		$this->template_html  = 'emails/webwakeupwdb-withdrawal-ack.php';
		$this->template_plain = 'emails/plain/webwakeupwdb-withdrawal-ack.php';
		$this->template_base  = WEBWAKEUPWDB_PATH . '/templates/';

		$this->placeholders = array(
			'{order_number}' => '',
			'{site_title}'   => $this->get_blogname(),
		);

		parent::__construct();
	}

	/**
	 * Default subject (WC 3.7+ reads this when the field is empty).
	 *
	 * @return string
	 */
	public function get_default_subject(): string {
		return __( 'Acknowledgement of your withdrawal — order {order_number}', 'wwu-withdrawal-button' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading(): string {
		return __( 'Acknowledgement of receipt of your withdrawal', 'wwu-withdrawal-button' );
	}

	/**
	 * Send the acknowledgement.
	 *
	 * @param array    $data        Receipt data (from ReceiptBuilder).
	 * @param string   $recipient   Consumer email.
	 * @param string[] $attachments Absolute attachment paths (e.g. the PDF).
	 * @return bool True if handed to the mailer; false if disabled or no recipient
	 *              (so the caller can fall back to the plain mailer).
	 */
	public function trigger( array $data, string $recipient, array $attachments = array() ): bool {
		$this->ack_data                   = $data;
		$this->recipient                  = $recipient;
		$this->placeholders['{order_number}'] = (string) ( $data['order_number'] ?? '' );

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return (bool) $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$attachments
		);
	}

	/**
	 * Render a fully-styled preview of this email for given data.
	 *
	 * Sets the data, builds the content and runs WooCommerce's own CSS inliner
	 * (style_inline) so the preview looks exactly like the delivered email,
	 * including the store's email branding. Sends nothing.
	 *
	 * @param array $data Sample (or real) receipt data.
	 * @return string Full HTML email document.
	 */
	public function preview( array $data ): string {
		$this->ack_data                       = $data;
		$this->placeholders['{order_number}'] = (string) ( $data['order_number'] ?? '' );
		return $this->style_inline( $this->get_content() );
	}

	/**
	 * HTML body (WC header/footer wrapper + our statutory content).
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'data'               => $this->ack_data,
				'email_heading'      => $this->get_heading(),
				'additional_content' => method_exists( $this, 'get_additional_content' ) ? $this->get_additional_content() : '',
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Plain-text body.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'data'               => $this->ack_data,
				'email_heading'      => $this->get_heading(),
				'additional_content' => method_exists( $this, 'get_additional_content' ) ? $this->get_additional_content() : '',
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}
}
