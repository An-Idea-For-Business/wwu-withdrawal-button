<?php
/**
 * Editor-script dependencies + version for the withdrawal-form block.
 *
 * WordPress reads this sibling file (named after the editorScript, `index`) to
 * resolve the script's dependency handles and version — so we can ship the block
 * editor script as hand-written vanilla JS using the window.wp.* globals, with NO
 * @wordpress/scripts / webpack build step. The handles below are the registered
 * core scripts that expose wp.blocks, wp.element, wp.i18n, wp.blockEditor,
 * wp.components and wp.serverSideRender.
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-i18n',
		'wp-block-editor',
		'wp-components',
		'wp-server-side-render',
	),
	'version'      => '1.0.0',
);
