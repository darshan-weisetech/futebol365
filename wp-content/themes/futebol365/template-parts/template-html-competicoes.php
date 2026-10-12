<?php 
/*
* Template Name: Competicoes html page
*/
get_header();
?>
<div class="ink-grid vertical-space">
	<?php get_template_part( 'template-parts/template-offer-widget' );?>

	<div class="ink-header-f365 competicoes" style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(https://weisetech.dev/futebol365/wp-content/uploads/2026/05/estadios04.jpg);">
        <div class="column-group" >
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content" >
                <div class="flag hide-small hide-tiny" >
                    <a href="#" ><i class="flag-icon flag-icon-conf_2" title="Liga Campeões" ><img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/uefa.png" alt=""></i></a>
                </div>
                <div class="headerdata" >
                    <a href="#"><h1 >Liga Campeões</h1></a>
                    <div>Edição: 2025-2026</div>
                    <div><i class="flag-bg"  style="background-image: url(https://weisetech.dev/futebol365/wp-content/uploads/2026/05/fr.svg);"></i>UEFA</div>
                    <div >
                        <a href="#" target="_blank" aria-label="Facebook" >
                        	<svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false" ><use href="#social-facebook" ></use></svg>
                        </a>
                    	<a href="#" target="_blank" aria-label="X (Twitter)" >
                    		<svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false" ><use href="#social-x" ></use></svg>
                    	</a>
                        <a href="#" target="_blank" aria-label="Instagram">
                        	<svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false" ><use href="#social-instagram" ></use></svg>
                        </a>
                        <a href="#" target="_blank" aria-label="Website oficial" >
                        	<svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false" ><use href="#icon-link" ></use>
                        	</svg>
                        </a>
                    </div>
                </div>
            </div>
            <div class="align-right medium-align-left small-align-left tiny-align-left all-40 medium-100 small-100 tiny-100 medium-top-space small-top-space tiny-top-space" >
                <form class="ink-form" >
				    <div class="control-group" >
				        <select name="edition" id="edition" >
				        	<?php for ($i=0; $i < 5; $i++) { ?>
				        		<option value="2025" selected="selected" >2025-2026</option>
				        	<?php } ?>
				        </select>
				    </div>
				</form>
				<form class="ink-form" >
				    <div class="control-group" >
				        <select name="edition" id="edition" >
				        	<?php for ($i=0; $i < 5; $i++) { ?>
				        		<option value="2025" selected="selected" >2026 | Qualificação Mundial Europa</option>
				        	<?php } ?>
				        </select>
				    </div>
				</form>
				<form class="ink-form" >
				    <div class="control-group" >
				        <select name="edition" id="edition" >
				        	<?php for ($i=1; $i < 5; $i++) { ?>
				        		<option value="Equipa Principal" selected="selected" >
                            	    Equipa Principal <?php echo $i; ?>
                            	</option>
				        	<?php } ?>
				        </select>
				    </div>
				</form>
            </div>
        </div>
    </div>
    <div class="nav-trigger" id="nav-trigger">
        <span>Menu</span>
    </div>
    <nav class="nav-main" id="nav-main">
        <ul >
            <li ><a class="active" href="#" >Mais Recentes</a></li>
            <li ><a href="#" >Em destaque</a></li>
            <li ><a href="#" >Mais vistos</a></li>
        </ul>
    </nav>
</div>
<div class="ink-grid futebol365-container">
	<section class="left-column-block">
		<div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
                <?php yoast_breadcrumb(); ?>
            </div>
        </div>

        <form class="ink-form ink-form-option" method="get" name="team_matches_form_level_2" id="team_matches_form_level_2">
		    <div class="column-group">
		        <div class="control-group all-33 tiny-100 small-100">
		            <div class="control">
		                <select name="match_option" id="id_match_option">
							<option value="matches">Jogos</option>
							<option value="victories">Vitórias</option>
							<option value="draws">Empates</option>
							<option value="loses">Derrotas</option>
							<option value="without_lose">Jogos sem derrotas</option>
							<option value="without_win">Jogos sem vitórias</option>
							<option value="scored_goals">Golos marcados</option>
							<option value="suffered_goals">Golos sofridos</option>
						</select>
		            </div>
		        </div>
				
				
		        <div class="control-group all-33 tiny-100 small-100">
		            <div class="control">
		                <select name="competition_phase" id="id_competition_phase">
							<option value="all">Fases</option>
							<option value="12626">Fase de grupos</option>
							<option value="13191">Play-off - Meias-finais</option>
							<option value="13192">Play-off - Final</option>
						</select>
		            </div>
		        </div>
				
		        <div class="control-group all-33 tiny-100 small-100">
		            <div class="control">
		                <select name="opponent" id="id_opponent">
							<option value="all">Adversário</option>
							<option value="1082916">Arménia</option>
							<option value="1082918">Hungria</option>
							<option value="1082917">Rep. Irlanda</option>
						</select>
		            </div>
		        </div>
		    </div>
		</form>

        <h3 class="condensed-400 headerTitle">Competições Internacionais de Clubes <span class="headerTitle-border"></span></h3>
        <h4 class="condensed-700 uppercase silver-bar-width-titles">Futebol Masculino</h4>
        <div class="column-group half-gutters bottom-space">
        	<?php for ($i=0; $i < 5; $i++) { ?>
        		<div class="all-50 xlarge-33 small-100 tiny-100">
                    <div class="obj-right-div-no-flex">
                        <div class="obj-name">
                            <a href="#">Amigáveis de clubes</a>
                        </div>
                        <div class="obj-link">43 edições | <i class="font-red">Extinta</i></div>
                    </div>
                </div>
        	<?php } ?>
        </div>
        <h3 class="condensed-400 headerTitle">Competições Nacionais de Clubes <span class="headerTitle-border"></span></h3>
        <div class="column-group half-gutters">
        	<?php for ($i=0; $i < 5; $i++) { ?>
        		<div class="all-50 xlarge-33 small-100 tiny-100">
                    <div class="obj-right-div">
                        <div class="obj-flag">
                            <a href="#"><i class="flag-icon flag-icon-conf_5" title="AFC"><img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/uefa.png" alt=""></i></a>
                        </div>
                        <div class="obj-detail">
                            <div class="obj-name">
                                <a href="/federacao/5/competicoes">AFC</a>
                            </div>
                            <div class="obj-link">26 competições</div>
                            <div class="obj-link">Fundação: 1965</div>
                        </div>
                    </div>
                </div>
        	<?php } ?>
        </div>


        <!--estadio page html -->
        <div class="overflow-auto">
        	<table class="ink-table ink-table-f365 alternating all-100">
        		<thead>
	                <tr>
	                    <th class="all-10 small-15 tiny-15 align-left">Data</th>
	                    <th class="all-5 hide-tiny hide-small align-center">Hora</th>
	                    <th class="all-30 align-right hide-tiny hide-small">Visitado</th>
	                    <th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
	                    <th class="all-10 small-20 tiny-20"></th>
	                    <th class="all-30 align-left hide-tiny hide-small">Visitante</th>
	                    <th class="all-15 small-20 tiny-20"></th>
	                </tr>
	            </thead>
	             <tbody>
	             	<?php for ($i=0; $i < 10; $i++) { ?>
	             		<tr>
                        	<td class="align-left cell-nowrap"><a href="#">22-03-26<span class="hide-xlarge hide-large hide-medium break">15:00</span></a></td>
                            <td class="align-center hide-tiny hide-small">15:00</td>
                        	<td class="align-right hide-tiny hide-small">
	                            <a href="#" class="teamWinner">Juv. Lajense</a> 
	                            <!-- <a href="#">Juv. Lajense</a> -->
	                            <!-- Note:  teamWinner this class for bold if you need normal text. it is not need to add. -->
	                        </td>
	                        <td class="align-left hide-xlarge hide-large hide-medium">
	                            <a href="#" class="teamWinner">Juv. Lajense</a>
	                            <span class="break">
	                                <a href="#">Lusitânia</a>
	                            </span>
	                        </td>
	                        <td class="align-center">
	                            <span class="ink-label ink-label-score silver"><a href="#">3 - 1</a></span>
	                        </td>
	                        <td class="align-left hide-tiny hide-small">
	                            <a href="#">Lusitânia</a>
	                        </td>
                        <td class="align-left">
                        	<a href="#">
						        <span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span>
						    </a>
						</td>
                    </tr>
	             	<?php } ?>
	             </tbody>
        	</table>
        </div>

        <h3 class="condensed-400 headerTitle top-space">Próximos jogos <span class="headerTitle-border"></span></h3>
        <div class="overflow-auto">
        	<table class="ink-table ink-table-f365 alternating all-100">
        		<thead>
	                <tr>
	                    <th class="all-10 small-15 tiny-15 align-left">Data</th>
	                    <th class="all-5 hide-tiny hide-small align-center">Hora</th>
	                    <th class="all-30 align-right hide-tiny hide-small">Visitado</th>
	                    <th class="all-45 align-left hide-medium hide-large hide-xlarge">Jogo</th>
	                    <th class="all-10 small-20 tiny-20"></th>
	                    <th class="all-30 align-left hide-tiny hide-small">Visitante</th>
	                    <th class="all-15 small-20 tiny-20"></th>
	                </tr>
	            </thead>
	             <tbody>
	             	<?php for ($i=0; $i < 10; $i++) { ?>
	             		<tr>
                        	<td class="align-left cell-nowrap"><a href="#">22-03-26<span class="hide-xlarge hide-large hide-medium break">15:00</span></a></td>
                            <td class="align-center hide-tiny hide-small">15:00</td>
                        	<td class="align-right hide-tiny hide-small">
	                            <a href="#" class="teamWinner">Juv. Lajense</a> 
	                            <!-- <a href="#">Juv. Lajense</a> -->
	                            <!-- Note:  teamWinner this class for bold if you need normal text. it is not need to add. -->
	                        </td>
	                        <td class="align-left hide-xlarge hide-large hide-medium">
	                            <a href="#" class="teamWinner">Juv. Lajense</a>
	                            <span class="break">
	                                <a href="#">Lusitânia</a>
	                            </span>
	                        </td>
	                        <td class="align-center">
	                            <span class="ink-label ink-label-score silver"><a href="#">vs</a></span>
	                        </td>
	                        <td class="align-left hide-tiny hide-small">
	                            <a href="#">Lusitânia</a>
	                        </td>
                        <td class="align-left">
                        	<a href="#">
						        <span class="ink-tooltip ink-button-related green" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="green">C</span>
						    </a>
						    <a href="#">
						        <span class="ink-tooltip ink-button-related red" data-tip-text="Comparar equipas" data-tip-where="up" data-tip-color="red">V</span>
						    </a>
						    <a href="#">
						        <span class="ink-tooltip ink-button-related yellow" data-tip-text="A" data-tip-where="up" data-tip-color="yellow">V</span>
						    </a>
						</td>
                    </tr>
	             	<?php } ?>
	             </tbody>
        	</table>
        </div>
        <!--estadio page html -->
		<!--jogador page html -->
		<h5 class="condensed-400 headerTitle top-space">Clubes <span class="headerTitle-border"></span></h5>
		<div class="overflow-auto">
			<table class="ink-table ink-table-f365 alternating all-100">
				<thead>
		            <tr>
		                <th class="align-left all-15 tiny-30 small-30">Época</th>
		                <th class="align-left">Clube</th>
		                <th class="align-left all-30 hide-tiny hide-small">Pais</th>
		                <th class="align-center all-10"><span class="ink-tooltip" data-tip-text="Jogos" data-tip-where="up" data-tip-color="black">J</span></th>
		                <th class="align-center all-10"><span class="ink-tooltip" data-tip-text="Golos" data-tip-where="up" data-tip-color="black">G</span></th>
		                <th class="align-center all-10 hide-tiny hide-small"></th>
		            </tr>
		        </thead>
		        <tbody>
		        	<?php for ($i=0; $i < 5; $i++) { ?>
		        		<tr>
		                    <td class="align-left"><a href="#">2025-2026</a></td>
		                    <td class="align-left"><a href="#">Arsenal</a></td>
		                    <td class="align-left hide-tiny hide-small">
		                        <i class="flag-icon quarter-right-space flag-icon-22" title="Inglaterra"><img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/fr.svg"></i><a href="#">Inglaterra</a>
		                    </td>
		                    <td class="align-center">30</td>
		                    <td class="align-center">12</td>
		                    <td class="align-center hide-tiny hide-small">
		                        <a href="#"><svg class="svg-icon" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#icon-plus"></use></svg></a>
		                    </td>
		                </tr>
		        	<?php } ?>
		        </tbody>
			</table>
		</div>
		<!--jogador page html -->
		<!-- comparar-com https://futebol365.pt/equipa/780/comparar-com/545/?edition=2025&step1=principal&gender1=male&modality1=football&step2=principal&gender2=male&modality2=football -->
		<form class="ink-form ink-form-option" >
		    <div class="column-group">
		        <div class="control-group all-33 tiny-100 small-100">
		            <div class="control">
		                <select name="federation_competition">
							<option value="all">Competitions</option>
							<option value="46">CAN</option>
							<option value="47">CHAN</option>
							<option value="13">National team friendlies</option>
						</select>
		            </div>
		        </div>
		    </div>
		</form>

		<div class="column-group match-div" style="background-image: url(https://weisetech.dev/futebol365/wp-content/uploads/2026/05/match.jpg);">

	        <!-- Nome equipas -->
	        <div class="all-40 small-30 tiny-30 align-center">
	            <a href="#">
	                <h5 class="match-team-name">Senegal</h5>
	            </a>
	        </div>
	        <div class="all-20 small-40 tiny-40 align-center">
	        </div>
	        <div class="all-40 small-30 tiny-30 align-center">
	            <a href="#">
	                <h5 class="match-team-name">Morocco</h5>
	            </a>
	        </div>

	        <!-- Logos e resultados -->
	        <div class="all-40 small-30 tiny-30 align-center">
	            <a href="#">
	                <img class="team-logo" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/780.gif" alt="Logo">
	            </a>
	        </div>
	        <div class="all-20 small-40 tiny-40 align-center">
	            <h2 class="match-score"><span class="ink-badge black no-margin quarter-vertical-padding">VS</h2>
	        </div>
	        <div class="all-40 small-30 tiny-30 align-center">
	            <a href="#">
	                <img class="team-logo" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/545.gif" alt="Logo">
	            </a>
	        </div>

	    </div>

	    <h5 class="condensed-400 headerTitle">Summary
	    	<span class="headerTitle-border"></span>
	    </h5>

	    <div class="column-group">
	    	<table class="ink-table ink-table-f365 alternating">
	    		<thead>
                    <tr>
                        <th class="align-center all-33"><font dir="auto" style="vertical-align: inherit;"><font dir="auto" style="vertical-align: inherit;">Senegal</font></font></th>
                        <th class="align-center all-33"></th>
                        <th class="align-center all-33"><font dir="auto" style="vertical-align: inherit;"><font dir="auto" style="vertical-align: inherit;">Morocco</font></font></th>
                    </tr>
                </thead>
                <tbody>
                	<?php for ($i=0; $i < 10; $i++) { ?>
                		<tr>
						    <td class="align-center">5</td>
						    <td class="align-center">Games</td>
						    <td class="align-center">5</td>
						</tr>
                	<?php } ?>
                </tbody>
	    	</table>
	    </div>
		<!-- comparar-com -->


        <h2 class="condensed-400 extralarge modtitle top-space">Na Primeira Página</h2>
        <div class="column-group gutters">
        	<?php for ($i=0; $i < 4; $i++) { ?>
        		<div class="all-25 small-50 tiny-100">
                    <a href="#">
                        <figure class="ink-image ink-lazyload half-bottom-space my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
                            <img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/f4e75e89195a4f86dc25b14f7b71f5b3.jpg" title="Ex-leão alerta: &quot;Atletas estão muito desgastados e a ficar já nos limites&quot;" alt="Futebol 365">
                        </figure>
                    </a>
                    <div class="quarter-vertical-space medium condensed-400">15-04-2026 · 15:00</div>
                    <h3 class="large condensed-700"><a href="#">Ex-leão alerta: "Atletas estão muito desgastados e a ficar já nos limites"</a></h3>
                </div>
        	<?php } ?>
        </div>

        <form class="ink-form ink-form-option" action="" method="get" name="match_opts_form" id="match_opts_form">
	        <div class="column-group">
	            <div class="control-group all-33 tiny-100 small-100">
	                <div class="control">
	                    <select name="live-matches-section" id="live-matches-section">
	                        <option value="livematch-narration">Narração</option>
	                        <option value="livematch-events">Eventos</option>
	                        <option value="livematch-players">Ficha</option>
	                        <option value="livematch-stats">Estatísticas</option>
	                        <option value="livematch-videos">Vídeos</option>
	                    </select>
	                </div>
	            </div>
	        </div>
	    </form>
	    <div class="overflow-auto">
	    	<table class="ink-table ink-table-f365 alternating all-100">
			    <thead>
			    <tr>
			        <th class="align-center all-10">Min</th>
			        <th class="align-left all-90">Ocorrência</th>
			    </tr>
			    </thead>
			    <tbody>
		            <?php for ($i=0; $i < 10; $i++) { ?>
		            	<tr>
			                <td class="align-center"><b>90'+5</b></td>
			                <td class="align-left">
			                    
			                        <span class="whistle event-type-right-padding"></span>
			                    
			                    O árbitro apita para o final da partida. O resultado é 0-0.
			                </td>
			            </tr>	
		            <?php } ?>
			    </tbody>
			</table>
	    </div>
	    <h5 class="condensed-400 headerTitle green top-space">Resumo <span class="headerTitle-border"></span></h5>
	    <div class="overflow-auto">
	    	<table class="ink-table ink-table-f365 alternating all-100">
		        <thead>
		            <tr>
		                <th class="align-left all-15">Desde</th>
		                <th class="align-left all-15">Até</th>
		                <th class="align-left all-35">Clube</th>
		                <th class="align-left all-35 hide-tiny hide-small">País</th>
		            </tr>
		        </thead>
		        <tbody>
		        
		            <tr>
		                <td colspan="4" class="align-left"><h5>Sem informação disponível.</h5></td>
		            </tr>
		        
		        </tbody>
		    </table>
	    </div>
	    <h4 class="condensed-400 headerTitle top-space">Dados Gerais <span  class="headerTitle-border"></span></h4>
	    <div class="overflow-auto">
	    	<table class="ink-table ink-table-f365 alternating">
	            <tbody>
	                <tr>
	                    <td class="align-right all-35 font-blue">Nome:</td>
	                    <td class="align-left">Federação Internacional de Futebol</td>
	                </tr>
	                
	                <tr>
	                    <td class="align-right font-blue">Ano de Fundação:</td>
	                    <td class="align-left">1904</td>
	                </tr>
	                <tr>
	                    <td class="align-right font-blue">Sede:</td>
	                    <td class="align-left">Zurique</td>
	                </tr>
	                
	                    <tr>
	                        <td class="align-right font-blue">Internet:</td>
	                        <td class="align-left"><a target="_blank" href="https://www.fifa.com/">https://www.fifa.com/</a></td>
	                    </tr>
	                
	                
	                    <tr>
	                        <td class="align-right font-blue">Redes Sociais:</td>
	                        <td>
	                            
                            	<a href="https://www.facebook.com/fifa" target="_blank" aria-label="Facebook"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-facebook"></use></svg></a>

                            	<a href="https://x.com/FIFAcom" target="_blank" aria-label="X (Twitter)"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-x"></use></svg></a>

                            	<a href="https://www.youtube.com/FIFATV" target="_blank" aria-label="YouTube"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-youtube"></use></svg></a>
	                        </td>
	                    </tr>
	                
	            </tbody>
	        </table>
	    </div>

	    <h5 class="condensed-400 headerTitle top-space">Equipas - Vitórias <span class="headerTitle-border"></span></h5>
	    <div class="overflow-auto">
	    	<table class="ink-table ink-table-f365 alternating">
                <thead>
                    <tr>
                        <th class="align-center all-5"></th>
                        <th class="align-left">Equipa</th>
                        <th class="align-center all-15">Total</th>
                        <th class="align-center all-15 hide-tiny hide-small">Jogos</th>
                        <th class="align-center all-15">Média</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr>
                            <td class="align-center">1</td>
                            <td class="align-left"><i class="hide-tiny flag-icon quarter-right-space flag-icon-7" title="Brasil"></i><a href="#">Flamengo</a></td>
                            <td class="align-center">2</td>
                            <td class="align-center hide-tiny hide-small">3</td>
                            <td class="align-center">0.67</td>
                        </tr>
                    
                        <tr>
                            <td class="align-center">2</td>
                            <td class="align-left"><i class="hide-tiny flag-icon quarter-right-space flag-icon-77" title="Egipto"></i><a href="#">Pyramids FC</a></td>
                            <td class="align-center">2</td>
                            <td class="align-center hide-tiny hide-small">3</td>
                            <td class="align-center">0.67</td>
                        </tr>
                    
                        <tr>
                            <td class="align-center">3</td>
                            <td class="align-left"><i class="hide-tiny flag-icon quarter-right-space flag-icon-18" title="França"></i><a href="#">PSG</a></td>
                            <td class="align-center">1</td>
                            <td class="align-center hide-tiny hide-small">1</td>
                            <td class="align-center">1.00</td>
                        </tr>
                    
                </tbody>
            </table>
	    </div>


	    <h5 class="condensed-400 headerTitle top-space">Equipas - Vitórias <span class="headerTitle-border"></span></h5>

	    <div class="overflow-auto">
	    	<table class="ink-table ink-table-f365 alternating all-100">
            
                <thead>
                    <tr>
                        <th class="align-left all-33">Edição</th>
                        <th class="align-left">Vencedor</th>
                        <th class="align-left all-33 hide-tiny hide-small">Segundo Classificado</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr>
                            <td class="align-left"><a href="/competicao/1429/?edition=2025">2025</a></td>
                            <td class="align-left">
                                <a href="#"><i class="hide-tiny flag-icon quarter-right-space "><img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/780.gif" alt=""></i>PSG</a>
                            </td>
                            <td class="align-left hide-tiny hide-small">
                                <a href="#"><i class="hide-tiny flag-icon quarter-right-space"><img alt="" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/780.gif"></i>Flamengo</a>
                            </td>
                        </tr>
                    
                        <tr>
                            <td class="align-left"><a href="/competicao/1429/?edition=2024">2024</a></td>
                            <td class="align-left">
                                <a href="#"><i class="hide-tiny flag-icon quarter-right-space"><img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/780.gif" alt=""></i>Real Madrid</a>
                            </td>
                            <td class="align-left hide-tiny hide-small">
                                <a href="#"><i class="hide-tiny flag-icon quarter-right-space "><img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/780.gif" alt=""></i>Pachuca</a>
                            </td>
                        </tr>
                    
                </tbody>
            
        </table>
	    </div>
	    <h4 class="condensed-400 headerTitle top-space">Palmarés <span class="headerTitle-border"></span></h4>
	    <div class="overflow-auto">
	    	<table class="ink-table alternating ink-table-f365 all-100">
                <thead>
                    <tr>
                        <th class="align-left all-30">Competição</th>
                        <th class="align-center all-10">Total</th>
                        <th class="align-left">Edições</th>
                    </tr>
                </thead>
                <tbody>
                    	<?php for ($i=0; $i < 5; $i++) { ?>
                    		<tr>
	                            <td class="align-left">
	                                <i title="Campeonato Nacional" alt="Campeonato Nacional" class="hide-tiny flag-icon quarter-right-space flag-icon-4"><img alt="" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/fr.svg"></i><a href="/competicao/72/">Campeonato Nacional</a>
	                            </td>
	                            <td class="align-center">
	                                13
	                            </td>
	                            <td class="align-left">
	                                <a href="#">2018-2019</a>, 
	                                <a href="#">2018</a>, 
	                                <a href="#">2017</a>, 
	                                <a href="#">2016</a>, 
	                                <a href="#">2006</a>, 
	                                <a href="#">1999</a>, 
	                                <a href="#">1998</a>, 
	                                <a href="#">1996</a>, 
	                                <a href="#">1992</a>, 
	                                <a href="#">1991</a>, 
	                                <a href="#">1981</a>, 
	                                <a href="#">1980</a>, 
	                                <a href="#">1979</a>
	                            </td>
	                        </tr>
                    	<?php } ?>
                </tbody>
            </table>
	    </div>

	    <h5 class="headerTitleSimpleBackground top-space">Classificação Geral</h5>
	    <div class="overflow-auto">
	    	<table class="ink-table ink-table-futebol365 alternating hover">
	    		<thead>
	    			<tr>
			            <th class="align-center all-5 tiny-10 small-10"></th>
			            <th class="align-left">Equipa</th>
			            <th class="align-center all-5 tiny-10 small-10"><span class="ink-tooltip" data-tip-text="Jogos" data-tip-where="up" data-tip-color="black">J</span></th>
			            <th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Vitórias" data-tip-where="up" data-tip-color="black">V</span></th>
			            <th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Empates" data-tip-where="up" data-tip-color="black">E</span></th>
			            <th class="align-center all-5 small-10 hide-tiny"><span class="ink-tooltip" data-tip-text="Derrotas" data-tip-where="up" data-tip-color="black">D</span></th>
			            <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Golos Marcados" data-tip-where="up" data-tip-color="black">GM</span></th>
			            <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Golos Sofridos" data-tip-where="up" data-tip-color="black">GS</span></th>
			            <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Diferença de Golos" data-tip-where="up" data-tip-color="black">DG</span></th>
			            <th class="align-center all-5 tiny-10 small-10"><span class="ink-tooltip" data-tip-text="Pontos" data-tip-where="up" data-tip-color="black">P</span></th>
			            <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Diferença Pontual" data-tip-where="up" data-tip-color="black">DP</span></th>
			            <th class="align-center all-5 hide-tiny hide-small"></th>
			        </tr>
	    		</thead>
	    		<tbody>
	    			<?php for ($i=0; $i < 5; $i++) { ?>
	    				<tr>
                
		                    <td class="align-center"><span class="ink-label ink-label-table-position" style="background-color:#B5DE8C">1</span></td>
		                
		                
		                    <td class="align-left"><a href="/equipa/300/jogos/?edition=2025&amp;modality=football&amp;gender=male&amp;step=principal">FC Porto</a></td>
		                
		                <td class="align-center" title="Jogos"><a href="/equipa/300/jogos/?edition=2025&amp;modality=football&amp;gender=male&amp;step=principal&amp;competition=12607&amp;match_option=matches&amp;opponent=all&amp;competition_phase=">29</a></td>
		                <td class="align-center hide-tiny" title="Vitórias"><a href="/equipa/300/jogos/?edition=2025&amp;modality=football&amp;gender=male&amp;step=principal&amp;competition=12607&amp;match_option=victories&amp;opponent=all&amp;competition_phase=">24</a></td>
		                <td class="align-center hide-tiny" title="Empates"><a href="/equipa/300/jogos/?edition=2025&amp;modality=football&amp;gender=male&amp;step=principal&amp;competition=12607&amp;match_option=draws&amp;opponent=all&amp;competition_phase=">4</a></td>
		                <td class="align-center hide-tiny" title="Derrotas"><a href="/equipa/300/jogos/?edition=2025&amp;modality=football&amp;gender=male&amp;step=principal&amp;competition=12607&amp;match_option=loses&amp;opponent=all&amp;competition_phase=">1</a></td>
		                <td class="align-center hide-tiny hide-small" title="Golos Marcados"><a href="/equipa/300/jogos/?edition=2025&amp;modality=football&amp;gender=male&amp;step=principal&amp;competition=12607&amp;match_option=scored_goals&amp;opponent=all&amp;competition_phase=">59</a></td>
		                <td class="align-center hide-tiny hide-small" title="Golos Sofridos"><a href="/equipa/300/jogos/?edition=2025&amp;modality=football&amp;gender=male&amp;step=principal&amp;competition=12607&amp;match_option=suffered_goals&amp;opponent=all&amp;competition_phase=">14</a></td>
		                <td class="align-center hide-tiny hide-small" title="Diferença de Golos">45</td>
		                <td class="align-center" title="Pontos"><b>76</b></td>
		                <td class="align-center hide-tiny hide-small" title="Diferença de Pontos">0</td>
		                <td class="align-center hide-tiny hide-small">
		                    <a href="/equipa/300/jogos/?edition=2025&amp;modality=football&amp;gender=male&amp;step=principal&amp;competition=12607&amp;match_option=matches&amp;opponent=all&amp;competition_phase=">
		                        <svg class="svg-icon" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#icon-plus"></use></svg>
		                    </a>
		                </td>
		            </tr>
	    			<?php } ?>
	    		</tbody>
	    	</table>
	    </div>

	    <div class="column-group top-space">
		    <h6 class="headerTitleSimple">Notas da classificação</h6>
		    <table class="ink-table ink-table-futebol365 noborder">
		        <thead></thead><tbody>
		            
		                <tr>
		                    <td class="align-center all-5 tiny-10 small-10"><span class="ink-label ink-label-table-position" style="background-color:#B5DE8C">&nbsp;</span></td>
		                    <td class="align-left">Liga Campeões - Fase de Liga</td>
		                </tr>
		            
		                <tr>
		                    <td class="align-center all-5 tiny-10 small-10"><span class="ink-label ink-label-table-position" style="background-color:#CDF6A4">&nbsp;</span></td>
		                    <td class="align-left">Liga Campeões - Qualificação</td>
		                </tr>
		            
		                <tr>
		                    <td class="align-center all-5 tiny-10 small-10"><span class="ink-label ink-label-table-position" style="background-color:#B9CDDF">&nbsp;</span></td>
		                    <td class="align-left">Liga Europa - Fase de Liga</td>
		                </tr>
		            
		                <tr>
		                    <td class="align-center all-5 tiny-10 small-10"><span class="ink-label ink-label-table-position" style="background-color:#D1E7F1">&nbsp;</span></td>
		                    <td class="align-left">Liga Europa - Qualificação</td>
		                </tr>
		            
		                <tr>
		                    <td class="align-center all-5 tiny-10 small-10"><span class="ink-label ink-label-table-position" style="background-color:#FFCD7D">&nbsp;</span></td>
		                    <td class="align-left">Liga Conferência Europa - Qualificação</td>
		                </tr>
		            
		                <tr>
		                    <td class="align-center all-5 tiny-10 small-10"><span class="ink-label ink-label-table-position" style="background-color:#FFD2D2">&nbsp;</span></td>
		                    <td class="align-left">Despromoção - Play-off</td>
		                </tr>
		            
		                <tr>
		                    <td class="align-center all-5 tiny-10 small-10"><span class="ink-label ink-label-table-position" style="background-color:#FFB0B0">&nbsp;</span></td>
		                    <td class="align-left">Despromoção - Liga Portugal 2</td>
		                </tr>
		            
		        </tbody>
		    </table>
		</div>

		<h3 class="condensed-400 headerTitle top-space">Estádios <span class="headerTitle-border"></span></h3>

		<div class="column-group half-gutters">
			<?php for ($i=0; $i < 4; $i++) { ?>
				<div class="all-50 small-100 tiny-100">
	                <div class="obj-right-div obj-flex">
	                    <div class="obj-image-stadium">
	                        <img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/7079c207a7c530465ffed4d16b64e7db.jpg" alt="">
	                    </div>
	                    <div class="obj-detail">
	                        <div class="obj-name"><a href="#">1º de Dezembro</a></div>
	                        <div class="obj-link">Gondomar</div>
	                        <div class="obj-link">1000 lugares</div>
	                    </div>
	                </div>
	            </div>
			<?php } ?>
		</div>

		<div class="column-group half-horizontal-gutters">
			<div class="all-50 small-100 tiny-100">
				<h5 class="condensed-400 headerTitle blue">Sporting </h5>
				<table class="ink-table ink-table-f365 alternating all-100">
					<thead>
						<tr>
							<th class="align-center all-10">Nº</th>
							<th class="align-left all-90">Jogador</th>
						</tr>
					</thead>
					<tbody>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									1
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/9268/jogos/?edition=2011">Rui Patrício</a>

								<div class="div_event_minute" title="Cartão amarelo (89')"><span class="yellow_card span_event_minute"></span><span class="event_minute span_event_minute">89'</span></div>

								<div class="div_event_minute" title="Substituição (saiu) (89')"><span class="substitution_out span_event_minute"></span><span class="event_minute span_event_minute">89'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									47
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/1215/jogos/?edition=2011">João Pereira</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									48
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/12105/jogos/?edition=2011">Emiliano Insua</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									93
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/20126/jogos/?edition=2011">Xandão</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									3
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/14862/jogos/?edition=2011">Daniel Carriço</a>

								<div class="div_event_minute" title="Cartão amarelo (89')"><span class="yellow_card span_event_minute"></span><span class="event_minute span_event_minute">89'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									8
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/4676/jogos/?edition=2011">Stijn Schaars</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									14
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/11852/jogos/?edition=2011">Matias Fernandez</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									77
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/19969/jogos/?edition=2011">Elias</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									18
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/26611/jogos/?edition=2011">André Carrillo</a>

								<div class="div_event_minute" title="Substituição (saiu) (78')"><span class="substitution_out span_event_minute"></span><span class="event_minute span_event_minute">78'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									17
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/20068/jogos/?edition=2011">Jeffrén Suárez</a>

								<div class="div_event_minute" title="Substituição (saiu) (71')"><span class="substitution_out span_event_minute"></span><span class="event_minute span_event_minute">71'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number blue">
									9
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/18735/jogos/?edition=2011">Ricky van Wolfswinkel</a>

								<div class="div_event_minute" title="Golo (84')"><span class="goal span_event_minute"></span><span class="event_minute span_event_minute">84'</span></div>

								<div class="div_event_minute" title="Golo (61')"><span class="goal span_event_minute"></span><span class="event_minute span_event_minute">61'</span></div>

								<div class="div_event_minute" title="Golo (34')"><span class="goal span_event_minute"></span><span class="event_minute span_event_minute">34'</span></div>

							</td>
						</tr>

					</tbody>
				</table>
			</div>
			<div class="all-50 small-100 tiny-100">
				<h5 class="condensed-400 headerTitle green">Sp. Braga  </h5>
				<table class="ink-table ink-table-f365 alternating all-100">
					<thead>
						<tr>
							<th class="align-center all-10">Nº</th>
							<th class="align-left all-90">Jogador</th>
						</tr>
					</thead>
					<tbody>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									1
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/1201/jogos/?edition=2011">Quim</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									4
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/15343/jogos/?edition=2011">Nuno André Coelho</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									5
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/27647/jogos/?edition=2011">Ewerton</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									13
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/14766/jogos/?edition=2011">Miguel Lopes</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									23
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/26812/jogos/?edition=2011">Emmanuel Imorou</a>

								<div class="div_event_minute" title="Substituição (saiu) (55')"><span class="substitution_out span_event_minute"></span><span class="event_minute span_event_minute">55'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									20
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/18344/jogos/?edition=2011">Elderson</a>

								<div class="div_event_minute" title="Cartão amarelo (52')"><span class="yellow_card span_event_minute"></span><span class="event_minute span_event_minute">52'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									45
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/1674/jogos/?edition=2011">Hugo Viana</a>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									27
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/1669/jogos/?edition=2011">Custódio Castro</a>

								<div class="div_event_minute" title="Substituição (saiu) (79')"><span class="substitution_out span_event_minute"></span><span class="event_minute span_event_minute">79'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									90
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/1193/jogos/?edition=2011">Rúben Amorim</a>

								<div class="div_event_minute" title="Substituição (saiu) (68')"><span class="substitution_out span_event_minute"></span><span class="event_minute span_event_minute">68'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									8
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/8920/jogos/?edition=2011">Márcio Mossoró</a>

								<div class="div_event_minute" title="Cartão amarelo (63')"><span class="yellow_card span_event_minute"></span><span class="event_minute span_event_minute">63'</span></div>

							</td>
						</tr>

						<tr>
							<td class="align-center">
								<span class="ink-label ink-label-number green">
									18
								</span>
							</td>
							<td class="align-left">
								<a href="/jogador/18026/jogos/?edition=2011">Lima</a>

								<div class="div_event_minute" title="Golo (penalty) (88')"><span class="goal_penalty span_event_minute"></span><span class="event_minute span_event_minute">88'</span></div>

							</td>
						</tr>

					</tbody>
				</table>
			</div>
		</div>
		<div class="column-group half-horizontal-gutters">
			<div class="all-50 small-100 tiny-100">
				<h5 class="condensed-400 headerTitle blue">Suplentes</h5>
				<table class="ink-table ink-table-f365 alternating all-100">
                    <thead>
                        <tr>
                            <th class="align-center all-10">Nº</th>
                            <th class="align-left all-90">Jogador</th>
                        </tr>
                    </thead>
                    <tbody>
                        
                            <tr>
                                <td class="align-center">
                                    <span class="ink-label ink-label-number blue">
                                        11
                                    </span>
                                </td>
                                <td class="align-left">
                                    <a href="/jogador/10609/jogos/?edition=2011">Diego Capel</a>
                                    
                                        <div class="div_event_minute" title="Substituição (entrou) (71')"><span class="substitution_in span_event_minute"></span><span class="event_minute span_event_minute">71'</span></div>
                                    
                                </td>
                            </tr>
                        
                            <tr>
                                <td class="align-center">
                                    <span class="ink-label ink-label-number blue">
                                        6
                                    </span>
                                </td>
                                <td class="align-left">
                                    <a href="/jogador/1429/jogos/?edition=2011">Evaldo</a>
                                    
                                        <div class="div_event_minute" title="Cartão amarelo (88')"><span class="yellow_card span_event_minute"></span><span class="event_minute span_event_minute">88'</span></div>
                                    
                                        <div class="div_event_minute" title="Substituição (entrou) (78')"><span class="substitution_in span_event_minute"></span><span class="event_minute span_event_minute">78'</span></div>
                                    
                                </td>
                            </tr>
                        
                            <tr>
                                <td class="align-center">
                                    <span class="ink-label ink-label-number blue">
                                        16
                                    </span>
                                </td>
                                <td class="align-left">
                                    <a href="/jogador/1656/jogos/?edition=2011">Tiago Ferreira</a>
                                    
                                        <div class="div_event_minute" title="Substituição (entrou) (89')"><span class="substitution_in span_event_minute"></span><span class="event_minute span_event_minute">89'</span></div>
                                    
                                </td>
                            </tr>
                        
                    </tbody>
                </table>
			</div>
			<div class="all-50 small-100 tiny-100">
				<h5 class="condensed-400 headerTitle green">Suplentes</h5>
				<table class="ink-table ink-table-f365 alternating all-100">
                    <thead>
                        <tr>
                            <th class="align-center all-10">Nº</th>
                            <th class="align-left all-90">Jogador</th>
                        </tr>
                    </thead>
                    <tbody>
                        
                            <tr>
                                <td class="align-center">
                                    <span class="ink-label ink-label-number green">
                                        10
                                    </span>
                                </td>
                                <td class="align-left">
                                    <a href="/jogador/8406/jogos/?edition=2011">Hélder Barbosa</a>
                                    
                                        <div class="div_event_minute" title="Golo (57')"><span class="goal span_event_minute"></span><span class="event_minute span_event_minute">57'</span></div>
                                    
                                        <div class="div_event_minute" title="Substituição (entrou) (55')"><span class="substitution_in span_event_minute"></span><span class="event_minute span_event_minute">55'</span></div>
                                    
                                </td>
                            </tr>
                        
                            <tr>
                                <td class="align-center">
                                    <span class="ink-label ink-label-number green">
                                        21
                                    </span>
                                </td>
                                <td class="align-left">
                                    <a href="/jogador/1213/jogos/?edition=2011">Nuno Gomes</a>
                                    
                                        <div class="div_event_minute" title="Substituição (entrou) (68')"><span class="substitution_in span_event_minute"></span><span class="event_minute span_event_minute">68'</span></div>
                                    
                                </td>
                            </tr>
                        
                            <tr>
                                <td class="align-center">
                                    <span class="ink-label ink-label-number green">
                                        7
                                    </span>
                                </td>
                                <td class="align-left">
                                    <a href="/jogador/14755/jogos/?edition=2011">Ukra</a>
                                    
                                        <div class="div_event_minute" title="Substituição (entrou) (79')"><span class="substitution_in span_event_minute"></span><span class="event_minute span_event_minute">79'</span></div>
                                    
                                </td>
                            </tr>
                        
                    </tbody>
                </table>
			</div>
		</div>
	</section>
	<section class="right-column-block">
		<h2 class="condensed-400 extralarge modtitle xlarge-half-top-space large-half-top-space small-top-space tiny-top-space"><a href="#">Últimas Notícias</a><span class="modtitle-border"></span></h2>
		<div class="column-group half-horizontal-gutters quarter-vertical-gutters small-gutters tiny-gutters">
								<div class="all-40 tiny-100">

					<figure class="ink-image ink-lazyload my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
						<img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/19cd1fcd9d2683f4f4d6448bd0c45285.jpg" title="" alt="">
					</figure>
				</div>
				<div class="all-60 tiny-100">
					<div class="quarter-bottom-space small condensed-400">15-04-2026 · 15:00</div>
					<h3 class="last-news-title condensed-700"><a href="#">Ex-leão alerta: "Atletas estão muito desgastados e a ficar já nos limites"</a></h3>
				</div>	
								<div class="all-40 tiny-100">

					<figure class="ink-image ink-lazyload my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
						<img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/19cd1fcd9d2683f4f4d6448bd0c45285.jpg" title="" alt="">
					</figure>
				</div>
				<div class="all-60 tiny-100">
					<div class="quarter-bottom-space small condensed-400">15-04-2026 · 15:00</div>
					<h3 class="last-news-title condensed-700"><a href="#">Ex-leão alerta: "Atletas estão muito desgastados e a ficar já nos limites"</a></h3>
				</div>	
								<div class="all-40 tiny-100">

					<figure class="ink-image ink-lazyload my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
						<img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/19cd1fcd9d2683f4f4d6448bd0c45285.jpg" title="" alt="">
					</figure>
				</div>
				<div class="all-60 tiny-100">
					<div class="quarter-bottom-space small condensed-400">15-04-2026 · 15:00</div>
					<h3 class="last-news-title condensed-700"><a href="#">Ex-leão alerta: "Atletas estão muito desgastados e a ficar já nos limites"</a></h3>
				</div>	
								<div class="all-40 tiny-100">

					<figure class="ink-image ink-lazyload my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
						<img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/19cd1fcd9d2683f4f4d6448bd0c45285.jpg" title="" alt="">
					</figure>
				</div>
				<div class="all-60 tiny-100">
					<div class="quarter-bottom-space small condensed-400">15-04-2026 · 15:00</div>
					<h3 class="last-news-title condensed-700"><a href="#">Ex-leão alerta: "Atletas estão muito desgastados e a ficar já nos limites"</a></h3>
				</div>	
						</div>
	
		<h3 class="condensed-400 extralarge modtitle top-space"><a href="#">Sondagem</a><span class="modtitle-border"></span></h3>
		<div class="ink-form-sondagem bottom-space">
			<form class="ink-form" action="" method="post"><div style="display:none"><input type="hidden" name="csrfmiddlewaretoken" value="KJjqyNZtZYb4h1yTBvHNLS2kLcUj5Yt9"></div>
				<div class="control-group">

					<p class="large condensed-700 last-poll-question">Até onde pode chegar Portugal no Mundial 2026?</p>
					<ul class="control unstyled">

						<li><input type="radio" id="choice1" name="choice" value="1610"><label for="choice1" class="condensed-400">Não passa da fase de grupos</label></li>

						<li><input type="radio" id="choice2" name="choice" value="1611"><label for="choice2" class="condensed-400">Chega aos 16 avos de final</label></li>

						<li><input type="radio" id="choice3" name="choice" value="1612"><label for="choice3" class="condensed-400">Vai até aos «oitavos»</label></li>

						<li><input type="radio" id="choice4" name="choice" value="1613"><label for="choice4" class="condensed-400">Pode chegar aos «quartos»</label></li>

						<li><input type="radio" id="choice5" name="choice" value="1614"><label for="choice5" class="condensed-400">Ainda joga as meias-finais</label></li>

						<li><input type="radio" id="choice6" name="choice" value="1615"><label for="choice6" class="condensed-400">Vai à final e traz a Taça</label></li>

					</ul>

				</div>
				<input type="submit" value="Votar" class="ink-button success">
			</form>
		</div>
	</section>
</div>

<?php 
get_footer();
?>