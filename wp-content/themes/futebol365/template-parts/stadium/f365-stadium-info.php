<?php
$post_id = $args['post_id'];

$fields = get_fields($post_id) ?: [];

$country = f365_get_stadium_country($post_id);

$country_flag = '';

if ($country) {

	$flag_id = get_field(
		'country_flag',
		'f365_country_' . $country->term_id
	);

	if ($flag_id) {
		$country_flag = wp_get_attachment_image_url(
			$flag_id,
			'thumbnail'
		);
	}
}

$has_information = (bool) array_filter(
	[
		$country,
		$fields['city'] ?? '',
		$fields['opening'] ?? '',
		$fields['closing'] ?? '',
		$fields['capacity'] ?? '',
		$fields['address'] ?? '',
		$fields['architect'] ?? '',
		$fields['dimensions'] ?? '',
		$fields['first_game'] ?? '',
		$fields['url'] ?? '',
		$fields['email'] ?? '',
	]
);

?>
<section class="stadium-information">
	<h2 class="condensed-400 headerTitle">
		<?php esc_html_e(
			'Dados Gerais',
			'f365'
		); ?>
		<span class="headerTitle-border"></span>
	</h2>
	<?php if ($has_information) : ?>
		<table class="ink-table ink-table-f365 alternating all-100">
			<thead></thead>
			<tbody>
				<?php if ($country) : ?>
					<tr>
						<td class="align-right all-35 font-blue">
							<?php esc_html_e(
								'País:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle all-65 flag-icon-text">
							<?php if ($country_flag) : ?>
								<img
									class="quarter-right-space vertical-align-middle"
									src="<?php echo esc_url(
												$country_flag
											); ?>"
									alt="<?php echo esc_attr(
												$country->name
											); ?>"
									width="20"
									height="14">
							<?php endif; ?>
							<?php echo esc_html(
								$country->name
							); ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (! empty($fields['city'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Local:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<?php
							echo esc_html(
								$fields['city']
							);
							?>
						</td>
					</tr>
				<?php endif; ?>

				<?php if (! empty($fields['opening'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Inauguração:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<?php
							echo esc_html(
								wp_date(
									'Y',
									strtotime(
										$fields['opening']
									)
								)
							);
							?>
						</td>
					</tr>
				<?php endif; ?>

				<?php if (! empty($fields['closing'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Encerramento:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<?php
							echo esc_html(
								date_i18n(
									'Y',
									strtotime(
										$fields['closing']
									)
								)
							);
							?>
						</td>
					</tr>
				<?php endif; ?>

				<?php if (! empty($fields['capacity'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Capacidade:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<?php
							echo esc_html(
								number_format_i18n(
									$fields['capacity']
								)
							);
							?>
							lugares

						</td>
					</tr>
				<?php endif; ?>
				<?php if (! empty($fields['address'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Morada:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<?php
							echo esc_html(
								$fields['address']
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (! empty($fields['architect'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Arquiteto:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<?php
							echo esc_html(
								$fields['architect']
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (! empty($fields['dimensions'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Dimensões:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<?php
							echo esc_html(
								$fields['dimensions']
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
				
				<?php
				// Get the stadium ID saved in the WordPress custom post meta (change 'stadium_id' to match your meta key name)
				$stadium_id = get_post_meta( $post_id, 'django_stadium_id', true );
				// Retrieve the active associated teams list
				$stadium_teams = f365_get_stadium_teams1( $stadium_id );
				if ( ! empty( $stadium_teams ) ) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e('Equipas:', 'f365'); ?>
						</td>
				        <td>
				            <?php 
				            $team_links = array();
				            foreach ( $stadium_teams as $team ) {
				                $team_links[] = sprintf(
				                    '<a href="%s">%s</a>',
				                    esc_url( $team['url'] ),
				                    esc_html( $team['formatted_name'] )
				                );
				            }
				            echo implode( ', ', $team_links );
				            ?>
				        </td>
			    	</tr>
				<?php endif; ?>

					
				<?php if (! empty($fields['first_game'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Primeiro jogo:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<?php
							echo wp_kses_post(
								$fields['first_game']
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (! empty($fields['url'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'Internet:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<a
								target="_blank"
								rel="noopener noreferrer"
								href="<?php echo esc_url(
											$fields['url']
										); ?>">
								<?php echo esc_html(
									$fields['url']
								); ?>
							</a>
						</td>
					</tr>
				<?php endif; ?>
				<?php if (! empty($fields['email'])) : ?>
					<tr>
						<td class="align-right font-blue">
							<?php esc_html_e(
								'E-mail:',
								'f365'
							); ?>
						</td>
						<td class="vertical-align-middle">
							<a href="mailto:<?php echo esc_attr(
												$fields['email']
											); ?>">
								<?php echo esc_html(
									$fields['email']
								); ?>
							</a>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	<?php else : ?>
		<h5 class="no-info">
			<?php esc_html_e(
				'Sem informação disponível',
				'f365'
			); ?>
		</h5>
	<?php endif; ?>
</section>

<?php
/*
 * Static stadium information code temporarily disabled.
 * Previous static implementation is preserved below as commented source.
 */
// <?php
// /**
//  * Stadium Information
//  *
//  * @package Futebol365
//  */
// 
// $static_information = [
// 	[
// 		'label' => 'Pa?s:',
// 		'value' => 'Portugal',
// 		'flag'  => 'https://flagcdn.com/w40/pt.png',
// 	],
// 	[
// 		'label' => 'Local:',
// 		'value' => 'Oeiras',
// 	],
// 	[
// 		'label' => 'Inaugura??o:',
// 		'value' => '1944',
// 	],
// 	[
// 		'label' => 'Capacidade:',
// 		'value' => '37.593 lugares',
// 	],
// 	[
// 		'label' => 'Morada:',
// 		'value' => 'Pra?a da Maratona, Jamor, Oeiras',
// 	],
// 	[
// 		'label' => 'Arquiteto:',
// 		'value' => 'Miguel Jacobetty Rosa',
// 	],
// 	[
// 		'label' => 'Dimens?es:',
// 		'value' => '105 x 68 m',
// 	],
// 	[
// 		'label' => 'Equipas:',
// 		'value' => '<a href="#">Portugal</a>, <a href="#">Belenenses</a>',
// 		'html'  => true,
// 	],
// 	[
// 		'label' => 'Primeiro jogo:',
// 		'value' => 'Portugal 2-2 Espanha',
// 	],
// 	[
// 		'label' => 'Internet:',
// 		'value' => 'https://www.fpf.pt',
// 		'url'   => 'https://www.fpf.pt',
// 	],
// 	[
// 		'label' => 'E-mail:',
// 		'value' => 'info@example.com',
// 		'url'   => 'mailto:info@example.com',
// 	],
// ];
// 
// ? >
// <section class="stadium-information">
// 	<h2 class="condensed-400 headerTitle">
// 		<?php esc_html_e('Dados Gerais', 'f365'); ? >
// 		<span class="headerTitle-border"></span>
// 	</h2>
// 
// 	<?php if (! empty($static_information)) : ? >
// 		<table class="ink-table ink-table-f365 alternating all-100">
// 			<thead></thead>
// 			<tbody>
// 				<?php foreach ($static_information as $row) : ? >
// 					<tr>
// 						<td class="align-right all-35 font-blue">
// 							<?php echo esc_html($row['label']); ? >
// 						</td>
// 						<td class="vertical-align-middle all-65 <?php echo ! empty($row['flag']) ? 'flag-icon-text' : ''; ? >">
// 							<?php if (! empty($row['flag'])) : ? >
// 								<img class="quarter-right-space vertical-align-middle" src="<?php echo esc_url($row['flag']); ? >" alt="<?php echo esc_attr($row['value']); ? >" width="20" height="14">
// 							<?php endif; ? >
// 
// 							<?php if (! empty($row['url'])) : ? >
// 								<a href="<?php echo esc_url($row['url']); ? >" <?php echo 0 === strpos($row['url'], 'http') ? 'target="_blank" rel="noopener noreferrer"' : ''; ? >>
// 									<?php echo esc_html($row['value']); ? >
// 								</a>
// 							<?php elseif (! empty($row['html'])) : ? >
// 								<?php echo wp_kses_post($row['value']); ? >
// 							<?php else : ? >
// 								<?php echo esc_html($row['value']); ? >
// 							<?php endif; ? >
// 						</td>
// 					</tr>
// 				<?php endforeach; ? >
// 			</tbody>
// 		</table>
// 	<?php else : ? >
// 		<h5 class="no-info">
// 			<?php esc_html_e('Sem informa??o dispon?vel', 'f365'); ? >
// 		</h5>
// 	<?php endif; ? >
// </section>
