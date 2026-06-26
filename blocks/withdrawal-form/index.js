/**
 * Editor script for the "Withdrawal — self-service" block.
 *
 * A dynamic (server-rendered) block: it has no static markup (`save` returns
 * null) because the output depends on per-request PHP logic — applicability,
 * order ownership, and the logged-in customer's eligible orders. The editor
 * preview uses ServerSideRender so it shows exactly what visitors will see
 * without re-implementing any of that logic in JavaScript.
 *
 * Hand-written with the window.wp.* globals (no JSX, no build step). The script
 * dependencies are declared in index.asset.php.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el                = wp.element.createElement;
	var __                = wp.i18n.__;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var TextControl       = wp.components.TextControl;
	var ServerSideRender  = wp.serverSideRender;

	wp.blocks.registerBlockType( 'webwakeupwdb/withdrawal-form', {
		/**
		 * Editor view: an optional "specific order ID" control + a live
		 * server-rendered preview of the front-end output.
		 *
		 * @param {Object} props Block props.
		 * @return {Object} Editor element.
		 */
		edit: function ( props ) {
			var blockProps = useBlockProps();
			var attributes = props.attributes || {};

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Withdrawal', 'wwu-withdrawal-button' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Specific order ID (optional)', 'wwu-withdrawal-button' ),
						help: __(
							'Leave empty to let the logged-in customer choose from their eligible orders.',
							'wwu-withdrawal-button'
						),
						type: 'number',
						value: attributes.orderId ? String( attributes.orderId ) : '',
						onChange: function ( value ) {
							var parsed = parseInt( value, 10 );
							props.setAttributes( { orderId: isNaN( parsed ) ? 0 : parsed } );
						}
					} )
				)
			);

			var preview = ServerSideRender
				? el( ServerSideRender, {
					block: 'webwakeupwdb/withdrawal-form',
					attributes: attributes
				} )
				: el(
					'p',
					{},
					__( 'Withdrawal form (rendered on the front end).', 'wwu-withdrawal-button' )
				);

			return el( 'div', blockProps, inspector, preview );
		},

		/**
		 * Dynamic block — rendered in PHP, so nothing is saved to post content.
		 *
		 * @return {null} No static markup.
		 */
		save: function () {
			return null;
		}
	} );
} )( window.wp );
