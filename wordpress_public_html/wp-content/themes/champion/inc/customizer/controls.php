<?php

function champion_stm_controls( $wp_customize ) {
	class Stm_Customize_Textarea_Control extends WP_Customize_Control {
		public $type = 'textarea';

		public function render_content() {
			?>

			<label>
				<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
				<textarea rows="5" style="width:100%;" <?php esc_url( $this->link() ); ?>><?php echo esc_textarea( $this->value() ); ?></textarea>
			</label>

		<?php
		}
	}

	class Stm_Customize_Description_Control extends WP_Customize_Control {
		public $type = 'description';
		public function render_content() {
			?>

			<p class="customize-description"><?php echo esc_html( $this->label ); ?></p>

		<?php
		}
	}

	class Stm_Customize_Bg_Control extends WP_Customize_Control {
		public $type = 'bg';
		public function render_content() {
			?>

			<label>
				<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>

				<div class="theme_bg">
					<ul>
						<?php foreach ( $this->choices as $itemName => $itemLabel ): ?>
							<li class="<?php echo esc_attr( $itemName ); ?>" <?php if($itemLabel){ ?>style="background-image: url('<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/bg/<?php echo esc_attr( $itemLabel ); ?>.jpg')"<?php } ?>>
								<label><input type="radio" <?php $this->link(); ?> name="<?php echo esc_attr( $this->id ); ?>" value="<?php echo esc_attr( $itemName ); ?>" title="<?php echo esc_attr( $itemLabel ); ?>"></label>
							</li>
						<?php endforeach; ?>

					</ul>
				</div>
			</label>

		<?php
		}
	}

	class Stm_Customize_SubTitle_Control extends WP_Customize_Control {
		public $type = 'subtitle';
		public function render_content() {
			?>

			<h4 class="stm_customizer_subtitle"><?php echo esc_html( $this->label ); ?></h4>

		<?php
		}
	}
}

add_action( 'customize_register', 'champion_stm_controls' );