<?php 
	$newscrunch_actions = get_query_var( 'newscrunch_actions', [] );
    $newscrunch_actions_todo = get_query_var( 'newscrunch_actions_todo', [] );
?>
<div id="recommended_actions" class="newscrunch-tab-pane panel-close">
	<div class="action-list">
		<?php 
		if($newscrunch_actions): 
			foreach ($newscrunch_actions as $key => $newscrunch_val): 
				if($newscrunch_val['id']!='install_one-click-demo-import' && $newscrunch_val['id']!='install_elementor'):?>
					<div class="action spice-col-3" id="<?php echo esc_attr($newscrunch_val['id']); ?>">
						<div class="action-box">
							<div class="action-watch">
							<?php if(!$newscrunch_val['is_done']): ?>
								<?php if(!isset($newscrunch_actions_todo[$newscrunch_val['id']]) || !$newscrunch_actions_todo[$newscrunch_val['id']]): ?>
									<span class="dashicons dashicons-visibility"></span>
								<?php else: ?>
									<span class="dashicons dashicons-hidden"></span>
								<?php endif; ?>
							<?php else: ?>
								<span class="dashicons dashicons-yes"></span>
							<?php endif; ?>
							</div>
							<div class="action-inner">
								<h3 class="action-title"><?php echo esc_html($newscrunch_val['title']); ?></h3>
								<div class="action-desc"><?php echo esc_html($newscrunch_val['desc']); ?></div>
								<?php echo wp_kses_post($newscrunch_val['link']); ?>
							</div>
						</div>
					</div>
					<?php 
				endif;
			endforeach; 
		endif; ?>
	</div>
</div>