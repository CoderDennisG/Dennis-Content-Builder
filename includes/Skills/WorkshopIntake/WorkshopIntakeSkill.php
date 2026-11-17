<?php
declare(strict_types=1);

namespace Denise\Skills\WorkshopIntake;

use Denise\Skills\Skill;
use Denise\Support\Capabilities;

/**
 * Skill: building a workshop from the document the client sends.
 *
 * The notes (knowledge.md next to this file) hold the agreed procedure:
 * which parts of the doc go to which ACF fields, which go into which
 * section of the "WS Guest Faculty Template", what is reported instead of
 * built, and how an agenda becomes the workshop_agenda shortcode. The one
 * tool is a pure text conversion for that agenda; the writes themselves
 * are the ordinary content tools (create_elementor_draft_from_template,
 * update_fields, read/update_elementor_element).
 */
final class WorkshopIntakeSkill implements Skill {

	private const KNOWLEDGE = __DIR__ . '/knowledge.md';

	private ?string $knowledge = null;

	public function id(): string {
		return 'workshop-intake';
	}

	public function name(): string {
		return 'Workshop intake';
	}

	public function description(): string {
		return 'Creating a new workshop from the client\'s document: the doc → ACF field mapping, the doc → template-section mapping for the WS Guest Faculty Template, what is reported instead of built, and the agenda → workshop_agenda shortcode conversion.';
	}

	public function triggers(): array {
		return array(
			'new workshop',
			'create a workshop',
			'create workshop',
			'build a workshop',
			'build the workshop',
			'workshop from',
			'from this doc',
			'docx',
			'word doc',
			'intake',
			'guest faculty template',
			'pillar child',
			'agenda',
			'workshop_agenda',
			'convert',
			'film fest',
		);
	}

	public function knowledge(): string {
		if ( null === $this->knowledge ) {
			$text            = is_readable( self::KNOWLEDGE ) ? file_get_contents( self::KNOWLEDGE ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->knowledge = is_string( $text ) && '' !== trim( $text ) ? trim( $text ) : '_The workshop intake notes were not found._';
		}
		return $this->knowledge;
	}

	public function tools(): array {
		return array(
			array(
				'name'        => 'convert_agenda',
				'description' => 'Turn a plain-text day-by-day agenda (lines like "DAY 2: Friday" then "7:30 AM – Breakfast") into the [mea_workshops action="workshop_agenda"] block the Schedule section needs. Pass the workshop start_date (YYYY-MM-DD) when known so the day-one date in the text can be checked against it. Returns the block plus warnings; it never writes anything.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'text'       => array(
							'type'        => 'string',
							'description' => 'The agenda exactly as it appears in the document.',
						),
						'start_date' => array(
							'type'        => 'string',
							'description' => 'Workshop start date, YYYY-MM-DD, to cross-check the first day.',
						),
					),
					'required'             => array( 'text' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	public function label( string $tool ): string {
		return __( 'Converting the agenda…', 'denise' );
	}

	public function execute( string $tool, array $input ): array {
		if ( ! current_user_can( Capabilities::USE_CHAT ) ) {
			return array( 'error' => 'Not allowed.' );
		}
		if ( 'convert_agenda' !== $tool ) {
			return array( 'error' => 'Unknown tool.' );
		}
		return AgendaConverter::convert( (string) ( $input['text'] ?? '' ), (string) ( $input['start_date'] ?? '' ) );
	}
}
