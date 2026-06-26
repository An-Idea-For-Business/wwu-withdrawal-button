<?php
/**
 * Consolidated "Right of withdrawal" policy PDF (Dompdf, CSS 2.1 / DejaVu fonts).
 *
 * Plain branding, mirroring the receipt PDF. The body is PolicyDocument::to_html()
 * and PolicyBuilder::disclaimer_html() — both builder-escaped — echoed verbatim so
 * the PDF matches the page/shortcode exactly. Dynamic values were escaped upstream
 * by the builder; this template adds only static, print-friendly CSS.
 *
 * @var string $content_html    Assembled policy HTML (title + sections), builder-escaped.
 * @var string $disclaimer_html Global "complements, not replaces" disclaimer HTML.
 * @var string $site_name       Site name.
 * @var string $generated_local Localised generation datetime.
 *
 * @package WWU\WithdrawalButton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
	body { font-family: "DejaVu Sans", sans-serif; color: #222; font-size: 12px; line-height: 1.5; }
	h2.wwu-wb-policy__title { color: #1a1f3a; font-size: 18px; margin: 0 0 4px; }
	h3.wwu-wb-policy__heading { color: #1a1f3a; font-size: 13px; margin: 16px 0 4px; }
	p { margin: 0 0 8px; }
	ul.wwu-wb-policy__exceptions { margin: 4px 0 8px; padding-left: 18px; }
	ul.wwu-wb-policy__exceptions li { margin-bottom: 6px; }
	.wwu-wb-policy__ref { color: #777; font-size: 11px; }
	.wwu-wb-policy__disclaimer { color: #555; font-size: 11px; background: #f3f3f3; border-left: 3px solid #1a1f3a; padding: 6px 10px; margin: 0 0 14px; }
	.foot { margin-top: 24px; font-size: 10px; color: #999; }
</style>
</head>
<body>
	<?php echo $disclaimer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder-escaped disclaimer HTML. ?>
	<?php echo $content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder-escaped policy HTML. ?>
	<div class="foot">
		<p><?php echo esc_html( $site_name ); ?> — <?php echo esc_html( $generated_local ); ?></p>
	</div>
</body>
</html>
