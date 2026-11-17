<?php
declare(strict_types=1);

namespace Denise\Skills;

use Denise\Skills\MeaWorkshops\MeaWorkshopsSkill;
use Denise\Skills\WorkshopIntake\WorkshopIntakeSkill;

/**
 * Collects the skills Ask Denise can use and routes tool calls back to
 * the skill that owns them.
 */
final class SkillRegistry {

	/** @var array<string, Skill>|null */
	private static ?array $skills = null;

	/** @return array<string, Skill> keyed by id */
	public static function all(): array {
		if ( null !== self::$skills ) {
			return self::$skills;
		}

		$skills = array( new MeaWorkshopsSkill(), new WorkshopIntakeSkill() );

		/**
		 * Let other plugins add skills. Append a Denise\Skills\Skill instance.
		 *
		 * @param Skill[] $skills
		 */
		$skills = apply_filters( 'denise_skills', $skills );

		self::$skills = array();
		foreach ( (array) $skills as $skill ) {
			if ( $skill instanceof Skill ) {
				self::$skills[ $skill->id() ] = $skill;
			}
		}

		return self::$skills;
	}

	/**
	 * Skills whose triggers appear in any of the given texts (the user's
	 * messages so far). No match = every skill, so a vague question still
	 * gets an answer.
	 *
	 * @param string[] $texts
	 * @param bool     $fallback_all No match: true = every skill (Ask), false = none (builder).
	 * @return array<string, Skill>
	 */
	public static function select( array $texts, bool $fallback_all = true ): array {
		$haystack = strtolower( implode( "\n", $texts ) );
		$matched  = array();

		foreach ( self::all() as $id => $skill ) {
			foreach ( $skill->triggers() as $trigger ) {
				if ( '' !== $trigger && false !== strpos( $haystack, strtolower( $trigger ) ) ) {
					$matched[ $id ] = $skill;
					break;
				}
			}
		}

		return $matched || ! $fallback_all ? $matched : self::all();
	}

	/** The skill that defines a tool, or null. */
	public static function tool_owner( string $tool ): ?Skill {
		foreach ( self::all() as $skill ) {
			foreach ( $skill->tools() as $definition ) {
				if ( ( $definition['name'] ?? '' ) === $tool ) {
					return $skill;
				}
			}
		}
		return null;
	}
}
