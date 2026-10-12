<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
    <label>
        <input type="search"
               class="search-field"
               value="<?php echo get_search_query(); ?>"
               name="s" />
    </label>

    <button type="submit" class="search-submit">
        Pesquisar
    </button>
</form>