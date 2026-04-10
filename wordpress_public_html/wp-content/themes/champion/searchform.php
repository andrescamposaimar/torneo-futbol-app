<form role="search" method="get" id="searchform" class="searchform" action="<?php echo esc_url(home_url('/')); ?>">
    <input class="form-control" type="text" placeholder="<?php esc_attr_e('Search', 'champion'); ?>"
           value="<?php echo esc_attr(get_search_query()); ?>" name="s"/>
    <button class="btn btn-danger"><i class="fa fa-search"></i></button>
</form>