<?php
/**
 * Annex I(B) model withdrawal form (rendered).
 *
 * @var array $form Model-form strings (from ModelForm::for_language()).
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="webwakeupwdb-model-form">
	<h3><?php echo esc_html( $form['title'] ); ?></h3>
	<p class="webwakeupwdb-model-form__note"><em><?php echo esc_html( $form['note'] ); ?></em></p>
	<p><?php echo esc_html( $form['to'] ); ?></p>
	<p><?php echo esc_html( $form['body'] ); ?></p>
	<ul class="webwakeupwdb-model-form__fields">
		<li><?php echo esc_html( $form['ordered'] ); ?></li>
		<li><?php echo esc_html( $form['name'] ); ?></li>
		<li><?php echo esc_html( $form['address'] ); ?></li>
		<li><?php echo esc_html( $form['sign'] ); ?></li>
		<li><?php echo esc_html( $form['date'] ); ?></li>
	</ul>
	<p class="webwakeupwdb-model-form__delete"><small><?php echo esc_html( $form['delete'] ); ?></small></p>
</div>
