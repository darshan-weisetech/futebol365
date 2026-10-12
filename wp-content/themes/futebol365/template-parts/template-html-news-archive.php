<?php
/*
* Template Name: News Archive 
*/
get_header();
 ?>
<?php get_template_part('template-parts/template-offer-widget'); ?>
<div class="ink-grid futebol365-container">
	<section class="left-column-block">
		<div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
                <?php yoast_breadcrumb(); ?>
            </div>
        </div>
        <div class="column-group gutters">
        	<?php for ($i=0; $i < 8; $i++) { ?>
        		<div class="all-33 xlarge-25 small-50 tiny-100">
	                <h2 class="quarter-vertical-space large condensed-700"><a href="#">A Bola</a></h2>
	                <h3 class="medium condensed-400">14-04-2026</h3>
	                <figure class="ink-image ink-lazyload half-bottom-space" data-delta="300">
                        <a href="#">
                            <img src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/2fcff68e0c752851f7e386d62d661fb3.jpg" class="lazyload-item" title="" alt="">
                        </a>
	                </figure>
	            </div>
        	<?php } ?>
        </div>
        <div class="all-100">
			<div class="paginacao-new">
			    <nav class="ink-navigation">
			        <ul class="pagination">
						<li><a class="active">1</a></li>
						<li><a href="?pagina=2">2</a></li>
						<li class="hide-tiny hide-small"><a href="?pagina=2">Seguinte »</a></li>
			        </ul>
			    </nav>
			</div>  
	    </div>
	    <h2 class="condensed-400 extralarge modtitle top-space">Na Primeira Página <span class="modtitle-border"></span></h2>
	    <div class="column-group gutters">
	    	<?php for ($i=0; $i < 4; $i++) {  ?>
	    		<div class="all-25 small-50 tiny-100">
                    <a href="#">
                        <figure class="ink-image ink-lazyload half-bottom-space my-thumb-3-2" data-delta="300" data-loaded-class="loaded">
                            <img class="lazyload-item my-thumb-image loaded" src="https://weisetech.dev/futebol365/wp-content/uploads/2026/05/f4e75e89195a4f86dc25b14f7b71f5b3.jpg" title="" alt="">
                        </figure>
                    </a>
                    <div class="quarter-vertical-space medium condensed-400">15-04-2026 · 15:00</div>
                    <h3 class="large condensed-700"><a href="#">Ex-leão alerta: "Atletas estão muito desgastados e a ficar já nos limites"</a></h3>
                </div>
	    	<?php } ?>
	    </div>
	</section>
	<section class="right-column-block">
		<?php get_sidebar();?>
	</section>
</div>

 <?php
get_footer();
?>