<?php

class sForms {
	private $fieldName = '';
	private static $fields = array();
	private $field = array();
	public static $data = array();
	private static $dataArray;
	public static $errors = array();


	public static function init( $initialData, $dataArray = 'Form' ) {
		self::$fields    = $initialData;
		self::$dataArray = $dataArray;
	}

	public static function setDataItem( $item, $value ) {
		self::$data[$item] = $value;
	}

	public static function setData( $data ) {
		self::$data = $data;
	}

	public static function getData( $fieldName ) {

		return empty( self::$data[$fieldName] ) ? '' : self::$data[$fieldName];
	}


	public static function validate() {

		/* basic empty check */
		$errors = array();
		foreach ( self::$fields as $fieldName => $fieldData ) {
			$Field = new self( $fieldName );
			$value = $Field->getValue();

			if ( ! empty( $fieldData['required'] ) && empty( $value ) ) {
				$errors[$fieldName] = 'empty';
				$Field->error       = 'empty';
				//$Field->addClass('error');


			} elseif ( ! empty( $fieldData['validate_rule'] ) ) {

				if ( method_exists( get_class(), 'validate_' . $fieldData['validate_rule'] ) ) {

					call_user_func_array(
						array( get_class(), 'validate_' . $fieldData['validate_rule'] ),
						array( &$Field, &$errors )
					);
				} elseif ( is_callable( $fieldData['validate_rule'] ) ) {


					call_user_func_array(
						$fieldData['validate_rule'],
						array( &$Field, &$errors )
					);

				}


			}

		}
		if ( empty( $errors ) ) {
			return true;
		} else {
			self::$errors = $errors;

			return false;
		}
	}


	private static function validate_email( sForms $Field, &$errors ) {

		if ( ! is_email( $Field->getValue() ) ) {
			$errors[$Field->id()] = 'not_email';
		} elseif ( false !== get_user_by( 'email', $Field->getValue() ) ) {
			$errors[$Field->id()] = 'email_used';
		}
	}

	private static function validate_captcha( sForms $Field, &$errors ) {

		if ( ! Captcha::check( $Field->getValue() ) ) {
			$errors['captcha'] = true;
		}
	}

	private function __construct( $fieldName, $args = array() ) {

		//print_r(self::$fields);
		$this->fieldName   = $fieldName;
		$this->field       = (object) self::$fields[$fieldName];
		$this->field->args = $args;

		if ( isset( $args['value'] ) ) {
			$this->field->value = $args['value'];
		} else {
			$this->field->value = empty( self::$data[$fieldName] ) ? '' : self::$data[$fieldName];
		}


		if ( empty( $this->field->html_options ) ) {
			$this->field->html_options = array();
		}

		if ( ! empty( $args['html_options'] ) ) {
			$this->field->html_options = (array) $args['html_options'] + (array) $this->field->html_options;
		}

	}

	public function id() {
		return $this->fieldName;
	}

	public function getName() {
		return self::$dataArray . '[' . $this->fieldName . ']';
	}

	public function getValue() {

		if ( empty( $this->field->value ) ) {
			return null;
		}

		if ( is_array( $this->field->value ) ) {
			return stripslashes_deep( $this->field->value );
		} else {
			return esc_attr( stripslashes( $this->field->value ) );
		}
	}

	public function getHtmlOptions() {

		$options = '';

		if ( ! empty( $this->field->html_options ) AND is_array( $this->field->html_options ) ) {

			foreach ( $this->field->html_options as $key => $value ) {
				$newOptions[] = $key . '=' . '"' . esc_attr( $value ) . '"';
			}
			$options = implode( ' ', $newOptions );
		}

		return $options;

	}

	public static function textField( $fieldName, $args = '' ) {

		$Field = new self( $fieldName, $args );

		$name    = $Field->getName();
		$value   = $Field->getValue();
		$options = $Field->getHtmlOptions();

		$type = empty( $args['type'] ) ? 'text' : $args['type'];

		return '<input type="' . $type . '" name="' . $name . '"  value="' . $value . '" ' . $options . ' />';
	}

    public static function imageField( $fieldName, $args = '' ) {

        $Field = new self( $fieldName, $args );

        $name    = $Field->getName();
        $value   = $Field->getValue();

        if(empty($value)){
            $delete_status = 'style="display: none;"';
        }else{
            $delete_status = '';
        }


        $html = '<div class="stm_image">';
        $html .= '<input type="button" class="single_image_select button button-primary button-large" name="media" value="'.esc_attr__( 'Select', 'champion' ).'">&nbsp;';
        $html .= '<input '.$delete_status.' type="button" name="media" class="remove_media button button-primary button-large" value="'.esc_attr__( 'Delete', 'champion' ).'">';

        $html .= '<input type="hidden" name="'.$name.'" class="new_id_list" value="'.$value.'">';

        $html .= '<div class="images"><ul>';
            if ( !empty($value) ) {
                $value = explode(',',$value);
                foreach($value as $v){
                    $html .= '<li>' . wp_get_attachment_image( $v ) . '</li>';
                }
            }

        $html .= '</ul></div></div>';

        return $html;
    }


