<?php
/**
 * Referee Jogos — match summary + per-competition detail tables
 *
 * Mirrors person/referee-games.html:
 *   1. "Jogos" gray bar with an edition filter (GET → re-renders via controller).
 *   2. "Resumo de Jogos" summary table: one row per competition with columns
 *      J (jogos), A (cartões amarelos), AA (acumulação amarelos), V (cartões
 *      vermelhos), plus a Total row.
 *   3. One detail table per competition: Data, Equipa casa, R (resultado),
 *      Equipa fora, A, AA, V.
 *   Empty state: "Sem informação disponível.".
 *
 * @package Futebol365
 *
 * Args:
 *   referee array  f365_get_referee_data()
 *   games   array  f365_get_referee_games()
 */

defined( 'ABSPATH' ) || exit;

$games        = isset( $args['games'] ) && is_array( $args['games'] ) ? $args['games'] : [];
$competitions = isset( $games['competitions'] ) && is_array( $games['competitions'] ) ? $games['competitions'] : [];
$by_comp      = isset( $games['by_comp'] ) && is_array( $games['by_comp'] ) ? $games['by_comp'] : [];
$totals       = isset( $games['totals'] ) && is_array( $games['totals'] ) ? $games['totals'] : [];
$grand        = isset( $games['grand'] ) && is_array( $games['grand'] ) ? $games['grand'] : [ 'games' => 0, 'yellow' => 0, 'double' => 0, 'red' => 0 ];
$editions     = isset( $games['editions'] ) && is_array( $games['editions'] ) ? $games['editions'] : [];
$edition      = (string) ( $games['edition'] ?? '' );
?>

