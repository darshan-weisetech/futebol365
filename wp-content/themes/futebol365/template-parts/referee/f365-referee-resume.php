<?php
/**
 * Referee Resumo — career summary tables (Clubes / Seleção)
 *
 * @package Futebol365
 *
 * Args:
 *   referee array  f365_get_referee_data()
 *   resume  array  f365_get_referee_resume() → [ 'club'=>[], 'national'=>[] ]
 */

defined( 'ABSPATH' ) || exit;

$resume   = isset( $args['resume'] ) && is_array( $args['resume'] ) ? $args['resume'] : [];
$club     = isset( $resume['club'] ) && is_array( $resume['club'] ) ? $resume['club'] : [];
$national = isset( $resume['national'] ) && is_array( $resume['national'] ) ? $resume['national'] : [];

/**
 * Local renderer for one competition table.
 *
 * @param array  $rows  Competition rows.
 * @param string $title Section title (already translated).
 */
$render_table = static function ( array $rows, string $title ): void {
	if ( empty( $rows ) ) {
		return;
	}
	?>
	<div class="green-bar-title"><?php echo esc_html( $title ); ?></div>
	<div class="overflow-auto">
		<div class="tabelaJogoCompeticoes">
			<table width="100%" align="center" border="0" cellspacing="1" cellpadding="0">
				<tbody>
					<tr class="headerTable">
						<td width="65" align="left" valign="middle"><?php echo esc_html__( 'Season', 'futebol365' ); ?></td>
						<td align="left" valign="middle"><?php echo esc_html__( 'Competition', 'futebol365' ); ?></td>
						<td width="30" align="center" valign="middle" title="<?php echo esc_attr__( 'Games', 'futebol365' ); ?>">
							<?php echo esc_html__( 'G', 'futebol365' ); ?>
						</td>
					</tr>
					<?php $i = 0; foreach ( $rows as $row ) : $i++; ?>
						<tr class="<?php echo ( $i % 2 ) ? 'linhaImparTable' : 'linhaParTable'; ?>">
							<td align="left" valign="middle"><?php echo esc_html( (string) ( $row['season'] ?? '' ) ); ?></td>
							<td align="left" valign="middle" class="negrito">
								<?php if ( ! empty( $row['flag'] ) ) : ?>
									<img src="<?php echo esc_url( $row['flag'] ); ?>"
									     alt="<?php echo esc_attr( $row['name'] ?? '' ); ?>"
									     title="<?php echo esc_attr( $row['name'] ?? '' ); ?>"
									     border="0" align="absbottom" />&nbsp;
								<?php endif; ?>
								<?php if ( ! empty( $row['url'] ) ) : ?>
									<a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></a>
								<?php else : ?>
									<?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?>
								<?php endif; ?>
							</td>
							<td align="center" valign="middle"><?php echo (int) ( $row['games'] ?? 0 ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
};
?>

<div class="gray-bar-title"><?php echo esc_html__( 'Resumo', 'futebol365' ); ?></div>

<?php if ( empty( $club ) && empty( $national ) ) : ?>
	<p><?php echo esc_html__( 'Sem informação disponível.', 'futebol365' ); ?></p>
<?php else : ?>
	<?php $render_table( $club, esc_html__( 'Clubs', 'futebol365' ) ); ?>
	<?php $render_table( $national, esc_html__( 'National Teams', 'futebol365' ) ); ?>
<?php endif; ?>