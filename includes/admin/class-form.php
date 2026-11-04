<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Article\Cron_Schedule;
final class Form {
	private array $definitions;
	public function __construct( array $definitions ) {
		$this->definitions = $definitions;
	}
	public function has( string $key ): bool {
		return isset( $this->definitions[ $key ] );
	}
	public function build_list( array $values, array $keys, string $prefix = '', array $overrides = array() ): array {
		$fields = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $this->definitions[ $key ] ) ) {
				continue;
			}
			$value  = isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
			$name   = '' === $prefix ? $key : $prefix . '[' . $key . ']';
			$fields[] = $this->build( $name, $key, $value, (array) ( $overrides[ $key ] ?? array() ) );
		}
		return $fields;
	}
	public function sanitize( array $raw, array $current = array() ): array {
		$clean = array();
		foreach ( $this->definitions as $key => $definition ) {
			$type = (string) ( $definition['type'] ?? 'text' );
			if ( ! empty( $definition['field_disabled'] ) ) {
				if ( array_key_exists( $key, $current ) ) {
					$clean[ $key ] = $current[ $key ];
				}
				continue;
			}
			if ( 'checkbox_multi' === $type ) {
				$clean[ $key ] = self::choices_value( $raw[ $key ] ?? array(), (array) ( $definition['options'] ?? array() ) );
				continue;
			}
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
			if ( 'readonly' === $type || 'cron' === $type ) {
				continue;
			}
			if ( 'number' === $type ) {
				$digits        = (string) preg_replace( '/[^0-9]/', '', $value );
				$number        = '' === $digits ? (int) ( $definition['default'] ?? 0 ) : (int) $digits;
				$min           = (int) ( $definition['min'] ?? 0 );
				$max           = (int) ( $definition['max'] ?? 100 );
				$clean[ $key ] = (string) max( $min, min( $max, $number ) );
				continue;
			}
			if ( 'image' === $type ) {
				$clean[ $key ] = '' === trim( $value ) ? '' : (string) absint( $value );
				continue;
			}
			if ( 'select' === $type || 'radio' === $type ) {
				$options       = (array) ( $definition['options'] ?? array() );
				$clean[ $key ] = array_key_exists( $value, $options ) ? $value : (string) ( $definition['default'] ?? '' );
				continue;
			}
			if ( isset( $definition['normalize'] ) && is_callable( $definition['normalize'] ) ) {
				$normalized    = (string) call_user_func( $definition['normalize'], $value );
				$clean[ $key ] = mb_substr( $normalized, 0, (int) ( $definition['max'] ?? 200 ) );
				continue;
			}
			$sanitized = in_array( $type, array( 'textarea', 'category' ), true ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			$sanitized = mb_substr( $sanitized, 0, (int) ( $definition['max'] ?? 200 ) );
			if ( 'password' === $type && '' === $sanitized ) {
				$clean[ $key ] = isset( $current[ $key ] ) && is_scalar( $current[ $key ] ) ? (string) $current[ $key ] : '';
				continue;
			}
			$clean[ $key ] = $sanitized;
		}
		return $clean;
	}
	public static function mask_secret( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}
		if ( mb_strlen( $secret ) <= 6 ) {
			return str_repeat( '*', 8 );
		}
		return mb_substr( $secret, 0, 3 ) . str_repeat( '*', 20 ) . mb_substr( $secret, -3 );
	}
	private function build( string $name, string $key, string $value, array $overrides ): array {
		$definition = array_merge( $this->definitions[ $key ], $overrides );
		$type       = (string) ( $definition['type'] ?? 'text' );
		$is_select   = 'select' === $type;
		$is_radio    = 'radio' === $type;
		$is_checkbox = 'checkbox' === $type;
		$is_checkbox_multi = 'checkbox_multi' === $type;
		$is_category = 'category' === $type;
		$is_area     = 'textarea' === $type || $is_category;
		$is_secret   = 'password' === $type;
		$is_list     = 'datalist' === $type;
		$is_color    = 'color' === $type;
		$is_readonly = 'readonly' === $type;
		$is_cron     = 'cron' === $type;
		$is_number   = 'number' === $type;
		$is_image    = 'image' === $type;
		$is_choice = $is_radio || $is_checkbox || $is_checkbox_multi;
		$is_headless  = ! empty( $definition['headless'] );
		$exclusive_group = '';
		if ( $is_checkbox ) {
			$exclusive_group = ! empty( $definition['exclusive_group'] )
				? (string) $definition['exclusive_group']
				: ( ! empty( $definition['exclusive'] ) ? $name : '' );
		}
		$is_exclusive = '' !== $exclusive_group;
		$driver             = (string) ( $definition['driver'] ?? '' );
		$row_depends        = (array) ( $definition['row_depends'] ?? array() );
		$row_depends_on     = (string) ( $row_depends['on'] ?? '' );
		$row_depends_values = implode( ' ', array_map( 'strval', (array) ( $row_depends['values'] ?? array() ) ) );
		$option_depends     = (string) ( $definition['option_depends'] ?? '' );
		if ( ( $is_select || $is_radio || $is_number ) && '' === $value ) {
			$value = (string) ( $definition['default'] ?? '' );
		}
		$field      = array(
			'name'           => esc_attr( $name ),
			'label'          => esc_html( (string) ( $definition['label'] ?? '' ) ),
			'value'          => $is_area ? esc_textarea( $value ) : esc_attr( $value ),
			'placeholder'    => esc_attr( (string) ( $definition['placeholder'] ?? '' ) ),
			'description'    => wp_kses( (string) ( $definition['description'] ?? '' ), array( 'strong' => array() ) ),
			'warning'        => esc_html( (string) ( $definition['warning'] ?? '' ) ),
			'note'           => (string) ( $definition['note'] ?? '' ),
			'driver'             => esc_attr( $driver ),
			'row_depends_on'     => esc_attr( $row_depends_on ),
			'row_depends_values' => esc_attr( $row_depends_values ),
			'option_depends'     => esc_attr( $option_depends ),
			'picker_action'  => esc_attr( (string) ( $definition['picker_action'] ?? '' ) ),
			'picker_label'   => esc_html( (string) ( $definition['picker_label'] ?? '' ) ),
			'picker_clear_label' => esc_html( (string) ( $definition['picker_clear_label'] ?? '' ) ),
			'picker_target'  => esc_attr( (string) ( $definition['picker_target'] ?? '' ) ),
			'is_input'       => ! $is_readonly && ! $is_select && ! $is_choice && ! $is_area && ! $is_secret && ! $is_list && ! $is_color && ! $is_cron && ! $is_number && ! $is_image,
			'is_textarea'    => $is_area,
			'is_select'      => $is_select,
			'is_radio'       => $is_radio,
			'is_checkbox'    => $is_checkbox,
			'is_checkbox_multi' => $is_checkbox_multi,
			'is_choice'      => $is_choice,
			'is_password'    => $is_secret,
			'is_datalist'    => $is_list,
			'is_color'       => $is_color,
			'is_category'    => $is_category,
			'is_cron'        => $is_cron,
			'is_image'       => $is_image,
			'is_number'      => $is_number,
			'number_min'     => esc_attr( (string) ( $definition['min'] ?? 0 ) ),
			'number_max'     => esc_attr( (string) ( $definition['max'] ?? 100 ) ),
			'number_step'    => esc_attr( (string) ( $definition['step'] ?? 1 ) ),
			'is_readonly'    => $is_readonly,
			'field_disabled' => ! empty( $definition['field_disabled'] ),
			'field_locked'   => ! empty( $definition['field_locked'] ),
			'master_key'     => esc_attr( (string) ( $definition['master'] ?? '' ) ),
			'disabled_by'    => esc_attr( (string) ( $definition['disabled_by'] ?? '' ) ),
			'common_target'  => esc_attr( (string) ( $definition['common_target'] ?? '' ) ),
			'is_headless'    => $is_headless,
			'is_exclusive'   => $is_exclusive,
			'exclusive_group' => esc_attr( $exclusive_group ),
			'rows'           => (string) ( $definition['rows'] ?? 4 ),
			'field_header_buttons' => (array) ( $definition['field_header_buttons'] ?? array() ),
			'field_messages'       => (array) ( $definition['field_messages'] ?? array() ),
		);
		if ( array() !== (array) ( $definition['ec_descriptions'] ?? array() ) ) {
			$field['ec_descriptions'] = array();
			foreach ( (array) $definition['ec_descriptions'] as $ec => $entry ) {
				$text  = is_array( $entry ) ? (string) ( $entry['text'] ?? '' ) : (string) $entry;
				$class = is_array( $entry ) ? (string) ( $entry['class'] ?? '' ) : '';
				$field['ec_descriptions'][] = array(
					'depends' => esc_attr( $option_depends ),
					'ec'      => esc_attr( (string) $ec ),
					'text'    => esc_html( $text ),
					'class'   => esc_attr( $class ),
				);
			}
		}
		if ( $is_image ) {
			$image_id  = absint( $value );
			$image_url = '';
			if ( 0 < $image_id && function_exists( 'wp_get_attachment_image_url' ) ) {
				$image_url = (string) wp_get_attachment_image_url( $image_id, 'medium' );
			}
			$pick_label = trim( (string) ( $definition['pick_label'] ?? '' ) );
			$field['image_id']          = esc_attr( (string) $image_id );
			$field['image_url']         = esc_url( $image_url );
			$field['image_pick_label']  = esc_html(
				'' !== $pick_label ? $pick_label : __( '画像を選択', GACHIREAI_TEXT_DOMAIN )
			);
			$field['image_clear_label'] = esc_html( (string) ( $definition['clear_label'] ?? '' ) );
			$field['image_generate_label']    = esc_html( (string) ( $definition['generate_label'] ?? '' ) );
			$field['image_generate_name']     = esc_attr( (string) ( $definition['generate_name'] ?? '' ) );
			$field['image_generate_action']   = esc_attr( (string) ( $definition['generate_action'] ?? '' ) );
			$field['image_generate_disabled'] = ! empty( $definition['generate_disabled'] );
			$field['image_generate_title']    = esc_attr( (string) ( $definition['generate_title'] ?? '' ) );
			$field['button_icon']            = 'robot';
			return $field;
		}
		if ( $is_cron ) {
			$parts = Cron_Schedule::parts( $value );
			$field['cron_minute_name'] = esc_attr( $name . '[minute]' );
			$field['cron_hour_name']   = esc_attr( $name . '[hour]' );
			$field['cron_minute']      = esc_attr( $parts['minute'] );
			$field['cron_hour']        = esc_attr( $parts['hour'] );
			return $field;
		}
		if ( $is_checkbox ) {
			$field['checkboxes'] = $this->radio_choices(
				$value,
				(array) ( $definition['options'] ?? array() ),
				(array) ( $definition['disabled'] ?? array() )
			);
			return $field;
		}
		if ( $is_checkbox_multi ) {
			$field['checkboxes'] = $this->multi_checkbox_choices(
				$value,
				(array) ( $definition['options'] ?? array() ),
				(array) ( $definition['disabled'] ?? array() )
			);
			return $field;
		}
		if ( $is_list ) {
			$field['datalist_id'] = esc_attr( self::datalist_id( $name ) );
			$field['options']     = $this->datalist_choices( (array) ( $definition['options'] ?? array() ) );
			return $field;
		}
		if ( $is_color ) {
			$options = (array) ( $definition['options'] ?? array() );
			$field['color_options'] = $this->color_options( $options );
			$field['color_value'] = esc_attr( $this->picker_value( $value, $options ) );
			return $field;
		}
		if ( $is_radio ) {
			$field['radios'] = $this->radio_choices(
				$value,
				(array) ( $definition['options'] ?? array() ),
				(array) ( $definition['disabled'] ?? array() ),
				(array) ( $definition['option_ec'] ?? array() ),
				$driver
			);
			return $field;
		}
		if ( ! $is_select ) {
			return $field;
		}
		$has_default = '' !== (string) ( $definition['default'] ?? '' );
		$disabled = array_map( 'strval', (array) ( $definition['disabled'] ?? array() ) );
		if ( '' !== $value && in_array( $value, $disabled, true ) ) {
			$value = $has_default ? (string) $definition['default'] : '';
		}
		$matched = ( '' === $value );
		$options = array();
		if ( ! $has_default ) {
			$options[] = array(
				'opt_value'    => '',
				'opt_label'    => esc_html__( '（未選択）', GACHIREAI_TEXT_DOMAIN ),
				'opt_selected' => $matched,
				'opt_disabled' => false,
			);
		}
		foreach ( (array) ( $definition['options'] ?? array() ) as $option_value => $option_label ) {
			$is_disabled = in_array( (string) $option_value, $disabled, true );
			$selected  = ! $is_disabled && ! $matched && (string) $option_value === $value;
			$matched   = $matched || $selected;
			$options[] = array(
				'opt_value'    => esc_attr( (string) $option_value ),
				'opt_label'    => esc_html( (string) $option_label ),
				'opt_selected' => $selected,
				'opt_disabled' => $is_disabled,
			);
		}
		$field['options'] = $options;
		return $field;
	}
	private function datalist_choices( array $options ): array {
		$choices = array();
		foreach ( $options as $option_value => $option_label ) {
			$value     = is_int( $option_value ) ? (string) $option_label : (string) $option_value;
			$choices[] = array( 'opt_value' => esc_attr( $value ) );
		}
		return $choices;
	}
	private function color_options( array $options ): array {
		$choices = array();
		foreach ( $options as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$choices[] = array(
				'opt_value' => esc_attr( (string) ( $entry['value'] ?? '' ) ),
				'opt_label' => esc_html( (string) ( $entry['label'] ?? '' ) ),
				'opt_hex'   => esc_attr( self::swatch_hex( (string) ( $entry['hex'] ?? '' ) ) ),
			);
		}
		return $choices;
	}
	private static function picker_hex( string $value ): string {
		if ( preg_match( '/^#([0-9a-fA-F]{3})$/', $value, $matches ) ) {
			$c = strtolower( $matches[1] );
			return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
		}
		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
			return strtolower( $value );
		}
		return '';
	}
	private static function swatch_hex( string $value ): string {
		$value = strtolower( trim( $value ) );
		return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value ) ? $value : '';
	}
	private function picker_value( string $value, array $options ): string {
		$hex = self::picker_hex( $value );
		if ( '' !== $hex ) {
			return $hex;
		}
		foreach ( $options as $entry ) {
			if ( is_array( $entry ) && (string) ( $entry['value'] ?? '' ) === $value ) {
				return self::picker_hex( self::swatch_hex( (string) ( $entry['hex'] ?? '' ) ) );
			}
		}
		return '';
	}
	private static function datalist_id( string $name ): string {
		$id = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $name ), '-' );
		return 'gachireai-' . ( '' !== $id ? $id : 'datalist' );
	}
	private function radio_choices( string $value, array $options, array $disabled = array(), array $option_ec = array(), string $driver = '' ): array {
		$disabled = array_map( 'strval', $disabled );
		$choices  = array();
		foreach ( $options as $option_value => $option_label ) {
			$is_disabled = in_array( (string) $option_value, $disabled, true );
			$choices[] = array(
				'opt_value'    => esc_attr( (string) $option_value ),
				'opt_label'    => esc_html( (string) $option_label ),
				'opt_checked'  => ! $is_disabled && (string) $option_value === $value,
				'opt_disabled' => $is_disabled,
				'opt_driver'   => esc_attr( $driver ),
				'opt_ec'       => esc_attr( (string) ( $option_ec[ (string) $option_value ] ?? '' ) ),
			);
		}
		return $choices;
	}
	private function multi_checkbox_choices( string $value, array $options, array $disabled = array() ): array {
		$disabled = array_map( 'strval', $disabled );
		$selected = array();
		foreach ( array_map( 'trim', explode( ',', $value ) ) as $item ) {
			if ( '' !== $item ) {
				$selected[] = $item;
			}
		}
		$choices = array();
		foreach ( $options as $option_value => $option_label ) {
			$is_disabled = in_array( (string) $option_value, $disabled, true );
			$choices[] = array(
				'opt_value'    => esc_attr( (string) $option_value ),
				'opt_label'    => esc_html( (string) $option_label ),
				'opt_checked'  => ! $is_disabled && in_array( (string) $option_value, $selected, true ),
				'opt_disabled' => $is_disabled,
			);
		}
		return $choices;
	}
	private static function choices_value( $value, array $options ): string {
		$submitted = is_array( $value ) ? array_map( 'strval', $value ) : array();
		$selected  = array();
		foreach ( array_keys( $options ) as $allowed ) {
			if ( in_array( (string) $allowed, $submitted, true ) ) {
				$selected[] = (string) $allowed;
			}
		}
		return implode( ',', $selected );
	}
}
