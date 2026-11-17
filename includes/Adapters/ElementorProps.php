<?php
declare(strict_types=1);

namespace Denise\Adapters;

/**
 * Turns the plain settings Denise's tools accept ({"title": "New text"})
 * into the typed prop values the Elementor editor stores for atomic (V4)
 * widgets ({"title": {"$$type": "string", "value": "New text"}}).
 *
 * Uses Elementor's own resolver, the same code its manage-elements ability
 * runs before saving, so a value that is valid for the saved page is valid
 * for the live editor too. V3 (legacy) widgets keep plain settings; the
 * editor accepts those as they are.
 */
final class ElementorProps {

	public static function is_available(): bool {
		return class_exists( '\Elementor\Plugin' )
			&& class_exists( '\Elementor\Modules\AtomicWidgets\Module' )
			&& class_exists( '\Elementor\Modules\GlobalClasses\Utils\Atomic_Elements_Utils' );
	}

	/**
	 * The model the editor's create command expects for a new element:
	 * widgets carry elType "widget" + widgetType, containers (e-flexbox,
	 * e-div-block) are elements with elType = their type.
	 */
	public static function model_for( string $element_type, array $settings ): array {
		$is_widget         = class_exists( '\Elementor\Plugin' )
			&& null !== \Elementor\Plugin::instance()->widgets_manager->get_widget_types( $element_type );
		$model             = $is_widget
			? array(
				'elType'     => 'widget',
				'widgetType' => $element_type,
			)
			: array( 'elType' => $element_type );
		$model['settings'] = (object) $settings;
		return $model;
	}

	/**
	 * @param string $element_type Widget type (e-heading) or element type (e-flexbox).
	 * @param array  $plain        Plain settings map; null removes a key.
	 * @return array{settings: array, atomic: bool, warnings: string[], errors: string[]}
	 */
	public static function resolve( string $element_type, array $plain ): array {
		$out = array(
			'settings' => array(),
			'atomic'   => false,
			'warnings' => array(),
			'errors'   => array(),
		);
		if ( ! self::is_available() ) {
			$out['settings'] = $plain;
			return $out;
		}

		$instance = \Elementor\Modules\GlobalClasses\Utils\Atomic_Elements_Utils::get_element_instance( $element_type );
		if ( ! $instance ) {
			$out['errors'][] = "Unknown element type \"{$element_type}\".";
			return $out;
		}
		if ( ! \Elementor\Modules\GlobalClasses\Utils\Atomic_Elements_Utils::is_atomic_element( $instance ) || ! method_exists( $instance, 'get_props_schema' ) ) {
			// Legacy widget: the editor stores plain settings.
			$out['settings'] = $plain;
			return $out;
		}

		$out['atomic'] = true;
		$schema        = $instance::get_props_schema();
		$resolver      = \Elementor\Modules\AtomicWidgets\Module::instance()->get_settings_plain_values_resolver();
		$alias_map     = class_exists( '\Elementor\Modules\Mcp\Abilities\Prop_Canonicalizer' )
			? \Elementor\Modules\Mcp\Abilities\Prop_Canonicalizer::build_alias_map( $schema )
			: array();

		foreach ( $plain as $name => $value ) {
			$name      = (string) $name;
			$canonical = isset( $schema[ $name ] ) ? $name : ( $alias_map[ $name ] ?? null );
			if ( null === $canonical ) {
				$out['warnings'][] = "\"{$name}\" is not a setting of {$element_type} and was skipped.";
				continue;
			}
			if ( null === $value ) {
				$out['settings'][ $canonical ] = null;
				continue;
			}
			$prop_type = $schema[ $canonical ];
			if ( ! $prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Contracts\Prop_Type ) {
				continue;
			}
			// Already typed (the model echoed a value it read)? Keep it.
			if ( is_array( $value ) && isset( $value['$$type'] ) ) {
				$out['settings'][ $canonical ] = $value;
				continue;
			}
			$resolved = $resolver->resolve( $value, $prop_type );
			if ( null === $resolved ) {
				$out['errors'][] = "\"{$canonical}\" on {$element_type} could not take that value.";
				continue;
			}
			$out['settings'][ $canonical ] = $resolved;
		}

		return $out;
	}
}
