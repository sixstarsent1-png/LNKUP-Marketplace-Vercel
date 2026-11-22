<?php
/**
 * Branded, mobile-responsive email layout. Table-based for client compatibility.
 * Variables come from LNKup_Comms_Templates::email_html() as $args. Everything is escaped here.
 *
 * @package LNKup_Communications
 */

defined( 'ABSPATH' ) || exit;

$lnkup_preheader = isset( $args['paragraphs'][0] ) ? (string) $args['paragraphs'][0] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title><?php echo esc_html( $args['subject'] ); ?></title>
<style>
	body { margin:0; padding:0; background:#f4f4f2; }
	a { color:#1a1b1f; }
	@media only screen and (max-width: 620px) {
		.lnk-container { width:100% !important; }
		.lnk-pad { padding:24px 20px !important; }
		.lnk-heading { font-size:22px !important; line-height:28px !important; }
		.lnk-btn a { display:block !important; text-align:center !important; }
	}
</style>
</head>
<body style="margin:0;padding:0;background:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1a1b1f;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;"><?php echo esc_html( $lnkup_preheader ); ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f2;">
	<tr>
		<td align="center" style="padding:24px 12px;">
			<table role="presentation" class="lnk-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
				<tr>
					<td style="background:#0b0b0c;border-radius:16px 16px 0 0;padding:20px 32px;">
						<span style="font-size:20px;font-weight:800;letter-spacing:-0.02em;color:#ffffff;">LNK<span style="color:#c8f542;">up</span></span>
					</td>
				</tr>
				<tr>
					<td class="lnk-pad" style="background:#ffffff;padding:32px;border-radius:0 0 16px 16px;">
						<h1 class="lnk-heading" style="margin:0 0 20px;font-size:24px;line-height:30px;font-weight:800;color:#0b0b0c;"><?php echo esc_html( $args['heading'] ); ?></h1>
						<?php foreach ( (array) $args['paragraphs'] as $lnkup_paragraph ) : ?>
							<p style="margin:0 0 16px;font-size:16px;line-height:24px;color:#2b2c31;"><?php echo esc_html( (string) $lnkup_paragraph ); ?></p>
						<?php endforeach; ?>
						<?php if ( ! empty( $args['items'] ) ) : ?>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 16px;">
								<?php foreach ( (array) $args['items'] as $lnkup_item ) : ?>
									<tr>
										<td style="padding:12px 16px;border:1px solid #e7e7e3;border-radius:12px;">
											<?php if ( '' !== $lnkup_item['url'] ) : ?>
												<a href="<?php echo esc_url( $lnkup_item['url'] ); ?>" style="font-size:16px;font-weight:700;color:#0b0b0c;text-decoration:none;"><?php echo esc_html( $lnkup_item['title'] ); ?></a>
											<?php else : ?>
												<span style="font-size:16px;font-weight:700;color:#0b0b0c;"><?php echo esc_html( $lnkup_item['title'] ); ?></span>
											<?php endif; ?>
											<?php if ( '' !== $lnkup_item['meta'] ) : ?>
												<div style="font-size:14px;line-height:20px;color:#5b5c63;margin-top:4px;"><?php echo esc_html( $lnkup_item['meta'] ); ?></div>
											<?php endif; ?>
										</td>
									</tr>
									<tr><td style="height:8px;line-height:8px;font-size:8px;">&nbsp;</td></tr>
								<?php endforeach; ?>
							</table>
						<?php endif; ?>
						<?php if ( '' !== $args['action_url'] && '' !== $args['cta'] ) : ?>
							<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="lnk-btn" style="margin:24px 0 8px;">
								<tr>
									<td style="background:#c8f542;border-radius:10px;">
										<a href="<?php echo esc_url( $args['action_url'] ); ?>" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#0b0b0c;text-decoration:none;border-radius:10px;"><?php echo esc_html( $args['cta'] ); ?></a>
									</td>
								</tr>
							</table>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="padding:20px 32px;font-size:12px;line-height:18px;color:#6b6c72;text-align:center;">
						<?php if ( '' !== $args['reason'] ) : ?>
							<p style="margin:0 0 8px;"><?php echo esc_html( $args['reason'] ); ?></p>
						<?php endif; ?>
						<p style="margin:0 0 8px;">
							<?php if ( '' !== $args['prefs_url'] ) : ?>
								<a href="<?php echo esc_url( $args['prefs_url'] ); ?>" style="color:#6b6c72;"><?php esc_html_e( 'Notification settings', 'lnkup-communications' ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $args['unsubscribe_url'] ) : ?>
								&nbsp;·&nbsp;<a href="<?php echo esc_url( $args['unsubscribe_url'] ); ?>" style="color:#6b6c72;"><?php esc_html_e( 'Unsubscribe', 'lnkup-communications' ); ?></a>
							<?php endif; ?>
						</p>
						<?php if ( '' !== $args['address'] ) : ?>
							<p style="margin:0;"><?php echo esc_html( $args['address'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
