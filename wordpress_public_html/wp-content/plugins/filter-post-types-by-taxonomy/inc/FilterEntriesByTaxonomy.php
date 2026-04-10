<?php

class FilterEntriesByTaxonomy
{
	/**
	 * This is used on URLs to separate terms from taxonomy. Double dash should be safe enough,
	 *  because WordPress slugs are stripped down of various/redundant stuff.
	 *
	 * @var string
	 */
	protected $termTaxonomySeparator = '--';

	public function __construct()
	{
		add_action('restrict_manage_posts', array(&$this, 'add_column_filters'));
		add_filter('parse_query', array(&$this, 'alter_query'));
	}

	public function add_column_filters()
	{
		$terms = '';

		if (isset($_GET['post_type'])) {
			$taxonomies = get_object_taxonomies($_GET['post_type'], 'objects');
			foreach ($taxonomies as $taxonomy) {
				$terms .= $this->getTaxonomySelector($taxonomy);
			}
		}

		if (empty($terms)) {
			return;
		}

		$select = sprintf('<select name="filter-by-taxonomy"><option value="-1">%s</option>', __('All taxonomies'));
		$select .= $terms;
		$select .= '</select>';

		echo $select;
	}

	public function alter_query($query)
	{
		if (isset($_GET['filter-by-taxonomy'])) {
			$getQuery = explode($this->termTaxonomySeparator, $_GET['filter-by-taxonomy']);
			$query_vars = &$query->query_vars;

			if (count($getQuery) == 2) {
				$query_vars[$getQuery[0]] = $getQuery[1];
			}
		}
	}

	protected function getTaxonomySelector($taxonomy)
	{
		$terms = $this->getTaxonomyTerms($taxonomy->name);

		if (empty($terms)) {
			return;
		}

		$selector = sprintf('<optgroup label="%s">', $taxonomy->labels->name);
		$selector .= $terms;
		$selector .= '</optgroup>';

		return $selector;
	}

	protected function getTaxonomyTerms($taxonomyName, $parent = 0, $level = 0)
	{
		$terms = get_terms($taxonomyName, array(
			'orderby' => 'name',
			'order' => 'ASC',
			'hide_empty' => true,
			'fields' => 'all',
			'hierarchical' => false,
			'childless' => false,
			'parent' => $parent,
			'cache_domain' => 'core',
		));

		if (empty($terms)) {
			return;
		}

		$indent = '';

		if ($level > 0) {
			$indent = '|' . str_repeat('&mdash;', $level);
			$indent .= ' ';
		}

		$selector = '';

		foreach ($terms as $term) {
			$selector .= sprintf('<option value="%s"%s>%s%s</option>',
				$taxonomyName . $this->termTaxonomySeparator . $term->slug,
				selected(true, $this->getActiveTaxonomyFilter($term), false),
				$indent,
				$term->name
			);
			$selector .= $this->getTaxonomyTerms($taxonomyName, $term->term_id, ++$level);
		}
		return $selector;
	}

	protected function getActiveTaxonomyFilter($term)
	{
		$wpFiltering = isset($_GET[$term->taxonomy]) && $_GET[$term->taxonomy] == $term->slug;
		$customFiltering = null;

		if (isset($_GET['filter-by-taxonomy'])) {
			$getQuery = explode($this->termTaxonomySeparator, $_GET['filter-by-taxonomy']);
			$customFiltering = isset($getQuery[1]) && $getQuery[1] == $term->slug;
		}

		return $wpFiltering || $customFiltering;
	}
}