<?php // ── "Jogos" bar + edition filter ──────────────────────────────────────── ?>
<form action="" method="get" name="filter_form" id="filter-form" class="ink-form ink-form-option">
	<div class="bgGradienteCinza gray-bar-title">
		<span class="textoBgGradienteCinza floatLeft"><?php echo esc_html__( 'Jogos', 'futebol365' ); ?></span>
		<?php if ( ! empty( $editions ) ) : ?>
			<span class="selectBgGradienteCinza floatRight">
				<select name="edition" id="id_edition" onchange="document.getElementById('filter-form').submit();">
					<?php foreach ( $editions as $ed_key => $ed_label ) : ?>
						<option value="<?php echo esc_attr( $ed_key ); ?>" <?php selected( (string) $ed_key, $edition ); ?>>
							<?php echo esc_html( $ed_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</span>
		<?php endif; ?>
	</div>
</form>

<?php // ── "Resumo de Jogos" summary table ─────────────────────────────────── ?>
<div class="bgGradienteVerde clearfix green-bar-title"><?php echo esc_html__( 'Resumo de Jogos', 'futebol365' ); ?></div>
<div class="overflow-auto">
	<div class="tabelaJogoCompeticoes">
		<table width="100%" align="center" border="0" cellspacing="1" cellpadding="0">
			<tbody>
				<tr class="headerTable">
					<td align="left" valign="middle"><?php echo esc_html__( 'Competição', 'futebol365' ); ?></td>
					<td width="30" style="cursor: help;" align="center" valign="middle" title="<?php echo esc_attr__( 'Total de jogos', 'futebol365' ); ?>"><?php echo esc_html_x( 'J', 'games column header', 'futebol365' ); ?></td>
					<td width="30" style="cursor: help;" align="center" valign="middle" title="<?php echo esc_attr__( 'Cartões amarelos', 'futebol365' ); ?>"><?php echo esc_html_x( 'A', 'yellow cards column header', 'futebol365' ); ?></td>
					<td width="40" style="cursor: help;" align="center" valign="middle" title="<?php echo esc_attr__( 'Acumulação amarelos', 'futebol365' ); ?>"><?php echo esc_html_x( 'AA', 'second yellow cards column header', 'futebol365' ); ?></td>
					<td width="30" style="cursor: help;" align="center" valign="middle" title="<?php echo esc_attr__( 'Cartões vermelhos', 'futebol365' ); ?>"><?php echo esc_html_x( 'V', 'red cards column header', 'futebol365' ); ?></td>
				</tr>

				<?php if ( ! empty( $competitions ) ) : ?>
					<?php $i = 0; foreach ( $competitions as $comp_id => $comp ) : $i++;
						$t = $totals[ $comp_id ] ?? [ 'games' => 0, 'yellow' => 0, 'double' => 0, 'red' => 0 ];
						?>
						<tr class="<?php echo ( $i % 2 ) ? 'linhaImparTable' : 'linhaParTable'; ?>">
							<td align="left" valign="middle">
								<?php if ( ! empty( $comp['flag'] ) ) : ?>
									<img src="<?php echo esc_url( $comp['flag'] ); ?>" alt="<?php echo esc_attr( $comp['name'] ?? '' ); ?>" title="<?php echo esc_attr( $comp['name'] ?? '' ); ?>" border="0" align="absbottom" />&nbsp;
								<?php endif; ?>
								<?php if ( ! empty( $comp['url'] ) ) : ?>
									<a href="<?php echo esc_url( $comp['url'] ); ?>"><?php echo esc_html( $comp['name'] ?? '' ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $comp['name'] ?? '' ); ?>
								<?php endif; ?>
							</td>
							<td align="center" valign="middle"><?php echo (int) $t['games']; ?></td>
							<td align="center" valign="middle"><?php echo (int) $t['yellow']; ?></td>
							<td align="center" valign="middle"><?php echo (int) $t['double']; ?></td>
							<td align="center" valign="middle"><?php echo (int) $t['red']; ?></td>
						</tr>
					<?php endforeach; ?>

					<tr class="<?php echo ( count( $competitions ) % 2 ) ? 'linhaParTable' : 'linhaImparTable'; ?>">
						<td valign="middle" align="right"><b><?php echo esc_html__( 'Total', 'futebol365' ); ?></b></td>
						<td align="center" class="negrito"><?php echo (int) $grand['games']; ?></td>
						<td align="center" class="negrito"><?php echo (int) $grand['yellow']; ?></td>
						<td align="center" class="negrito"><?php echo (int) $grand['double']; ?></td>
						<td align="center" class="negrito"><?php echo (int) $grand['red']; ?></td>
					</tr>
				<?php else : ?>
					<tr class="linhaImparTable">
						<td valign="middle" align="right"><b><?php echo esc_html__( 'Total', 'futebol365' ); ?></b></td>
						<td align="center" class="negrito">0</td>
						<td align="center" class="negrito">0</td>
						<td align="center" class="negrito">0</td>
						<td align="center" class="negrito">0</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<?php if ( empty( $competitions ) ) : ?>
	<p><?php echo esc_html__( 'Sem informação disponível.', 'futebol365' ); ?></p>
<?php endif; ?>

<?php // ── Per-competition detail tables ───────────────────────────────────── ?>
<?php foreach ( $competitions as $comp_id => $comp ) :
	$rows = $by_comp[ $comp_id ] ?? [];
	if ( empty( $rows ) ) {
		continue;
	}
	?>
	<div class="bgGradienteVerde clearfix green-bar-title">
		<?php if ( ! empty( $comp['flag'] ) ) : ?>
			<img src="<?php echo esc_url( $comp['flag'] ); ?>" alt="<?php echo esc_attr( $comp['name'] ?? '' ); ?>" title="<?php echo esc_attr( $comp['name'] ?? '' ); ?>" border="0" align="absbottom" />&nbsp;
		<?php endif; ?>
		<?php if ( ! empty( $comp['url'] ) ) : ?>
			<a href="<?php echo esc_url( $comp['url'] ); ?>"><?php echo esc_html( $comp['name'] ?? '' ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $comp['name'] ?? '' ); ?>
		<?php endif; ?>
	</div>

	<div class="overflow-auto">
		<div class="tabelaJogoCompeticoes">
			<table width="100%" align="center" border="0" cellspacing="1" cellpadding="0">
				<tbody>
					<tr class="headerTable">
						<td width="50" align="left" valign="middle"><?php echo esc_html__( 'Data', 'futebol365' ); ?></td>
						<td width="120" align="left" valign="middle"><?php echo esc_html__( 'Equipa casa', 'futebol365' ); ?></td>
						<td width="30" style="cursor: help;" align="center" valign="middle" title="<?php echo esc_attr__( 'Resultado', 'futebol365' ); ?>"><?php echo esc_html_x( 'R', 'result column header', 'futebol365' ); ?></td>
						<td align="left" valign="middle"><?php echo esc_html__( 'Equipa fora', 'futebol365' ); ?></td>
						<td width="30" style="cursor: help;" align="center" valign="middle" title="<?php echo esc_attr__( 'Cartões Amarelos', 'futebol365' ); ?>"><?php echo esc_html_x( 'A', 'yellow cards column header', 'futebol365' ); ?></td>
						<td width="30" style="cursor: help;" align="center" valign="middle" title="<?php echo esc_attr__( 'Acumulação de Amarelos', 'futebol365' ); ?>"><?php echo esc_html_x( 'AA', 'second yellow cards column header', 'futebol365' ); ?></td>
						<td width="30" style="cursor: help;" align="center" valign="middle" title="<?php echo esc_attr__( 'Cartões Vermelhos', 'futebol365' ); ?>"><?php echo esc_html_x( 'V', 'red cards column header', 'futebol365' ); ?></td>
					</tr>

					<?php $j = 0; foreach ( $rows as $row ) : $j++;
						$ts        = $row['date_time'] !== '' ? strtotime( $row['date_time'] ) : 0;
						$date_disp = $ts ? date_i18n( 'd-m-y', $ts ) : '';
						$hs        = $row['home_score'];
						$vs        = $row['visitor_score'];
						$result    = ( $hs !== '' && $vs !== '' )
							? ( (int) $hs . '-' . (int) $vs )
							: '-';
						?>
						<tr class="<?php echo ( $j % 2 ) ? 'linhaImparTable' : 'linhaParTable'; ?>">
							<td align="left"><?php echo esc_html( $date_disp ); ?></td>
							<td align="left">
								<?php if ( $row['home_url'] !== '' ) : ?>
									<a href="<?php echo esc_url( $row['home_url'] ); ?>"><?php echo esc_html( $row['home_name'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $row['home_name'] ); ?>
								<?php endif; ?>
							</td>
							<td align="center" title="<?php echo esc_attr__( 'Resultado', 'futebol365' ); ?>"><?php echo esc_html( $result ); ?></td>
							<td align="left">
								<?php if ( $row['away_url'] !== '' ) : ?>
									<a href="<?php echo esc_url( $row['away_url'] ); ?>"><?php echo esc_html( $row['away_name'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $row['away_name'] ); ?>
								<?php endif; ?>
							</td>
							<td align="center"><?php echo (int) $row['yellow']; ?></td>
							<td align="center"><?php echo (int) $row['double']; ?></td>
							<td align="center"><?php echo (int) $row['red']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endforeach; ?>