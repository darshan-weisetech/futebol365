<?php 
/*
* Template Name: Video single html
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
                    <a href="#"><h1 >Liga Campeões test</h1></a>
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
            <div class="align-right medium-align-left small-align-left tiny-align-left all-40 medium-100 small-100 tiny-100 medium-top-space small-top-space tiny-top-space banner-filter-block" >
                <form class="ink-form" >
				    <div class="control-group" >
				        <select name="edition" id="edition" >
				        	<?php for ($i=0; $i < 5; $i++) { ?>
				        		<option value="2025" selected="selected" >2025-2026</option>
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
        <div class="column-group gutters">
        	<div class="all-100">
	            <div class="videoDetail">
	                
	                    <iframe class="iframeVideoDetail" width="560" height="315" src="https://www.youtube.com/embed/EngW7tLk6R8?si=IpYCCpbwopBqSTmd" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
	                
	            </div>
	        </div>
        </div>
        <script src="https://apis.google.com/js/platform.js"></script>
<div class="subscribe bottom-space">
<div class="g-ytsubscribe" data-channel="GoogleDevelopers" data-layout="default" data-count="default"></div>	
</div>

        <h3 class="condensed-400 modtitle">Relacionados</h3>
        <div class="column-group gutters">
        	<?php for ($i=0; $i < 10; $i++) { ?>
        		<div class="all-25 small-50 tiny-100">
                    <figure class="ink-image ink-lazyload half-bottom-space my-thumb-16-9" data-delta="300" data-loaded-class="loaded">
                        
                            <a href="#">
                                <img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/2c7fbe909b8400ca79a936faef942cb6.jpg" title="" alt="">
                            </a>
                            <figcaption class="over-bottom dark figcaption-videos">01:07</figcaption>
                    </figure>
                    <div class="quarter-vertical-space medium uppercase condensed-400">19-10-2025</div>
                    <h3 class="large condensed-700"><a href="#">Serie A: Milan vs Fiorentina, Golo 0-1 R. Gosens (2025-2026)</a></h3>
                </div>
        	<?php } ?>
        </div>
        <div class="all-100">
        	<h2 class="video-title">Liga Portugal Betclic: Tondela 2 - 2 Gil Vicente (2025-2026)</h2>
        	<div class="fw-500 video-date-and-views">13-04-2026 - 21 visualizações</div>
        	<div class="button-toolbar vertical-padding">
                <div class="button-group">
                    <a class="ink-button" href="/competicao/392/?edition=2025">Liga Portugal Betclic</a>
                </div>
                <div class="button-group">
                    <a class="ink-button" href="/jogo/1116816/">Ficha do jogo</a>
                </div>
                <div class="button-group">
                    <a class="ink-button" href="/equipa/342/jogos/">Gil Vicente</a>
                </div>
                <div class="button-group">
                    <a class="ink-button" href="/equipa/1961/jogos/">Tondela</a>
                </div>
            </div>
		</div>

        <div class="column-group gutters">
        	<div class="all-100">
        		<h1 class="extralarge condensed-700">O Jogo</h1>
        		<h2 class="bottom-space medium condensed-400">Edição de 14-04-2026</h2>
        		<div class="ink-image bottom-space">
        			<img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/2fcff68e0c752851f7e386d62d661fb3.jpg" title="" alt="" class="w-full">
        		</div>
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

        <div class="all-100 overflow-auto tb-mb-3">
        	<table class="ink-table alternating ink-table-f365">
		        <thead class="align-left">
		            <tr>
		                <th class="align-left">Edição</th>
		                <th class="align-left">Jogador</th>
		                <th class="align-left hide-small hide-tiny">Equipa</th>
		                <th class="align-left">Golos</th>
		            </tr>
		        </thead>
		        <tbody>
		        	<?php for ($i=0; $i < 10; $i++) { ?>
		        		<tr>
			                <td>2024-2025</td>
			                <td>
			                    <i class="hide-tiny flag-icon quarter-right-space"><img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/fr.svg" alt=""></i>
			                    <a href="#">Kylian Mbappé</a>
			                </td>
			                <td class="hide-small hide-tiny">Real Madrid</td>
		                    <td>31</td>
			            </tr>
		        	 <?php } ?>
			    </tbody>
		    </table>
        </div>
        <div class="overflow-auto">
        	<table class="ink-table ink-table-f365 alternating all-100">
            <thead>
                <tr>
                    <th class="align-center all-55"></th>
                    <th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Jogos" data-tip-where="up" data-tip-color="black">J</span></th>
                    <th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Minutos" data-tip-where="up" data-tip-color="black">M</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Jogos a titular" data-tip-where="up" data-tip-color="black">T</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Substituido" data-tip-where="up" data-tip-color="black">S</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Suplente utilizado" data-tip-where="up" data-tip-color="black">U</span></th>
                    <th class="align-center all-5"><span class="ink-tooltip" data-tip-text="Golos" data-tip-where="up" data-tip-color="black">G</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Cartões amarelos" data-tip-where="up" data-tip-color="black">A</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Acumulação amarelos" data-tip-where="up" data-tip-color="black">AA</span></th>
                    <th class="align-center all-5 hide-tiny hide-small"><span class="ink-tooltip" data-tip-text="Cartões vermelhos" data-tip-where="up" data-tip-color="black">V</span></th>
                </tr>
            </thead>
            <tbody>
                
                    <tr>
                        <td class="align-left">
                            <i class="hide-tiny flag-icon quarter-right-space flag-icon-conf_2" alt="Liga Campeões" title="Liga Campeões"></i>
                            <a href="/competicao/26/?edition=2006">Liga Campeões</a>
                        </td>
                        <td class="align-center" title="Total de jogos"><a href="?edition=2006&amp;competition=147">11</a></td>
                        <td class="align-center" title="Minutos">959</td>
                        <td class="align-center hide-tiny hide-small" title="Jogos a titular"><a href="?edition=2006&amp;competition=147&amp;stat=2">11</a></td>
                        <td class="align-center hide-tiny hide-small" title="Substituido"><a href="?edition=2006&amp;competition=147&amp;stat=3">2</a> </td>
                        <td class="align-center hide-tiny hide-small" title="Suplente utilizado"><a href="?edition=2006&amp;competition=147&amp;stat=4">0</a></td>
                        <td class="align-center" title="Golos"><a href="?edition=2006&amp;competition=147&amp;stat=5">3</a></td>
                        <td class="align-center hide-tiny hide-small" title="Cartões amarelos"><a href="?edition=2006&amp;competition=147&amp;stat=8">3</a></td>
                        <td class="align-center hide-tiny hide-small" title="Acumulação amarelos"><a href="?edition=2006&amp;competition=147&amp;stat=9">0</a></td>
                        <td class="align-center hide-tiny hide-small" title="Cartões vermelhos"><a href="?edition=2006&amp;competition=147&amp;stat=10">0</a></td>
                    </tr>
                
                    <tr>
                        <td class="align-left">
                            <i class="hide-tiny flag-icon quarter-right-space flag-icon-conf_1" alt="Mundial" title="Mundial"></i>
                            <a href="/competicao/2/?edition=2006">Mundial</a>
                        </td>
                        <td class="align-center" title="Total de jogos"><a href="?edition=2006&amp;competition=6">6</a></td>
                        <td class="align-center" title="Minutos">484</td>
                        <td class="align-center hide-tiny hide-small" title="Jogos a titular"><a href="?edition=2006&amp;competition=6&amp;stat=2">6</a></td>
                        <td class="align-center hide-tiny hide-small" title="Substituido"><a href="?edition=2006&amp;competition=6&amp;stat=3">2</a> </td>
                        <td class="align-center hide-tiny hide-small" title="Suplente utilizado"><a href="?edition=2006&amp;competition=6&amp;stat=4">0</a></td>
                        <td class="align-center" title="Golos"><a href="?edition=2006&amp;competition=6&amp;stat=5">1</a></td>
                        <td class="align-center hide-tiny hide-small" title="Cartões amarelos"><a href="?edition=2006&amp;competition=6&amp;stat=8">1</a></td>
                        <td class="align-center hide-tiny hide-small" title="Acumulação amarelos"><a href="?edition=2006&amp;competition=6&amp;stat=9">0</a></td>
                        <td class="align-center hide-tiny hide-small" title="Cartões vermelhos"><a href="?edition=2006&amp;competition=6&amp;stat=10">0</a></td>
                    </tr>
                
                <tr>
                    <td class="align-right"><b>Total</b></td>
                    <td class="align-center" title="Total de jogos"><a href="?edition=2006"><b>17</b></a></td>
                    <td class="align-center" title="Minutos"><b>1443</b></td>
                    <td class="align-center hide-tiny hide-small" title="Jogos a titular"><a href="?edition=2006&amp;stat=2"><b>17</b></a></td>
                    <td class="align-center hide-tiny hide-small" title="Substituido"><a href="?edition=2006&amp;stat=3"><b>4</b></a></td>
                    <td class="align-center hide-tiny hide-small" title="Suplente utilizado"><a href="?edition=2006&amp;stat=4"><b>0</b></a></td>
                    <td class="align-center" title="Golos"><a href="?edition=2006&amp;stat=5"><b>4</b></a></td>
                    <td class="align-center hide-tiny hide-small" title="Cartões amarelos"><a href="?edition=2006&amp;stat=8"><b>4</b></a></td>
                    <td class="align-center hide-tiny hide-small" title="Acumulação amarelos"><a href="?edition=2006&amp;stat=9"><b>0</b></a></td>
                    <td class="align-center hide-tiny hide-small" title="Cartões vermelhos"><a href="?edition=2006&amp;stat=10"><b>0</b></a></td>
                </tr>
            </tbody>
        </table>
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