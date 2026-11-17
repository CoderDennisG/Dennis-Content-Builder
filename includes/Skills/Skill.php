<?php
declare(strict_types=1);

namespace Denise\Skills;

/**
 * A skill is one area Denise can answer questions about — usually one
 * plugin. It brings reference notes (Markdown, injected into the system
 * prompt) and optional read-only lookup tools Claude may call.
 *
 * Built-in skills live under includes/Skills/<Name>/. Other plugins can
 * add their own through the `denise_skills` filter (append an instance).
 *
 * Tool names must be unique across all skills. Tools must be read-only:
 * Ask Denise answers questions, it never changes the site.
 */
interface Skill {

	/** Stable slug, e.g. "mea-workshops". */
	public function id(): string;

	/** Human name, e.g. "MEA Workshops". */
	public function name(): string;

	/** One line for the skill index in the system prompt. */
	public function description(): string;

	/**
	 * Lowercase keywords. A question containing any of them loads this
	 * skill's notes; a question matching no skill loads every skill.
	 *
	 * @return string[]
	 */
	public function triggers(): array;

	/** Markdown reference notes injected into the system prompt. */
	public function knowledge(): string;

	/**
	 * Tool definitions in the Anthropic SDK array shape
	 * (name, description, inputSchema). Empty array = no tools.
	 */
	public function tools(): array;

	/** Progress label shown in the chat while a tool runs. */
	public function label( string $tool ): string;

	/** Run a tool. Returns JSON-serialisable data (or ['error' => '…']). */
	public function execute( string $tool, array $input ): array;
}
