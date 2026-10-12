<?php 
/*
* Template Name: Video html
*/
get_header();
?>
<div class="ink-grid vertical-space">
	<?php get_template_part( 'template-parts/template-offer-widget' );?>

	<div class="ink-header-f365 multimedia">
        <div class="column-group" >
            <div class="align-left" >
                <div class="headerdata">
                    <h1>Vídeos</h1>
                </div>
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

        <div class="arbitro-title">
        	<div class="image-block">
        		<i><img src="https://futebol365.pt/static/website/img/layout/flags/64/2.png" alt=""></i>
        		<h2>Hélder Malheiro</h2>
        	</div>
        	<ul class="arbitro-links">
        		<li><a href="#" title="">Summary</a></li>
        		<li><a href="#" title=""><strong>Games</strong></a></li>
        	</ul>
        	<span></span>
        </div>
        <div class="arbitro-details">
        	<div class="thumb-img">
        		<img src="https://weisetech.dev/futebol365/wp-content/uploads/2016/01/122293.jpg" alt="">
        	</div>
        	<ul class="persion-info">
        		<li><strong>Name:</strong> <span>Hélder Miguel Azevedo Malheiro</span></li>
        		<li><strong>Date of Birth:</strong> <span>June 23, 1980</span></li>
        		<li><strong>Age:</strong> <span>45 years</span></li>
        		<li><strong>Place of Birth:</strong> <span>Ferreira Zêzere - Santarém</span></li>
        		<li><strong>Nationality:</strong> <span>Portugal</span></li>
        	</ul>
        </div>

        <div class="overflow-auto">
        	<div class="gray-bar-title">Summary</div>
        	<p>No information available.</p>

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
        </div>
        <div class="overflow-auto bottom-space">
        	<div class="green-bar-title">game summary</div>
        </div>
        <div class="overflow-auto">
        	<div class="tabelaJogoCompeticoes">
		        <table width="100%" align="center" border="0" cellspacing="1" cellpadding="0">
		            <tbody><tr class="headerTable">
		                <td align="left" valign="middle">Competição</td>
		                <td width="30" style="cursor: help;" align="center" valign="middle" title="Total de jogos">J</td>
		                <td width="30" style="cursor: help;" align="center" valign="middle" title="Cartões amarelos">A</td>
		                <td width="40" style="cursor: help;" align="center" valign="middle" title="Acumulação amarelos">AA</td>
		                <td width="30" style="cursor: help;" align="center" valign="middle" title="Cartões vermelhos">V</td>
		            </tr>
		            
		                <tr class="linhaImparTable">
		                    <td valign="middle" align="right"><b>Total</b></td>
		                    <td align="center" class="negrito" title="Total de jogos">0</td>
		                    <td align="center" class="negrito" title="Cartões amarelos">0</td>
		                    <td align="center" class="negrito" title="Acumulação amarelos">0</td>
		                    <td align="center" class="negrito" title="Cartões vermelhos">0</td>
		                </tr>
		            
		        </tbody></table>
		    </div>
        </div>
        <div class="column-group gutters">
        	<?php for ($i=0; $i < 10; $i++) { ?>
        		<div class="all-25 small-50 tiny-100">
		            <figure class="ink-image ink-lazyload half-bottom-space my-thumb-16-9" data-delta="300" data-loaded-class="loaded">
	                	<a href="#">
	                        <img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/59f32cd84254880aa69c5bf4f287d16b.jpg" title="" alt="">
	                    </a>
	                    <figcaption class="over-bottom dark figcaption-videos">00:54</figcaption>
		            </figure>
		            <h3 class="quarter-vertical-space medium uppercase condensed-400">13-04-2026</h3>
		            <h2 class="large condensed-700"><a href="#">Liga Portugal Betclic: Tondela 2 - 2 Gil Vicente (2025-2026)</a></h2>
		        </div>
        	<?php } ?>
        	<?php for ($i=0; $i < 10; $i++) { ?>
        		<div class="all-25 small-50 tiny-100">
		            <figure class="ink-image ink-lazyload half-bottom-space my-thumb-16-9" data-delta="300" data-loaded-class="loaded">
	                	<a href="#">
	                        <img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/59f32cd84254880aa69c5bf4f287d16b.jpg" title="" alt="">
	                        <svg class="video-thumbnail-icon video-thumbnail-icon-svg small" aria-hidden="true" focusable="false"><use href="#icon-play"></use></svg>
	                    </a>
		            </figure>
		            <h3 class="quarter-vertical-space medium uppercase condensed-400">13-04-2026</h3>
		            <h2 class="large condensed-700"><a href="#">Liga Portugal Betclic: Tondela 2 - 2 Gil Vicente (2025-2026)</a></h2>
		        </div>
        	<?php } ?>
        </div>
        <div class="all-100">
        	<div class="paginacao-new">
			    <nav class="ink-navigation">
			        <ul class="pagination">
	                    <li><a class="active">1</a></li>
	                    <li><a href="?pagina=2">2</a></li>
	                    <li><a href="?pagina=3">3</a></li>
		                <li><span>...</span></li>
		                <li><a href="?pagina=20">20</a></li>
		                <li class="hide-tiny hide-small"><a href="?pagina=2">Seguinte »</a></li>
			        </ul>
			    </nav>
			</div>
		</div>
		<h2 class="condensed-400 extralarge modtitle top-space">Na Primeira Página</h2>
		<div class="column-group gutters">
			<?php for ($i=0; $i < 4; $i++) { ?>
				<div class="all-25 small-50 tiny-100">
		            <a href="#">
		                <figure class="ink-image ink-lazyload half-bottom-space my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
		                    <img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/19cd1fcd9d2683f4f4d6448bd0c45285.jpg" title="" alt="">
		                </figure>
		            </a>
		            <div class="quarter-vertical-space medium condensed-400">15-04-2026 · 15:00</div>
		            <h3 class="large condensed-700"><a href="#">Ex-leão alerta: "Atletas estão muito desgastados e a ficar já nos limites"</a></h3>
		        </div>  	
			<?php } ?>
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
	
		<h3 class="condensed-400 extralarge modtitle"><a href="#">Sondagem</a><span class="modtitle-border"></span></h3>
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