	public static function textarea( $fieldName, $args = '' ) {
		$Field = new self( $fieldName, $args );

		$name    = $Field->getName();
		$value   = $Field->getValue();
		$options = $Field->getHtmlOptions();

		$type = empty( $args['type'] ) ? 'text' : $args['type'];

		return '<textarea name="' . $name . '" ' . $options . ' />' . $value . '</textarea>';

	}

	public static function passwordField( $fieldName, $args = '' ) {
		$args['type'] = 'password';

		return self::textField( $fieldName, $args );
	}


	public static function checkboxList( $fieldName, $dataOptions, $args = '' ) {

		if ( empty( $dataOptions ) || ! is_array( $dataOptions ) ) {
			return false;
		}

		$type = empty( $args['type'] ) ? 'checkbox' : $args['type'];

		$Field = new self( $fieldName, $args );
		$value = $Field->getValue();

		foreach ( $dataOptions as $optionKey => $optionValue ) {

			$args = array( 'value' => $optionKey, 'type' => $type );
			if ( $value == $optionKey ) {
				$args['html_options'] = array( 'checked' => 'checked' );
			}
			$options[] = '<label>' . self::textField( $fieldName, $args ) . ' ' . $optionValue . '</label>';

		}

		$separator = empty( $args['separator'] ) ? '' : $args['separator'];

		return implode( $separator, $options );
	}


	public static function radioList( $fieldName, $dataOptions, $args = '' ) {
		$args['type'] = 'radio';

		return self::checkboxList( $fieldName, $dataOptions, $args );
	}


	public static function checkbox( $fieldName, $args = '' ) {

		if ( empty( $args['value'] ) ) {
			$value = 1;
		} else {
			$value = $args['value'];
			unset( $args['value'] );
		}

		$Field = new self( $fieldName, $args );
		if ( $Field->getValue() == $value ) {
			$args['html_options'] = array( 'checked' => 'checked' );
		}
		$args['type'] = 'checkbox';
		/* back value */
		$args['value'] = $value;

		return self::textField( $fieldName, $args );
	}


	public static function selectbox( $fieldName, $dataOptions, $args = '' ) {


		if ( empty( $dataOptions ) || ! is_array( $dataOptions ) ) {
			return false;
		}

		$Field = new self( $fieldName, $args );

		$value = $Field->getValue();

		$name         = $Field->getName();
		$html_options = $Field->getHtmlOptions();


		if ( ! empty( $html_options['multiple'] ) ) {
			$name .= '[]';
		}

		$options[] = '<select name="' . $name . '" ' . $html_options . '>';
		foreach ( $dataOptions as $optionKey => $optionValue ) {

			if ( $value == $optionKey ) {
				$selected = 'selected="selected"';
			} elseif ( is_array( $value ) AND in_array( $optionKey, $value ) ) {
				$selected = 'selected="selected"';
			} else {
				$selected = '';
			}
			$options[] = '<option value="' . esc_attr($optionKey) . '" ' . $selected . '>' . $optionValue . '</option>';
		}
		$options[] = '</select>';

		$separator = empty( $args['separator'] ) ? '' : $args['separator'];

		return implode( $separator, $options );

	}

	public static function label( $fieldName, $args = '' ) {

		$Field = new self( $fieldName, $args );
		$icon = '';
		if(!empty($Field->field->icon)){
            $icon = '<i class="fa '.$Field->field->icon.'"></i> ';
		}
		return '<label>'.$icon . $Field->field->label . '</label>';
	}

	public static function actionField( $fieldName ) {
		return '<input type="hidden" name="stm_action" value="' . esc_attr($fieldName) . '">';
	}

	public static function has_error( $fieldName ) {

		if ( is_array( $fieldName ) ) {

			if ( array_intersect_assoc( $fieldName, self::$errors ) ) {
				return true;
			} else {
				return false;
			}
		} else {
			return empty( self::$errors[$fieldName] ) ? false : true;
		}
	}


	public function addClass( $className ) {

		if ( empty( $this->field->html_options['class'] ) ) {
			$this->field->html_options['class'] = ' ' . $className;
		} else {
			$this->field->html_options['class'] .= ' ' . $className;
		}
	}


	/* everything else is text fields  with type */
	public static function __callStatic( $name, $arguments ) {

		$arguments[1]['type'] = $name;
		if ( ! empty( $arguments[0] ) AND ! empty( $arguments[1] ) ) {
			return self::textField( $arguments[0], $arguments[1] );
		}

	}
}
